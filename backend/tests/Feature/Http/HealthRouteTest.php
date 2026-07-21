<?php

use App\Http\Controllers\HealthController;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\UserLog;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

uses()->group('database');

/**
 * 清理 M2 redis 测试用到的队列键（del 走 facade，与 llen/zcount 同 prefix 对称）。
 * 覆盖 config('queue.names') 全部队列名的就绪列表与延时 zset。
 */
function resetRedisQueueKeys(): void
{
    foreach (array_unique(array_values(config('queue.names', []))) as $name) {
        Redis::command('del', ["queues:$name"]);
        Redis::command('del', ["queues:$name:delayed"]);
    }
}

beforeEach(function () {
    UpgradeFreezeLock::unfreeze();
    // 显式清心跳键：保证「缺失」前提确定性。HeartbeatCommandTest 真跑命令写 Cache::forever 键，
    // 同进程串跑时 array cache 跨用例存活会污染此文件的缺失前提 → flaky。
    Cache::forget('schedule:heartbeat');
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze();
    Cache::forget('schedule:heartbeat');
});

/**
 * 用 anonymous subclass 替换 HealthController 的内部探活方法。
 *
 * 路由 dispatch 走 Route::container->make(HealthController::class)，
 * 所以 app()->bind 之后会拿到替代实例。
 *
 * @param  array{
 *   db?: array{ok: bool, latency_ms: int},
 *   queue_lag_seconds?: int,
 *   disk_free_gb?: float,
 *   heartbeat_age_seconds?: int|null
 * }  $overrides
 */
function bindFakeHealthController(array $overrides): void
{
    app()->bind(HealthController::class, function () use ($overrides) {
        return new class($overrides) extends HealthController
        {
            public function __construct(private array $overrides) {}

            protected function dbCheck(): array
            {
                return $this->overrides['db'] ?? parent::dbCheck();
            }

            protected function queueLag(): int
            {
                return $this->overrides['queue_lag_seconds'] ?? parent::queueLag();
            }

            protected function diskFree(): float
            {
                return $this->overrides['disk_free_gb'] ?? parent::diskFree();
            }

            protected function heartbeatAge(): ?int
            {
                // 用 array_key_exists 而非 ??：显式传 null 表示「心跳缺失」是有效覆盖值，
                // ?? 会把 null 误当未设而回落 parent。
                return array_key_exists('heartbeat_age_seconds', $this->overrides)
                    ? $this->overrides['heartbeat_age_seconds']
                    : parent::heartbeatAge();
            }

            protected function cacheCheck(): array
            {
                return $this->overrides['cache'] ?? parent::cacheCheck();
            }
        };
    });
}

// ==========================================
// 1. 健康状态：全部 ok → 200
// ==========================================

test('health 全部检查通过返回 200 / status=ok / freeze=false', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 2],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 0, // 心跳新鲜，保 status=ok（否则缺失 → degraded）
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    $response->assertJson([
        'status' => 'ok',
        'freeze' => false,
        'checks' => [
            'db' => ['ok' => true, 'latency_ms' => 2],
            'queue_lag_seconds' => 0,
            'disk_free_gb' => 50.0,
            'heartbeat_age_seconds' => 0,
        ],
        'check_statuses' => [
            'db' => 'ok',
            'cache' => 'ok',
            'heartbeat' => 'ok',
            'queue' => 'ok',
            'disk' => 'ok',
        ],
        'queue_lag_unit' => 'seconds',
    ]);
});

// ==========================================
// 2. DB 故障 → 503 / status=error
// ==========================================

test('DB ping 失败返回 503 / status=error', function () {
    bindFakeHealthController([
        'db' => ['ok' => false, 'latency_ms' => 0],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    $response->assertJson([
        'status' => 'error',
        'checks' => [
            'db' => ['ok' => false],
        ],
        'check_statuses' => [
            'db' => 'error',
            'cache' => 'ok',
            'heartbeat' => 'degraded',
            'queue' => 'degraded',
            'disk' => 'degraded',
        ],
    ]);
});

// ==========================================
// 3. queue lag 超阈值 (database driver) → 503
// ==========================================

test('queue lag 超阈值返回 503 / status=error', function () {
    // 不替换 queue_lag，让 controller 真实读 jobs 表。
    // 切到 database driver 并插入一条远在过去 available_at 的 Job。
    config(['queue.default' => 'database']);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'test', 'job' => 'test']),
        'attempts' => 0,
        'reserved_at' => null,
        // 阈值默认 600s，这里设 1000s 之前
        'available_at' => time() - 1000,
        'created_at' => time() - 1000,
    ]);

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
        // queue_lag_seconds 不传，用 controller 真实计算
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('status'))->toBe('error');
    expect($response->json('check_statuses.queue'))->toBe('error');
    expect($response->json('checks.queue_lag_seconds'))->toBeGreaterThan(600);
});

// ==========================================
// 4. 磁盘不足 → 503
// ==========================================

test('磁盘不足返回 503 / status=error', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        // 默认阈值 1.0 GB，这里设 0.5 GB（< 1.0）
        'disk_free_gb' => 0.5,
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    $response->assertJson([
        'status' => 'error',
        'checks' => ['disk_free_gb' => 0.5],
    ]);
});

// ==========================================
// 5. freeze 期间仍 200，freeze=true
// ==========================================

test('freeze 期间健康检查仍返回 200 + freeze=true', function () {
    UpgradeFreezeLock::freeze();
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 0, // 心跳新鲜，保 status=ok
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    $response->assertJson([
        'status' => 'ok',
        'freeze' => true,
    ]);
});

// ==========================================
// 6. freeze 期间 queue lag 不参与 503 判定
// ==========================================

test('freeze 期间 queue lag 超阈值仍返回 200', function () {
    UpgradeFreezeLock::freeze();

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        // 远超 600 阈值
        'queue_lag_seconds' => 3600,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 0, // 心跳新鲜，保 status=ok
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('status'))->toBe('ok');
    expect($response->json('freeze'))->toBeTrue();
    expect($response->json('checks.queue_lag_seconds'))->toBe(3600);
});

// ==========================================
// 7. 路由不需要 token / 鉴权
// ==========================================

test('GET /api/health 不带任何鉴权 header 仍可访问 200', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
    ]);

    // 无 Authorization / 无 cookie / 无 session
    $response = $this->get('/api/health');

    $response->assertOk();
});

// ==========================================
// 8. 路由不写日志（不记录到任何 *Log 表）
// ==========================================

test('GET /api/health 不写任何业务日志', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
    ]);

    expect(AdminLog::count())->toBe(0);
    expect(ApiLog::count())->toBe(0);
    expect(UserLog::count())->toBe(0);
    expect(CallbackLog::count())->toBe(0);

    $this->getJson('/api/health')->assertOk();

    expect(AdminLog::count())->toBe(0);
    expect(ApiLog::count())->toBe(0);
    expect(UserLog::count())->toBe(0);
    expect(CallbackLog::count())->toBe(0);
});

// ==========================================
// 9. 真实 DB ping 走通：默认 controller（不 bind）也能正常返回
// ==========================================

test('未替换 controller 时真实 DB ping 走通且返回 ok 字段结构', function () {
    // 不 bind，走真实 dbCheck()。RefreshDatabase 已建好连接，PDO 可获取。
    // disk_free / queue_lag 也走真实分支，确保 controller 整体可执行。
    $response = $this->getJson('/api/health');

    // 真实磁盘多大未知，但结构必须正确
    $response->assertJsonStructure([
        'status',
        'freeze',
        'checks' => [
            'db' => ['ok', 'latency_ms'],
            'queue_lag_seconds',
            'disk_free_gb',
            'heartbeat_age_seconds',
        ],
    ]);

    expect($response->json('checks.db.ok'))->toBeTrue();
    expect($response->json('checks.db.latency_ms'))->toBeInt();
    expect($response->json('checks.queue_lag_seconds'))->toBeInt();
    // disk_free_gb 是 round($bytes / 1024^3, 1) 的 float，但 PHP json_encode 对整数值 float（如 463.0）
    // 输出为整数 "463"（不带小数点），客户端 json_decode 解出 int。
    // 测试用 toBeNumeric 兼容两种类型（spec 不要求严格 float，仅要求数字可比较阈值）
    expect($response->json('checks.disk_free_gb'))->toBeNumeric();
    expect($response->json('freeze'))->toBeFalse();
});

// ==========================================
// 10. queue driver=sync 时 lag 始终 0（不查询任何外部依赖）
// ==========================================

test('queue driver=sync 时 queue_lag_seconds 始终为 0', function () {
    config(['queue.default' => 'sync']);

    // 即便插入 Job 行，sync driver 也不计算 lag
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'test', 'job' => 'test']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 1000,
        'created_at' => time() - 1000,
    ]);

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
        // queue_lag_seconds 不传，走真实 queueLag()
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('checks.queue_lag_seconds'))->toBe(0);
});

// ==========================================
// 11. queue driver=redis 时 queue_lag_seconds 走 redis 路径不抛异常
// ==========================================

test('queue driver=redis 时 queue_lag_seconds 走 redis 路径返回 int（无 redis 时 graceful 0）', function () {
    config(['queue.default' => 'redis']);

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
        // queue_lag_seconds 不传，走真实 queueLag()
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    // redis 不可用时 controller 应 graceful 返回 0（不抛 502/503）
    expect($response->json('checks.queue_lag_seconds'))->toBeInt()
        ->and($response->json('checks.queue_lag_seconds'))->toBeGreaterThanOrEqual(0);
});

test('queue driver=database 但 jobs 表缺失时 queue_lag_seconds 仍返回 int 不抛异常', function () {
    config(['queue.default' => 'database']);

    // 拷贝 jobs 表为空（RefreshDatabase 已建好），controller 应正常返回 0
    DB::table('jobs')->truncate();

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('checks.queue_lag_seconds'))->toBe(0);
});

// ==========================================
// M1. 心跳缺失 → degraded + 200（新装机/清缓存，不 stale 503）
// ==========================================

test('心跳键缺失时 status=degraded 且返回 200（非 stale 503）', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => null, // 显式缺失
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('status'))->toBe('degraded')
        ->and($response->json('checks.heartbeat_age_seconds'))->toBeNull()
        ->and($response->json('check_statuses.heartbeat'))->toBe('degraded');
});

// ==========================================
// M1. 心跳过旧（stale）且未 freeze → error 503
// ==========================================

test('心跳过旧（age>300）且未 freeze 时 status=error 返回 503', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 400, // > 默认 300 阈值
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('status'))->toBe('error');
    expect($response->json('check_statuses.heartbeat'))->toBe('error');
});

// ==========================================
// M1. freeze 期心跳过旧仍 200（豁免 stale 判定，升级窗不误报）
// ==========================================

test('freeze 期心跳过旧仍返回 200（豁免 stale）', function () {
    UpgradeFreezeLock::freeze();
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 3600, // 远超阈值，但 freeze 期不评估
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('status'))->toBe('ok')
        ->and($response->json('freeze'))->toBeTrue()
        ->and($response->json('check_statuses.heartbeat'))->toBe('degraded');
});

// ==========================================
// M1. 判定序固化（Mi5）：心跳缺失 + 磁盘不足 → error 503（不得被 degraded 掩盖）
// ==========================================

test('心跳缺失叠加磁盘不足时 status=error 503（error 分支先于 degraded）', function () {
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 0.5, // < 1.0 阈值 → error
        'heartbeat_age_seconds' => null, // 缺失：若判定序错会被误判 degraded 200
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('status'))->toBe('error');
});

// ==========================================
// M2. redis 驱动：就绪深度计入 queue_lag_seconds（深度求和）
// ==========================================

test('redis 驱动就绪 job 计入 queue_lag_seconds（深度求和）', function () {
    config(['queue.default' => 'redis']);
    resetRedisQueueKeys();

    // tasks 队列 2 条就绪 job（生产 worker 只消费 tasks/notifications）
    Redis::command('rpush', ['queues:tasks', 'job-a', 'job-b']);

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 0,
        // queue_lag_seconds 走真实 queueLagRedis()
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('checks.queue_lag_seconds'))->toBe(2);

    resetRedisQueueKeys();
});

// ==========================================
// M2. redis 延时队列：未到期 score 不计入、已到期计入（防夜间 0~8h 延时批次误报）
// ==========================================

test('redis 延时队列只计已到期 score，未到期批次不计入', function () {
    config(['queue.default' => 'redis']);
    resetRedisQueueKeys();

    $now = time();
    // 已到期（score ≤ now，worker 死才堆积）→ 计入
    Redis::command('zadd', ['queues:tasks:delayed', $now - 60, 'due-job']);
    // 未到期（score 在未来，如 auto-renew 夜间 0~8h 延时 commit 批次）→ 不计入
    Redis::command('zadd', ['queues:tasks:delayed', $now + 3600, 'future-job-1']);
    Redis::command('zadd', ['queues:tasks:delayed', $now + 7200, 'future-job-2']);

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 0,
    ]);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    // 只计已到期 1 条，2 条未到期被 ZCOUNT -inf now 排除
    expect($response->json('checks.queue_lag_seconds'))->toBe(1);

    resetRedisQueueKeys();
});

// ==========================================
// M2. redis 深度超 queue_depth_threshold（默认 500 条）→ error 503
// ==========================================

test('redis 队列深度超 queue_depth_threshold 时 status=error 503', function () {
    config(['queue.default' => 'redis']);
    resetRedisQueueKeys();

    // 阈值默认 500 条；压 501 条就绪 job 触发 503
    $jobs = array_fill(0, 501, 'job');
    Redis::command('rpush', array_merge(['queues:tasks'], $jobs));

    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => 0,
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('status'))->toBe('error')
        ->and($response->json('checks.queue_lag_seconds'))->toBeGreaterThan(500)
        ->and($response->json('check_statuses.queue'))->toBe('error')
        ->and($response->json('queue_lag_unit'))->toBe('jobs');

    resetRedisQueueKeys();
});

// ==========================================
// M4. cache 后端故障 → 结构化 error 503（非白屏 500 / 非 degraded 200 掩盖）
// ==========================================

test('cache 探针故障时 status=error 503（cache error 先于 degraded，不被误判 200）', function () {
    // 心跳缺失（null）：若判定序把 cache error 排在 degraded 之后，会被误判 degraded 200，掩盖 cache 故障。
    bindFakeHealthController([
        'db' => ['ok' => true, 'latency_ms' => 1],
        'cache' => ['ok' => false],
        'queue_lag_seconds' => 0,
        'disk_free_gb' => 50.0,
        'heartbeat_age_seconds' => null,
    ]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('status'))->toBe('error')
        ->and($response->json('checks.cache.ok'))->toBeFalse();
});

test('cache 后端崩溃时 /api/health 返回结构化 503 而非白屏 500', function () {
    // 不 bind controller，走真实探针；swap 一个所有操作抛异常的 cache（模拟 redis 全故障）。
    // heartbeatAge/cacheCheck 的 Cache::get 抛异常必须被降级为结构化输出，而非冒泡成 500。
    Cache::swap(throwingCacheRepository());

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    $response->assertJsonStructure([
        'status',
        'freeze',
        'checks' => [
            'db' => ['ok', 'latency_ms'],
            'cache' => ['ok'],
            'queue_lag_seconds',
            'disk_free_gb',
            'heartbeat_age_seconds',
        ],
    ]);
    expect($response->json('status'))->toBe('error')
        ->and($response->json('checks.cache.ok'))->toBeFalse();

    restoreArrayCache();
});

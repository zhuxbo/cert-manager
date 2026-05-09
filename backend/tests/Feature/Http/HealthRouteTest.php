<?php

use App\Http\Controllers\HealthController;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\UserLog;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Facades\DB;

uses()->group('database');

beforeEach(function () {
    UpgradeFreezeLock::unfreeze();
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze();
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
 *   disk_free_gb?: float
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
        ],
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

<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    // metrics:index 走 30s 短 TTL 缓存，清掉避免跨用例污染
    Cache::flush();
    $this->admin = Admin::factory()->create();
});

test('未认证用户无法访问 metrics 端点', function () {
    $response = $this->getJson('/api/admin/metrics');

    $response->assertUnauthorized();
});

test('返回 6 类指标 + collected_at 字段结构', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure([
        'data' => [
            'orders' => ['count_24h', 'count_7d', 'status_distribution'],
            'queue' => ['jobs', 'failed_jobs', 'lag_seconds'],
            'ca' => ['requests_24h', 'success_rate', 'latency_p50_ms', 'latency_p95_ms'],
            'logs',
            'database' => ['driver', 'total_size_bytes', 'total_size_mb'],
            'collected_at',
        ],
    ]);
});

test('返回订单 24h / 7d 统计与状态分布', function () {
    // 24h 内：2 单 active、1 单 pending
    [$o1, $c1] = createOrderWithLatestCert('active', ['created_at' => now()->subHours(2)]);
    [$o2, $c2] = createOrderWithLatestCert('active', ['created_at' => now()->subHours(10)]);
    [$o3, $c3] = createOrderWithLatestCert('pending', ['created_at' => now()->subHours(20)]);

    // 7d 内但 24h 外：1 单 cancelled
    [$o4, $c4] = createOrderWithLatestCert('cancelled', ['created_at' => now()->subDays(3)]);

    // 7d 外：1 单 active（不计入两个窗口）
    [$o5, $c5] = createOrderWithLatestCert('active', ['created_at' => now()->subDays(10)]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    expect($response->json('data.orders.count_24h'))->toBe(3);
    expect($response->json('data.orders.count_7d'))->toBe(4);

    $dist = $response->json('data.orders.status_distribution');
    expect($dist)->toHaveKey('active')->and($dist)->toHaveKey('pending')->and($dist)->toHaveKey('cancelled');
    expect($dist['active'])->toBe(3);
    expect($dist['pending'])->toBe(1);
    expect($dist['cancelled'])->toBe(1);
});

test('返回队列深度 jobs / failed_jobs / lag_seconds', function () {
    // 插入 2 个 jobs，最早 available_at 比当前提早 100s
    $now = time();
    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 100,
            'created_at' => $now - 100,
        ],
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 30,
            'created_at' => $now - 30,
        ],
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'fail',
        'failed_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    expect($response->json('data.queue.jobs'))->toBe(2);
    expect($response->json('data.queue.failed_jobs'))->toBe(1);
    // lag_seconds 取 min(available_at) → ≥ 99（允许 1s 误差）
    expect($response->json('data.queue.lag_seconds'))->toBeGreaterThanOrEqual(99);
});

test('返回 CA 延迟 P50 / P95（基于 ca_logs.duration 秒列转毫秒）', function () {
    // 插入 10 条 ca_logs，duration 从 0.1s 到 1.0s（递增 0.1s）
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = [
            'url' => 'https://ca.test/api',
            'api' => 'test',
            'params' => null,
            'response' => null,
            'status_code' => 200,
            'status' => $i <= 8 ? 1 : 0, // 8 成功 / 2 失败 = 80%
            'duration' => round($i * 0.1, 1),
            'created_at' => now()->subMinutes(10),
        ];
    }
    DB::table('ca_logs')->insert($rows);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    expect($response->json('data.ca.requests_24h'))->toBe(10);
    expect($response->json('data.ca.success_rate'))->toBeFloat()->toBe(80.0);
    // 排序后 [0.1..1.0]，count=10，p50_idx=floor(10*0.5)=5 → durations[5]=0.6s=600ms
    expect($response->json('data.ca.latency_p50_ms'))->toBe(600);
    // p95_idx=floor(10*0.95)=9 → durations[9]=1.0s=1000ms
    expect($response->json('data.ca.latency_p95_ms'))->toBe(1000);
});

test('CA 无数据时延迟字段返回 0 且 success_rate 为 0', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    expect($response->json('data.ca.requests_24h'))->toBe(0);
    expect($response->json('data.ca.success_rate'))->toBeFloat()->toBe(0.0);
    expect($response->json('data.ca.latency_p50_ms'))->toBe(0);
    expect($response->json('data.ca.latency_p95_ms'))->toBe(0);
});

test('返回 6 张日志表 + tasks / notifications 行数', function () {
    DB::table('admin_logs')->insert([
        'admin_id' => 1,
        'module' => 't',
        'action' => 'a',
        'method' => 'GET',
        'url' => '/x',
        'status_code' => 200,
        'status' => 1,
        'duration' => 0.01,
        'created_at' => now(),
    ]);
    DB::table('user_logs')->insert([
        'user_id' => 1,
        'module' => 't',
        'action' => 'a',
        'method' => 'GET',
        'url' => '/x',
        'status_code' => 200,
        'status' => 1,
        'duration' => 0.01,
        'created_at' => now(),
    ]);
    DB::table('user_logs')->insert([
        'user_id' => 1,
        'module' => 't',
        'action' => 'a',
        'method' => 'GET',
        'url' => '/y',
        'status_code' => 200,
        'status' => 1,
        'duration' => 0.01,
        'created_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    $logs = $response->json('data.logs');

    foreach (['admin_logs', 'user_logs', 'api_logs', 'callback_logs', 'ca_logs', 'error_logs', 'tasks', 'notifications'] as $table) {
        expect($logs)->toHaveKey($table);
        expect($logs[$table])->toHaveKey('row_count');
        expect($logs[$table])->toHaveKey('size_bytes');
    }
    expect($logs['admin_logs']['row_count'])->toBe(1);
    expect($logs['user_logs']['row_count'])->toBe(2);
});

test('返回数据库总大小（mysql information_schema 求和）', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    $db = $response->json('data.database');
    expect($db['driver'])->toBeString();
    expect($db['total_size_bytes'])->toBeGreaterThanOrEqual(0);
    expect($db['total_size_mb'])->toBeFloat()->toBeGreaterThanOrEqual(0);
});

test('状态分布仅统计近 30 天下单的订单', function () {
    // 窗口内：2 单 active
    createOrderWithLatestCert('active', ['created_at' => now()->subDays(5)]);
    createOrderWithLatestCert('active', ['created_at' => now()->subDays(29)]);
    // 窗口外（>30 天）：1 单 active —— 不应计入状态分布
    createOrderWithLatestCert('active', ['created_at' => now()->subDays(40)]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');

    $response->assertOk();
    $dist = $response->json('data.orders.status_distribution');
    // 仅窗口内 2 单计入，40 天前那单被排除
    expect($dist['active'])->toBe(2);
});

test('index 走短 TTL 缓存：首次采集后再次请求返回缓存快照', function () {
    // 第一次请求：此时 0 单
    $first = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');
    $first->assertOk();
    expect($first->json('data.orders.count_24h'))->toBe(0);
    $firstCollectedAt = $first->json('data.collected_at');

    // 缓存写入后再造数据
    createOrderWithLatestCert('active', ['created_at' => now()->subHours(1)]);

    // 第二次请求：应命中缓存（TTL 30s 内），仍返回 0 与同一 collected_at
    $second = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');
    $second->assertOk();
    expect($second->json('data.orders.count_24h'))->toBe(0);
    expect($second->json('data.collected_at'))->toBe($firstCollectedAt);

    // 清缓存后重新采集：能看到新数据，证明此前确为缓存命中而非查询本身漏算
    Cache::forget('metrics:index');
    $third = $this->actingAsAdmin($this->admin)->getJson('/api/admin/metrics');
    $third->assertOk();
    expect($third->json('data.orders.count_24h'))->toBe(1);
});

/**
 * 创建带 latest_cert_id 的订单（用于 metrics.orders.status_distribution 统计）。
 *
 * @return array{0: Order, 1: Cert}
 */
function createOrderWithLatestCert(string $certStatus, array $orderOverrides = []): array
{
    $order = Order::factory()->create($orderOverrides);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => $certStatus,
    ]);
    $order->forceFill(['latest_cert_id' => $cert->id])->save();

    // 强制 created_at 应用 override（factory 可能被 timestamps 覆盖）
    if (isset($orderOverrides['created_at'])) {
        $order->forceFill(['created_at' => $orderOverrides['created_at']])->save();
    }

    return [$order->fresh(), $cert->fresh()];
}

<?php

use App\Models\Admin;
use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\AutoDeployReportService;
use Illuminate\Support\Facades\Cache;

// TestCase + RefreshDatabase 由 Pest.php 对 Feature 目录自动应用

afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    // resolveAlertTarget 回落 Admin::first()，建一个 admin 即可解析告警目标
    Admin::factory()->create();
    // 捕获替身 NotificationCenter：避免真实 NotificationJob 发信，仅验证去重/置键行为
    $center = Mockery::mock(NotificationCenter::class);
    $center->shouldReceive('dispatch')->andReturnNull();
    app()->instance(NotificationCenter::class, $center);
});

function makeReportOrder(string $certStatus = 'active', array $certOverrides = []): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $factory = $certStatus === 'active' ? Cert::factory()->active() : Cert::factory()->state(['status' => $certStatus]);
    $cert = $factory->create(array_merge(['order_id' => $order->id], $certOverrides));

    $order->update(['latest_cert_id' => $cert->id]);
    $order->setRelation('latestCert', $cert);

    return [$order, $cert, $user];
}

test('notifyFailure per-order 固定指纹去重：TTL 内同一订单只发一封', function () {
    [$order] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    expect($svc->notifyFailure($order))->toBeTrue()
        ->and($svc->notifyFailure($order))->toBeFalse()
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBe('deploy_failure');
});

test('notifyFailure TTL（168h）到期后再提醒一封', function () {
    [$order] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    expect($svc->notifyFailure($order))->toBeTrue()
        ->and($svc->notifyFailure($order))->toBeFalse();

    // 默认 TTL 168h，travel 超过后去重键过期 → 再发一封
    $this->travel(169)->hours();
    expect($svc->notifyFailure($order))->toBeTrue();
});

test('clearFailureAlert 清键后复发立即再发（成功回调即恢复）', function () {
    [$order] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    expect($svc->notifyFailure($order))->toBeTrue()
        ->and($svc->notifyFailure($order))->toBeFalse();

    $svc->clearFailureAlert($order);

    expect($svc->notifyFailure($order))->toBeTrue();
});

test('不同订单独立去重，互不压制', function () {
    [$order1] = makeReportOrder();
    [$order2] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    expect($svc->notifyFailure($order1))->toBeTrue()
        ->and($svc->notifyFailure($order2))->toBeTrue();
});

test('recordServerFailure 写 ip 留空的签发失败记录并触发去重告警', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    $svc->recordServerFailure($order, '自动重签失败：测试原因');

    $report = AutoDeployReport::where('order_id', $order->id)->sole();
    expect($report->status)->toBe('failure')
        ->and($report->cert_id)->toBe($cert->id)
        ->and($report->ip)->toBeNull()
        ->and($report->deployed_at)->toBeNull()
        ->and($report->message)->toBe('自动重签失败：测试原因')
        // 写行同时触发告警（去重键已置）
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBe('deploy_failure');
});

test('recordServerFailure 无 latestCert 时静默跳过、不留痕', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $svc = app(AutoDeployReportService::class);

    $svc->recordServerFailure($order, '本地签发失败：x');

    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

test('recordServerRecovery 失败在案 → 写 ip 留空恢复行并清去重键', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    // 失败在案：failure 行 + 去重键已置
    $svc->recordServerFailure($order, '自动重签失败：测试原因');
    expect(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBe('deploy_failure');

    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    $recovery = AutoDeployReport::where('order_id', $order->id)->orderByDesc('id')->first();
    expect($recovery->status)->toBe('success')
        ->and($recovery->cert_id)->toBe($cert->id) // 回读 latest_cert_id（本用例未切新证书）
        ->and($recovery->ip)->toBeNull()
        ->and($recovery->deployed_at)->toBeNull()
        ->and($recovery->message)->toBe('自动重签成功：前次失败已恢复')
        // 去重键已清：复发时立即再告警
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBeNull()
        ->and(AutoDeployReport::where('order_id', $order->id)->count())->toBe(2);
});

test('recordServerRecovery 恢复行 cert_id 回读切换后的 latest_cert_id（不用调用方陈旧关系）', function () {
    [$order, $oldCert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);
    $svc->recordServerFailure($order, '自动重签失败：x');

    // 模拟重签成功切新证书：DB 已切、调用方内存 $order 仍持旧 latestCert 关系
    $newCert = Cert::factory()->state(['status' => 'processing'])->create(['order_id' => $order->id]);
    Order::query()->whereKey($order->id)->update(['latest_cert_id' => $newCert->id]);

    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    $recovery = AutoDeployReport::where('order_id', $order->id)->orderByDesc('id')->first();
    expect($recovery->status)->toBe('success')
        ->and($recovery->cert_id)->toBe($newCert->id)
        ->and($recovery->cert_id)->not->toBe($oldCert->id);
});

test('recordServerRecovery 来源门：最后一条为客户端部署失败（ip 非空）→ 不写恢复行不清键', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    // 客户端部署失败在案（重签成功 ≠ 部署恢复，只有客户端 success 回调能解除）
    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'failure',
        'ip' => '203.0.113.9',
        'message' => '部署失败：nginx reload 失败（已达重试上限）',
    ]);
    Cache::put("system_alert:deploy_failure_{$order->id}", 'deploy_failure', 3600);

    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    // 不写恢复行、不清键：reminder 继续按 TTL 提醒（CAPPED 静默客户端唯一兜底）
    expect(AutoDeployReport::where('order_id', $order->id)->count())->toBe(1)
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBe('deploy_failure');
});

test('recordServerRecovery 来源门：客户端部署失败后又叠服务端签发失败 → 仍不恢复', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    // 交错形态：客户端部署失败（未解除）→ 服务端签发失败（最后一条 ip 留空）→ 重签成功
    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'failure',
        'ip' => '203.0.113.9',
        'message' => '部署失败：nginx reload 失败',
    ]);
    $svc->recordServerFailure($order, '自动重签失败：系统处理异常');

    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    // 最近一条客户端行仍为 failure → 部署问题未解除，不写恢复行、不清键
    expect(AutoDeployReport::where('order_id', $order->id)->count())->toBe(2)
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBe('deploy_failure');
});

test('recordServerRecovery 来源门：客户端失败已被客户端 success 解除、其后服务端失败 → 正常恢复', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    // 客户端失败 → 客户端 success（部署侧已解除）→ 服务端签发失败 → 重签成功
    AutoDeployReport::create([
        'order_id' => $order->id, 'cert_id' => $cert->id,
        'status' => 'failure', 'ip' => '203.0.113.9', 'message' => '部署失败：x',
    ]);
    AutoDeployReport::create([
        'order_id' => $order->id, 'cert_id' => $cert->id,
        'status' => 'success', 'ip' => '203.0.113.9', 'deployed_at' => now(),
    ]);
    $svc->recordServerFailure($order, '自动重签失败：系统处理异常');

    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    $recovery = AutoDeployReport::where('order_id', $order->id)->orderByDesc('id')->first();
    expect($recovery->status)->toBe('success')
        ->and($recovery->ip)->toBeNull()
        ->and(AutoDeployReport::where('order_id', $order->id)->count())->toBe(4)
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBeNull();
});

test('recordServerRecovery 无失败在案（无报告或最后一条为 success）→ 不写恢复行', function () {
    [$order] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    // 无任何报告 → 不写
    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');
    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();

    // 最后一条已是 success（客户端已回调恢复）→ 不再叠恢复行
    $svc->recordServerFailure($order, '自动重签失败：x');
    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $order->latestCert->id,
        'status' => 'success',
    ]);
    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    expect(AutoDeployReport::where('order_id', $order->id)->count())->toBe(2);
});

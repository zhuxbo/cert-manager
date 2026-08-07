<?php

use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\AutoDeployReportService;

// TestCase + RefreshDatabase 由 Pest.php 对 Feature 目录自动应用

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

test('recordServerFailure 写 ip 留空的签发失败记录', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    $svc->recordServerFailure($order, '自动重签失败：测试原因');

    $report = AutoDeployReport::where('order_id', $order->id)->sole();
    expect($report->status)->toBe('failure')
        ->and($report->cert_id)->toBe($cert->id)
        ->and($report->ip)->toBeNull()
        ->and($report->deployed_at)->toBeNull()
        ->and($report->message)->toBe('自动重签失败：测试原因');
});

test('recordServerFailure 无 latestCert 时静默跳过、不留痕', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $svc = app(AutoDeployReportService::class);

    $svc->recordServerFailure($order, '本地签发失败：x');

    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

test('recordServerRecovery 失败在案时写 ip 留空恢复行', function () {
    [$order, $cert] = makeReportOrder();
    $svc = app(AutoDeployReportService::class);

    $svc->recordServerFailure($order, '自动重签失败：测试原因');
    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    $recovery = AutoDeployReport::where('order_id', $order->id)->orderByDesc('id')->first();
    expect($recovery->status)->toBe('success')
        ->and($recovery->cert_id)->toBe($cert->id) // 回读 latest_cert_id（本用例未切新证书）
        ->and($recovery->ip)->toBeNull()
        ->and($recovery->deployed_at)->toBeNull()
        ->and($recovery->message)->toBe('自动重签成功：前次失败已恢复')
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

test('recordServerRecovery 来源门：最后一条为客户端部署失败时不写恢复行', function () {
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
    $svc->recordServerRecovery($order, '自动重签成功：前次失败已恢复');

    expect(AutoDeployReport::where('order_id', $order->id)->count())->toBe(1);
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

    // 最近一条客户端行仍为 failure → 部署问题未解除，不写恢复行
    expect(AutoDeployReport::where('order_id', $order->id)->count())->toBe(2);
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
        ->and(AutoDeployReport::where('order_id', $order->id)->count())->toBe(4);
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

<?php

use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\AutoDeployReportService;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

// TestCase + RefreshDatabase 由 Pest.php 对 Feature/Commands 自动应用

afterEach(function () {
    Mockery::close();
});

/**
 * 造一个订单 + 证书 + 若干上报（按传入顺序创建，最后一个即最新一行）。
 */
function makeReminderOrder(string $certStatus, Carbon $expiresAt, array $reportStatuses): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $factory = $certStatus === 'active' ? Cert::factory()->active() : Cert::factory()->state(['status' => $certStatus]);
    $cert = $factory->create(['order_id' => $order->id, 'expires_at' => $expiresAt]);
    $order->update(['latest_cert_id' => $cert->id]);

    foreach ($reportStatuses as $status) {
        AutoDeployReport::create([
            'order_id' => $order->id,
            'cert_id' => $cert->id,
            'status' => $status,
        ]);
    }

    return $order;
}

function mockReportService(): MockInterface
{
    $svc = Mockery::mock(AutoDeployReportService::class);
    app()->instance(AutoDeployReportService::class, $svc);

    return $svc;
}

test('签名为 schedule:deploy-failure-reminder', function () {
    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();
});

test('最后一条为 failure 且证书 active 未过期 → 提醒该订单', function () {
    $order = makeReminderOrder('active', now()->addDays(10), ['success', 'failure']); // 最新=failure

    $svc = mockReportService();
    $svc->shouldReceive('notifyFailure')->once()
        ->with(Mockery::on(fn ($o) => $o->id === $order->id))
        ->andReturnTrue();

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();
});

test('最后一条为 success（已恢复）→ 不提醒', function () {
    makeReminderOrder('active', now()->addDays(10), ['failure', 'success']); // 最新=success

    $svc = mockReportService();
    $svc->shouldNotReceive('notifyFailure');

    $this->artisan('schedule:deploy-failure-reminder')
        ->expectsOutputToContain('无未解决')
        ->assertSuccessful();
});

test('订单终态（证书 renewed）→ 停止提醒', function () {
    makeReminderOrder('renewed', now()->addDays(10), ['failure']); // 最新=failure 但订单终态

    $svc = mockReportService();
    $svc->shouldNotReceive('notifyFailure');

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();
});

test('证书已过期（expires_at < now，status 仍 active）→ 停止提醒', function () {
    makeReminderOrder('active', now()->subDay(), ['failure']); // active 但已过期

    $svc = mockReportService();
    $svc->shouldNotReceive('notifyFailure');

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();
});

test('多订单混合：仅未解决且活跃的订单被提醒', function () {
    $active = makeReminderOrder('active', now()->addDays(10), ['failure']);       // 应提醒
    makeReminderOrder('active', now()->addDays(10), ['failure', 'success']);      // 已恢复，跳过
    makeReminderOrder('expired', now()->subDay(), ['failure']);                    // 终态，跳过

    $svc = mockReportService();
    $svc->shouldReceive('notifyFailure')->once()
        ->with(Mockery::on(fn ($o) => $o->id === $active->id))
        ->andReturnTrue();

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();
});

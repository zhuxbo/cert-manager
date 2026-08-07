<?php

use App\Models\Admin;
use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// TestCase + RefreshDatabase 由 Pest.php 对 Feature/Commands 自动应用

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

beforeEach(function () {
    Carbon::setTestNow('2026-08-06 11:00:00');
    Admin::factory()->create(['email' => 'ops@example.com']);
    Cache::flush();
});

function makeDeploySummaryOrder(): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id]);
    $order->update(['latest_cert_id' => $cert->id]);

    return [$order, $cert];
}

function createDeploySummaryReport(Order $order, Cert $cert, string $at, ?string $ip): void
{
    Carbon::setTestNow($at);
    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'failure',
        'ip' => $ip,
        'message' => '部署失败',
    ]);
    Carbon::setTestNow('2026-08-06 11:00:00');
}

function captureDeploySummaryNotifications(): object
{
    $state = new class
    {
        public array $intents = [];
    };
    $center = Mockery::mock(NotificationCenter::class);
    $center->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        $state->intents[] = $intent;
    });
    app()->instance(NotificationCenter::class, $center);

    return $state;
}

test('上一完整小时的跨订单失败合并为一封管理员告警', function () {
    [$order1, $cert1] = makeDeploySummaryOrder();
    [$order2, $cert2] = makeDeploySummaryOrder();
    createDeploySummaryReport($order1, $cert1, '2026-08-06 10:05:00', '203.0.113.1');
    createDeploySummaryReport($order1, $cert1, '2026-08-06 10:20:00', '203.0.113.1');
    createDeploySummaryReport($order2, $cert2, '2026-08-06 10:40:00', null);
    $state = captureDeploySummaryNotifications();

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();

    expect($state->intents)->toHaveCount(1);
    $context = $state->intents[0]->context;
    expect($context['category'])->toBe('deploy_failure')
        ->and($context['details']['report_count'])->toBe(3)
        ->and($context['details']['order_count'])->toBe(2)
        ->and($context['details']['client_failure_count'])->toBe(2)
        ->and($context['details']['server_failure_count'])->toBe(1)
        ->and($context['details']['order_sample'])->toContain((string) $order1->id)
        ->and($context['details']['order_sample'])->toContain((string) $order2->id);
});

test('聚合窗口排除更早历史和当前小时的失败', function () {
    [$oldOrder, $oldCert] = makeDeploySummaryOrder();
    [$windowOrder, $windowCert] = makeDeploySummaryOrder();
    [$currentOrder, $currentCert] = makeDeploySummaryOrder();
    createDeploySummaryReport($oldOrder, $oldCert, '2026-08-06 09:59:59', '203.0.113.1');
    createDeploySummaryReport($windowOrder, $windowCert, '2026-08-06 10:30:00', '203.0.113.2');
    createDeploySummaryReport($currentOrder, $currentCert, '2026-08-06 11:00:00', '203.0.113.3');
    $state = captureDeploySummaryNotifications();

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();

    expect($state->intents)->toHaveCount(1)
        ->and($state->intents[0]->context['details']['report_count'])->toBe(1)
        ->and($state->intents[0]->context['details']['order_sample'])->toBe((string) $windowOrder->id);
});

test('同一小时重复执行不会重复发送聚合告警', function () {
    [$order, $cert] = makeDeploySummaryOrder();
    createDeploySummaryReport($order, $cert, '2026-08-06 10:30:00', '203.0.113.1');
    $state = captureDeploySummaryNotifications();

    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();
    $this->artisan('schedule:deploy-failure-reminder')->assertSuccessful();

    expect($state->intents)->toHaveCount(1);
});

test('上一完整小时没有失败时不发送告警', function () {
    [$order, $cert] = makeDeploySummaryOrder();
    createDeploySummaryReport($order, $cert, '2026-08-06 09:59:59', '203.0.113.1');
    $state = captureDeploySummaryNotifications();

    $this->artisan('schedule:deploy-failure-reminder')
        ->expectsOutputToContain('上一小时无失败')
        ->assertSuccessful();

    expect($state->intents)->toBeEmpty();
});

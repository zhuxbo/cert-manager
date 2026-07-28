<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Action;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

beforeEach(function () {
    Cache::flush();
});

test('获取仪表盘总览', function () {
    $user = User::factory()->withBalance('500.00')->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/overview')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['user_info', 'assets', 'orders']]);
});

test('获取资产统计', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/assets')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['balance']]);
});

test('获取订单统计', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    // 创建一些订单
    for ($i = 0; $i < 3; $i++) {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $cert = Cert::factory()->active()->create([
            'order_id' => $order->id,
        ]);
        $order->update(['latest_cert_id' => $cert->id]);
    }

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['total_orders', 'active_orders']]);
});

test('订单统计返回处理中订单数和品牌分布', function () {
    $user = User::factory()->create();
    $statuses = ['unpaid', 'pending', 'processing', 'approving', 'active'];
    $brands = ['digicert', 'digicert', 'sectigo', 'sectigo', 'sectigo'];

    foreach ($statuses as $index => $status) {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => $brands[$index],
        ]);
        $cert = Cert::factory()->create([
            'order_id' => $order->id,
            'status' => $status,
        ]);
        $order->update(['latest_cert_id' => $cert->id]);
    }

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJsonPath('data.processing_orders', 4)
        ->assertJsonPath('data.order_count', 5)
        ->assertJsonPath('data.active_orders', 1)
        ->assertJsonPath('data.brand_distribution.digicert', 2)
        ->assertJsonPath('data.brand_distribution.sectigo', 3);
});

test('新增待支付订单后立即刷新订单统计缓存', function () {
    $user = User::factory()->create();

    $createUnpaidOrder = function () use ($user): Cert {
        $order = Order::factory()->create(['user_id' => $user->id]);
        $cert = Cert::factory()->create([
            'order_id' => $order->id,
            'status' => 'unpaid',
        ]);
        $order->update(['latest_cert_id' => $cert->id]);

        return $cert;
    };

    $firstCert = $createUnpaidOrder();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJsonPath('data.processing_orders', 1)
        ->assertJsonPath('data.status_distribution.unpaid', 1);

    $cacheKey = "dashboard:user:{$user->id}:orders";
    expect(Cache::has($cacheKey))->toBeTrue();

    $firstCert->update([
        'domain_verify_status' => $firstCert->domain_verify_status === 1 ? 0 : 1,
    ]);
    expect(Cache::has($cacheKey))->toBeTrue();

    $createUnpaidOrder();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJsonPath('data.processing_orders', 2)
        ->assertJsonPath('data.status_distribution.unpaid', 2);
});

test('删除待支付订单后立即刷新订单统计缓存', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'unpaid',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJsonPath('data.processing_orders', 1);

    $cacheKey = "dashboard:user:{$user->id}:orders";
    expect(Cache::has($cacheKey))->toBeTrue();

    (new Action)->delete($order->id);

    expect(Cache::has($cacheKey))->toBeFalse();
    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJsonPath('data.processing_orders', 0);
});

test('到期统计按自然日区间计算', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    try {
        $user = User::factory()->create();
        $expiresAtByStatus = [
            ['active', '2026-07-26 08:00:00'],
            ['active', '2026-08-01 23:59:59'],
            ['active', '2026-08-02 00:00:00'],
            ['active', '2026-08-24 23:59:59'],
            ['active', '2026-08-25 00:00:00'],
            ['processing', '2026-07-27 00:00:00'],
        ];

        foreach ($expiresAtByStatus as [$status, $expiresAt]) {
            $order = Order::factory()->create(['user_id' => $user->id]);
            $cert = Cert::factory()->create([
                'order_id' => $order->id,
                'status' => $status,
                'expires_at' => $expiresAt,
            ]);
            $order->update(['latest_cert_id' => $cert->id]);
        }

        $this->actingAsUser($user)
            ->getJson('/api/dashboard/orders')
            ->assertOk()
            ->assertJsonPath('data.expiring_7_days', 2)
            ->assertJsonPath('data.expiring_30_days', 4);
    } finally {
        Carbon::setTestNow();
    }
});

function seedUserDashboardTransaction(User $user, string $type, float $amount, int $transactionId): void
{
    DB::transaction(fn () => Transaction::create([
        'user_id' => $user->id,
        'type' => $type,
        'transaction_id' => $transactionId,
        'amount' => $amount,
    ]));
}

test('用户首页订单取消净增按本人交易流水及交易时间统计', function () {
    $user = User::factory()->withBalance('1000')->create();
    $other = User::factory()->withBalance('1000')->create();
    $product = Product::factory()->create();
    $oldOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'created_at' => now()->subDays(10),
    ]);

    seedUserDashboardTransaction($user, 'order', -100, $oldOrder->id);
    seedUserDashboardTransaction($user, 'order', -20, $oldOrder->id);
    seedUserDashboardTransaction($user, 'acme_order', -30, 91001);
    seedUserDashboardTransaction($user, 'cancel', 50, $oldOrder->id);
    seedUserDashboardTransaction($user, 'acme_cancel', 10, 91001);
    seedUserDashboardTransaction($user, 'deduct', -5, 91002);
    seedUserDashboardTransaction($other, 'order', -10, 92001);

    $orders = $this->actingAsUser($user)->getJson('/api/dashboard/orders');
    $orders->assertOk()
        ->assertJsonPath('data.total_orders', 3)
        ->assertJsonPath('data.cancelled_orders', 2)
        ->assertJsonPath('data.net_orders', 1)
        ->assertJsonPath('data.monthly_orders', 3)
        ->assertJsonPath('data.monthly_cancelled_orders', 2)
        ->assertJsonPath('data.monthly_net_orders', 1);

    $trend = $this->actingAsUser($user)->getJson('/api/dashboard/trend?period=month');
    $today = collect($trend->json('data'))->firstWhere('date', now()->format('Y-m-d'));
    expect($today)->toMatchArray([
        'orders' => 3,
        'cancelled_orders' => 2,
        'net_orders' => 1,
        'consumption' => 95,
    ]);

    $comparison = $this->actingAsUser($user)->getJson('/api/dashboard/monthly-comparison');
    $comparison->assertJsonPath('data.current_month.orders', 3)
        ->assertJsonPath('data.current_month.cancelled_orders', 2)
        ->assertJsonPath('data.current_month.net_orders', 1);
});

test('趋势数据支持月季年范围', function () {
    $user = User::factory()->create();

    foreach (['month' => 30, 'quarter' => 13, 'year' => 12] as $period => $points) {
        $this->actingAsUser($user)
            ->getJson("/api/dashboard/trend?period=$period")
            ->assertOk()
            ->assertJson(['code' => 1])
            ->assertJsonCount($points, 'data');
    }
});

test('趋势数据未知范围回退到月', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/trend?period=unknown')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonCount(30, 'data');
});

test('获取月度统计对比', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/monthly-comparison')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['current_month', 'last_month', 'growth']]);
});

test('仪表盘-未认证', function () {
    $this->getJson('/api/dashboard/overview')
        ->assertUnauthorized();
});

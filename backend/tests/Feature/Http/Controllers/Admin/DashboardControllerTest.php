<?php

use App\Models\Admin;
use App\Models\Fund;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取首页统计总览', function () {
    User::factory()->count(3)->create();
    $product = Product::factory()->create();
    Order::factory()->count(2)->create(['product_id' => $product->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/overview');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['total_users', 'total_orders', 'total_revenue', 'active_orders']]);
});

test('管理员可以获取系统概览统计', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/system-overview');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['monthly', 'daily', 'finance', 'new_users', 'new_orders']]);
});

test('管理员可以获取实时统计数据', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/realtime');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['online_users', 'today', 'alerts']]);
});

test('管理员可以获取趋势数据', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/trends?days=30');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以指定趋势天数', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/trends?days=7');

    $response->assertOk()->assertJson(['code' => 1]);
    expect(count($response->json('data')))->toBe(7);
});

test('管理员可以获取产品销售排行', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/top-products?days=30&limit=10');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取CA品牌统计', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/brand-stats?days=30');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取用户等级分布', function () {
    User::factory()->count(3)->create(['level_code' => 'standard']);
    User::factory()->count(2)->create(['level_code' => 'gold']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/user-level-distribution');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取财务概览', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/finance-overview');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['total_balance', 'positive_count', 'total_debt', 'negative_count']]);
});

test('管理员可以清除仪表盘缓存', function () {
    // 先写入一些缓存
    Cache::put('dashboard:admin:overview', ['test' => 1], 3600);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/dashboard/clear-cache');

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Cache::has('dashboard:admin:overview'))->toBeFalse();
});

test('未认证用户无法访问仪表盘', function () {
    $response = $this->getJson('/api/admin/dashboard/overview');

    $response->assertUnauthorized();
});

/**
 * 创建订单类 transaction（不在 L3 资金类配对约束内，可直接 INSERT）
 */
function seedOrderTransaction(User $user, string $type, float $amount, int $transactionId, $createdAt = null): Transaction
{
    $transaction = DB::transaction(function () use ($user, $type, $amount, $transactionId) {
        return Transaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'transaction_id' => $transactionId,
            'amount' => $amount,
        ]);
    });

    if ($createdAt !== null) {
        DB::table('transactions')->where('id', $transaction->id)->update(['created_at' => $createdAt]);
        $transaction->created_at = $createdAt;
    }

    return $transaction;
}

test('订单数量按购买和取消交易流水及交易时间统计', function () {
    Cache::flush();

    $user = User::factory()->withBalance('1000')->create();
    $product = Product::factory()->create();
    $oldOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'created_at' => now()->subDays(10),
    ]);

    seedOrderTransaction($user, 'order', -100, $oldOrder->id);
    seedOrderTransaction($user, 'order', -20, $oldOrder->id);
    seedOrderTransaction($user, 'acme_order', -30, 90001);
    seedOrderTransaction($user, 'cancel', 50, $oldOrder->id);
    seedOrderTransaction($user, 'acme_cancel', 10, 90001);
    seedOrderTransaction($user, 'reverse', 5, 90002);

    $overview = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/overview');
    $overview->assertOk()->assertJsonPath('data.total_orders', 3)
        ->assertJsonPath('data.cancelled_orders', 2)
        ->assertJsonPath('data.net_orders', 1);

    $system = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/system-overview');
    foreach (['daily', 'weekly', 'monthly'] as $period) {
        $system->assertJsonPath("data.order_stats.$period.orders", 3)
            ->assertJsonPath("data.order_stats.$period.cancelled_orders", 2)
            ->assertJsonPath("data.order_stats.$period.net_orders", 1);
    }

    $realtime = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/realtime');
    $realtime->assertJsonPath('data.today.new_orders', 3)
        ->assertJsonPath('data.today.cancelled_orders', 2)
        ->assertJsonPath('data.today.net_orders', 1);

    $trends = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/trends?days=7');
    $today = collect($trends->json('data'))->firstWhere('date', now()->format('Y-m-d'));
    expect($today)->toMatchArray([
        'orders' => 3,
        'cancelled_orders' => 2,
        'net_orders' => 1,
    ]);
});

test('财务口径：净充值反映 refunds 抵扣、净消费含 ACME 且可为负', function () {
    Cache::forget('dashboard:admin:system_overview');

    $user = User::factory()->withBalance('0')->create();

    // 充值 1000：Fund status=1 触发 +1000 addfunds transaction
    Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'amount' => '1000.00',
        'type' => 'addfunds',
    ]);

    // 充值 200 后被撤销：先 +200 addfunds，再 update→refunds 写 -200 refunds
    $refunded = Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'amount' => '200.00',
        'type' => 'addfunds',
    ]);
    $refunded->type = 'refunds';
    $refunded->status = 2;
    $refunded->save();

    // 订单类 transaction（L3 不约束）
    seedOrderTransaction($user, 'order', -100, 1001);
    seedOrderTransaction($user, 'cancel', 50, 1002);
    seedOrderTransaction($user, 'acme_order', -30, 1003);
    seedOrderTransaction($user, 'acme_cancel', 10, 1004);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/system-overview');

    $daily = $response->json('data.finance.daily');
    // 充值桶：addfunds(1000+200) + refunds(-200) = 1000
    expect((float) $daily['recharge'])->toBe(1000.0);
    // 消费桶：-(order(-100) + cancel(+50) + acme_order(-30) + acme_cancel(+10)) = 70
    expect((float) $daily['consumption'])->toBe(70.0);
});

test('财务口径：净退费日消费为负数', function () {
    Cache::forget('dashboard:admin:system_overview');

    $user = User::factory()->withBalance('1000')->create();
    // 仅 cancel +500（订单全额退费），无任何消费
    seedOrderTransaction($user, 'cancel', 500, 2001);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/system-overview');

    $daily = $response->json('data.finance.daily');
    expect((float) $daily['consumption'])->toBe(-500.0);
});

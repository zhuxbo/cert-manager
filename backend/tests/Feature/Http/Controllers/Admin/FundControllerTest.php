<?php

use App\Models\Admin;
use App\Models\Fund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取资金记录列表', function () {
    Fund::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/fund');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
    expect($response->json('data.total'))->toBe(3);
    expect($response->json('data.items'))->toHaveCount(3);
});

test('管理员可以快速搜索资金记录', function () {
    $matchedFund = Fund::factory()->create(['user_id' => $this->user->id, 'remark' => 'special payment']);
    Fund::factory()->create(['user_id' => $this->user->id, 'remark' => 'normal']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/fund?quickSearch=special');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.id'))->toBe($matchedFund->id);
});

test('管理员可以按类型筛选资金记录', function () {
    Fund::factory()->create(['user_id' => $this->user->id, 'type' => 'addfunds']);
    $deductFund = Fund::factory()->deduct()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/fund?type=deduct');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.id'))->toBe($deductFund->id);
    expect($response->json('data.items.0.type'))->toBe('deduct');
});

test('管理员可以按状态筛选资金记录', function () {
    Fund::factory()->create(['user_id' => $this->user->id, 'status' => 0]);
    $completedFund = Fund::factory()->completed()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/fund?status=1');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.id'))->toBe($completedFund->id);
    expect($response->json('data.items.0.status'))->toBe(1);
});

test('管理员可以按支付方式筛选资金记录', function () {
    Fund::factory()->create(['user_id' => $this->user->id, 'pay_method' => 'alipay']);
    $wechatFund = Fund::factory()->wechat()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/fund?pay_method=wechat');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.id'))->toBe($wechatFund->id);
    expect($response->json('data.items.0.pay_method'))->toBe('wechat');
});

test('管理员可以查看资金记录详情', function () {
    $fund = Fund::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/fund/$fund->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $fund->id);
});

test('查看不存在的资金记录返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/fund/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以手工充值（type=addfunds）', function () {
    $payload = [
        'user_id' => $this->user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'manual',
        'status' => 0,
        'remark' => '后台补单',
    ];

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/fund', $payload);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Fund::where('user_id', $this->user->id)->where('type', 'addfunds')->exists())->toBeTrue();
});

test('管理员可以手工扣款（type=deduct）', function () {
    $payload = [
        'user_id' => $this->user->id,
        'amount' => '50.00',
        'type' => 'deduct',
        'pay_method' => 'manual',
        'status' => 0,
        'remark' => '后台扣减',
    ];

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/fund', $payload);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Fund::where('user_id', $this->user->id)->where('type', 'deduct')->exists())->toBeTrue();
});

test('store 拒绝 type=refunds（必须走 fund/refunds/{id} UPDATE 同行接口）', function () {
    $payload = [
        'user_id' => $this->user->id,
        'amount' => '100.00',
        'type' => 'refunds',
        'pay_method' => 'manual',
        'status' => 1,
    ];

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/fund', $payload);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(Fund::where('type', 'refunds')->exists())->toBeFalse();
});

test('store 拒绝 type=reverse（必须走 fund/reverse/{id} UPDATE 同行接口）', function () {
    $payload = [
        'user_id' => $this->user->id,
        'amount' => '50.00',
        'type' => 'reverse',
        'pay_method' => 'manual',
        'status' => 1,
    ];

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/fund', $payload);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(Fund::where('type', 'reverse')->exists())->toBeFalse();
});

test('管理员可以删除资金记录', function () {
    // Fund deleting 事件要求 status=0 且创建超2小时
    $fund = Fund::factory()->create([
        'user_id' => $this->user->id,
        'created_at' => now()->subHours(3),
    ]);

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/fund/$fund->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Fund::find($fund->id))->toBeNull();
});

test('管理员不能删除已被入账的资金记录', function () {
    $fund = Fund::factory()->create([
        'user_id' => $this->user->id,
        'amount' => '100.00',
        'pay_method' => 'alipay',
        'pay_sn' => null,
        'status' => 0,
        'created_at' => now()->subHours(3),
    ]);

    DB::transaction(fn () => Fund::transitionToSuccessful(
        (string) $fund->id,
        '100.00',
        'addfunds',
        'alipay',
        'PAY_DESTROY_GUARD',
    ));

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/fund/$fund->id");

    $response->assertOk()->assertJson(['code' => 0]);
    expect($fund->fresh())->not->toBeNull();
    expect($fund->fresh()->status)->toBe(1);
    expect(Transaction::where('type', 'addfunds')->where('transaction_id', $fund->id)->count())->toBe(1);
    expect((string) $this->user->fresh()->balance)->toBe('100.00');
});

test('管理员可以退款已完成的充值记录', function () {
    $fund = Fund::factory()->completed()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/fund/refunds/$fund->id");

    $response->assertOk()->assertJson(['code' => 1]);

    $fund->refresh();
    expect($fund->type)->toBe('refunds');
    expect($fund->status)->toBe(2);
});

test('不能退款处理中的资金记录', function () {
    $fund = Fund::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
        'status' => 0,
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/fund/refunds/$fund->id");

    $response->assertOk()->assertJson(['code' => 0]);
    expect($fund->fresh()->type)->toBe('addfunds');
    expect($fund->fresh()->status)->toBe(0);
});

test('不能重复退款', function () {
    // Fund 的 creating 事件禁止 status=2 直接创建
    // 需要先创建 completed(status=1)，再更新为退款状态
    $fund = Fund::factory()->completed()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
    ]);
    // 通过 updating 将 status 从 1 改为 2（退款状态）
    $fund->status = 2;
    $fund->type = 'refunds';
    $fund->save();

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/fund/refunds/$fund->id");

    $response->assertOk()->assertJson(['code' => 0]);
    expect($fund->fresh()->type)->toBe('refunds');
    expect($fund->fresh()->status)->toBe(2);
});

test('管理员可以退回扣款记录', function () {
    $fund = Fund::factory()->deduct()->completed()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/fund/reverse/$fund->id");

    $response->assertOk()->assertJson(['code' => 1]);

    $fund->refresh();
    expect($fund->type)->toBe('reverse');
    expect($fund->status)->toBe(2);
});

test('不能退回非扣款记录', function () {
    $fund = Fund::factory()->completed()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/fund/reverse/$fund->id");

    $response->assertOk()->assertJson(['code' => 0]);
    expect($fund->fresh()->type)->toBe('addfunds');
    expect($fund->fresh()->status)->toBe(1);
});

test('管理员可以批量删除资金记录', function () {
    // Fund deleting 事件要求 status=0 且创建超2小时
    $funds = Fund::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'created_at' => now()->subHours(3),
    ]);
    $ids = $funds->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/fund/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Fund::whereIn('id', $ids)->count())->toBe(0);
});

test('未认证用户无法访问资金管理', function () {
    $response = $this->getJson('/api/admin/fund');

    $response->assertUnauthorized();
});

test('检查充值状态-微信查单带 Wechatpay-Serial 公钥序列号', function () {
    setWechatPublicKeyId('PUB_KEY_ID_TEST_0001');
    $fund = Fund::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 0,
    ]);

    $captured = mockPayCapture();

    $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/fund/check/$fund->id")
        ->assertOk();

    expect($captured->query)->not->toBeNull();
    expect($captured->query['_serial_no'] ?? null)->toBe('PUB_KEY_ID_TEST_0001');
});

test('检查充值状态-支付宝查单走标准 PaymentGateway 配置', function () {
    $fund = Fund::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
    ]);

    $captured = mockPayCapture();

    $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/fund/check/$fund->id")
        ->assertOk();

    expect($captured->alipayQuery)->not->toBeNull();
    expect($captured->alipayQuery['out_trade_no'] ?? null)->toBe($fund->id);
});

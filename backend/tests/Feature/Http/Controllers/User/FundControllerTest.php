<?php

use App\Models\Fund;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('获取资金记录列表', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $funds = Fund::factory()->count(3)->completed()->create(['user_id' => $user->id]);
    $otherFund = Fund::factory()->completed()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/fund')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);

    $returnedIds = collect($response->json('data.items'))
        ->pluck('id')
        ->map(fn ($id) => (string) $id)
        ->all();

    expect($response->json('data.total'))
        ->toBe(3)
        ->and($response->json('data.items'))
        ->toHaveCount(3)
        ->and($returnedIds)
        ->not->toContain((string) $otherFund->id);

    foreach ($funds as $fund) {
        expect($returnedIds)->toContain((string) $fund->id);
    }
});

test('获取资金记录列表-按类型筛选', function () {
    $user = User::factory()->create();
    Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'type' => 'addfunds',
    ]);
    Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'type' => 'deduct',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/fund?type=addfunds')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))
        ->toBe(1)
        ->and($response->json('data.items'))
        ->toHaveCount(1)
        ->and($response->json('data.items.0.type'))
        ->toBe('addfunds');
});

test('获取资金记录列表-按状态筛选', function () {
    $user = User::factory()->create();
    Fund::factory()->completed()->create(['user_id' => $user->id, 'status' => 1]);
    Fund::factory()->create(['user_id' => $user->id, 'status' => 0]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/fund?status=1')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))
        ->toBe(1)
        ->and($response->json('data.items.0.status'))
        ->toBe(1);
});

test('获取资金记录列表-按支付方式筛选', function () {
    $user = User::factory()->create();
    Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'pay_method' => 'alipay',
    ]);
    Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'pay_method' => 'wechat',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/fund?pay_method=alipay')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))
        ->toBe(1)
        ->and($response->json('data.items.0.pay_method'))
        ->toBe('alipay');
});

test('获取资金记录列表-不显示 status=0 记录', function () {
    $user = User::factory()->create();
    Fund::factory()->create([
        'user_id' => $user->id,
        'status' => 0,
    ]);
    Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'status' => 1,
    ]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/fund')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))->toBe(1);
});

test('检查充值状态-无效ID', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/fund/check/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('检查充值状态-不能查询其他用户的充值记录', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $otherUser->id,
        'type' => 'addfunds',
        'status' => 0,
        'pay_method' => 'other',
    ]);
    $originalPaySn = $fund->pay_sn;

    $this->actingAsUser($user)
        ->postJson('/api/fund/check/'.$fund->id)
        ->assertOk()
        ->assertJson(['code' => 0]);

    $fund->refresh();
    expect($fund->status)->toBe(0)
        ->and($fund->pay_sn)->toBe($originalPaySn);
});

test('检查充值状态-非处理中记录返回无效ID', function () {
    $user = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'status' => 1,
    ]);

    $this->actingAsUser($user)
        ->postJson('/api/fund/check/'.$fund->id)
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('资金记录-未认证', function () {
    $this->getJson('/api/fund')
        ->assertUnauthorized();
});

test('检查充值状态-微信查单带 Wechatpay-Serial 公钥序列号', function () {
    $user = User::factory()->create();
    setWechatPublicKeyId('PUB_KEY_ID_TEST_0001');
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 0,
    ]);

    $captured = mockPayCapture();

    $this->actingAsUser($user)
        ->postJson("/api/fund/check/$fund->id")
        ->assertOk();

    expect($captured->query)->not->toBeNull();
    expect($captured->query['_serial_no'] ?? null)->toBe('PUB_KEY_ID_TEST_0001');
});

test('检查充值状态-支付宝查单走标准 PaymentGateway 配置', function () {
    $user = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
    ]);

    $captured = mockPayCapture();

    $this->actingAsUser($user)
        ->postJson("/api/fund/check/$fund->id")
        ->assertOk();

    expect($captured->alipayQuery)->not->toBeNull();
    expect($captured->alipayQuery['out_trade_no'] ?? null)->toBe($fund->id);
});

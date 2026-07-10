<?php

use App\Models\ErrorLog;
use App\Models\Fund;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsUser;
use Yansongda\Pay\Pay;

uses(ActsAsUser::class);

afterEach(function () {
    Pay::clear();
    cache()->forget('pay_config_alipay');
    cache()->forget('pay_config_wechat');
    Mockery::close();
});

test('支付宝充值-金额无效', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/alipay', [
            'amount' => 0,
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('支付宝充值-金额为负数', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/alipay', [
            'amount' => -100,
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('微信充值-金额无效', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/wechat', [
            'amount' => 0,
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('检查充值状态-订单不存在返回成功', function () {
    $user = User::factory()->create();

    $response = $this->actingAsUser($user)
        ->getJson('/api/top-up/check/99999')
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.message', 'successful');
});

test('检查充值状态-已完成订单返回 successful', function () {
    $user = User::factory()->create();
    $fund = Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'pay_method' => 'alipay',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson("/api/top-up/check/$fund->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.message', 'successful');
    expect($fund->fresh()->status)->toBe(1);
});

test('检查充值状态-无权访问他人订单返回 successful', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $owner->id,
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 0,
    ]);

    $response = $this->actingAsUser($other)
        ->getJson("/api/top-up/check/$fund->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.message', 'successful');
    expect($fund->fresh()->status)->toBe(0);
});

test('获取银行账户信息-未配置', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/top-up/get-bank-account')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('获取银行账户信息-已配置返回成功', function () {
    $user = User::factory()->create();

    $group = SettingGroup::firstOrCreate(
        ['name' => 'bankAccount'],
        ['title' => 'Bank Account', 'weight' => 0]
    );

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'bank_name'],
        ['type' => 'string', 'value' => 'Test Bank']
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'account_name'],
        ['type' => 'string', 'value' => 'Test User']
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'account_no'],
        ['type' => 'string', 'value' => '1234567890']
    );

    $response = $this->actingAsUser($user)
        ->getJson('/api/top-up/get-bank-account')
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.bank_name', 'Test Bank');
    $response->assertJsonPath('data.account_name', 'Test User');
    $response->assertJsonPath('data.account_no', '1234567890');
});

test('充值-未认证', function () {
    $this->postJson('/api/top-up/alipay', ['amount' => 100])
        ->assertUnauthorized();
});

test('支付宝回调成功入账并返回 ACK', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
        'pay_sn' => null,
    ]);

    mockPayCallback('alipay', [
        'trade_status' => 'TRADE_SUCCESS',
        'out_trade_no' => (string) $fund->id,
        'total_amount' => '100.00',
        'trade_no' => 'ALI_TRADE_001',
    ]);

    $this->post('/callback/alipay')
        ->assertOk()
        ->assertSee('success', false);

    $fund->refresh();
    $user->refresh();

    expect($fund->status)->toBe(1);
    expect($fund->pay_sn)->toBe('ALI_TRADE_001');
    expect((string) $user->balance)->toBe('1100.00');

    $transaction = Transaction::where('type', 'addfunds')
        ->where('transaction_id', $fund->id)
        ->first();
    expect($transaction)->not->toBeNull();
    expect((string) $transaction->amount)->toBe('100.00');
});

test('微信回调成功入账并按分转元', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '123.45',
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 0,
        'pay_sn' => null,
    ]);

    mockPayCallback('wechat', [
        'resource' => [
            'ciphertext' => [
                'trade_state' => 'SUCCESS',
                'out_trade_no' => (string) $fund->id,
                'amount' => ['total' => 12345],
                'transaction_id' => 'WX_TRADE_001',
            ],
        ],
    ]);

    $this->post('/callback/wechat')
        ->assertOk()
        ->assertSee('success', false);

    $fund->refresh();
    $user->refresh();

    expect($fund->status)->toBe(1);
    expect($fund->pay_sn)->toBe('WX_TRADE_001');
    expect((string) $user->balance)->toBe('1123.45');

    $transaction = Transaction::where('type', 'addfunds')
        ->where('transaction_id', $fund->id)
        ->first();
    expect($transaction)->not->toBeNull();
    expect((string) $transaction->amount)->toBe('123.45');
});

test('支付宝重复回调只入账一次但仍返回 ACK', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
        'pay_sn' => null,
    ]);

    mockPayCallback('alipay', [
        'trade_status' => 'TRADE_SUCCESS',
        'out_trade_no' => (string) $fund->id,
        'total_amount' => '100.00',
        'trade_no' => 'ALI_TRADE_DUP',
    ], 2);

    $this->post('/callback/alipay')->assertOk();
    $this->post('/callback/alipay')->assertOk();

    $fund->refresh();
    $user->refresh();

    expect($fund->status)->toBe(1);
    expect($fund->pay_sn)->toBe('ALI_TRADE_DUP');
    expect((string) $user->balance)->toBe('1100.00');
    expect(Transaction::where('type', 'addfunds')->where('transaction_id', $fund->id)->count())->toBe(1);
});

test('支付宝回调金额不匹配时不 ACK 且不入账', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
        'pay_sn' => null,
    ]);

    mockPayCallback('alipay', [
        'trade_status' => 'TRADE_SUCCESS',
        'out_trade_no' => (string) $fund->id,
        'total_amount' => '99.99',
        'trade_no' => 'ALI_TRADE_BAD_AMOUNT',
    ], 1, false);

    $this->post('/callback/alipay')
        ->assertStatus(400)
        ->assertJson(['code' => 0]);

    $fund->refresh();
    $user->refresh();

    expect($fund->status)->toBe(0);
    expect($fund->pay_sn)->toBeNull();
    expect((string) $user->balance)->toBe('1000.00');
    expect(Transaction::where('transaction_id', $fund->id)->exists())->toBeFalse();
});

test('微信下单-已配置公钥时下单参数带 Wechatpay-Serial 公钥序列号', function () {
    $user = User::factory()->create();
    setWechatPublicKeyId('PUB_KEY_ID_TEST_0001');

    $captured = mockPayCapture();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/wechat', ['amount' => 1])
        ->assertOk();

    expect($captured->scan)->not->toBeNull();
    expect($captured->scan['_serial_no'] ?? null)->toBe('PUB_KEY_ID_TEST_0001');
});

test('微信下单-未配置公钥时下单参数不带 _serial_no（回退不破坏现状）', function () {
    $user = User::factory()->create();

    $captured = mockPayCapture();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/wechat', ['amount' => 1])
        ->assertOk();

    expect($captured->scan)->not->toBeNull();
    expect(array_key_exists('_serial_no', $captured->scan))->toBeFalse();
});

test('微信下单-仅配公钥ID未配公钥内容时不发头（gate 与公钥注册条件对称）', function () {
    $user = User::factory()->create();
    // 仅配 publicKeyId、未配 publicKey 内容（模拟运维半配）：本地无公钥可验签，
    // 不应发 Wechatpay-Serial，否则微信用公钥签应答而本地验签失败。
    $group = SettingGroup::firstOrCreate(['name' => 'wechat'], ['title' => '微信支付设置', 'weight' => 7]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'publicKeyId'],
        ['type' => 'string', 'value' => 'PUB_KEY_ID_TEST_0001']
    );
    Setting::clearGroupCache($group->id);

    $captured = mockPayCapture();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/wechat', ['amount' => 1])
        ->assertOk();

    expect($captured->scan)->not->toBeNull();
    expect(array_key_exists('_serial_no', $captured->scan))->toBeFalse();
});

test('检查充值状态-微信查单参数带 Wechatpay-Serial 公钥序列号', function () {
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
        ->getJson("/api/top-up/check/$fund->id")
        ->assertOk();

    expect($captured->query)->not->toBeNull();
    expect($captured->query['_serial_no'] ?? null)->toBe('PUB_KEY_ID_TEST_0001');
});

test('微信回调重放已成功充值单直接应答成功停止重试', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = DB::transaction(fn () => Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '123.45',
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 1, // 已成功（creating 钩子入账）
        'pay_sn' => 'WX_REPLAY_SETTLED',
    ]));
    $secret = seedWechatPayConfigCache();
    $balanceBefore = (string) $user->refresh()->balance;

    // 模拟微信补投：终态单 + 平台证书 serial（验签必失败的那类），应绕过验签直接 ACK
    $this->postJson('/callback/wechat', buildWechatNotifyBody((string) $fund->id, $secret, 'WX_REPLAY_SETTLED'), [
        'Wechatpay-Serial' => 'PLATFORM_CERT_SERIAL_76A5BC',
    ])
        ->assertOk()
        ->assertSee('SUCCESS', false);

    expect((string) $user->refresh()->balance)->toBe($balanceBefore); // 未重复入账
    expect($fund->refresh()->status)->toBe(1);
    // 报文与本地一致，不应产生不匹配错误日志
    expect(ErrorLog::where('message', 'like', '%不匹配%')->exists())->toBeFalse();
});

test('微信回调重放已退款充值单同样直接应答成功', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = DB::transaction(function () use ($user) {
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'type' => 'addfunds',
            'pay_method' => 'wechat',
            'status' => 1,
            'pay_sn' => 'WX_REPLAY_REFUNDED',
        ]);
        $fund->update(['type' => 'refunds', 'status' => 2]); // 1→2 退款（updating 钩子出账）

        return $fund;
    });
    $secret = seedWechatPayConfigCache();
    $balanceBefore = (string) $user->refresh()->balance;

    $this->postJson('/callback/wechat', buildWechatNotifyBody((string) $fund->id, $secret, 'WX_REPLAY_REFUNDED', 5000))
        ->assertOk()
        ->assertSee('SUCCESS', false);

    expect((string) $user->refresh()->balance)->toBe($balanceBefore);
    expect($fund->refresh()->status)->toBe(2);
});

test('微信回调重放报文与终态单不匹配时仍应答成功但记后台错误日志', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = DB::transaction(fn () => Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '123.45',
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 1,
        'pay_sn' => 'WX_REPLAY_MISMATCH',
    ]));
    $secret = seedWechatPayConfigCache();
    $balanceBefore = (string) $user->refresh()->balance;

    // 金额（9999 分 ≠ 123.45 元）与交易号均与本地不一致
    $this->postJson('/callback/wechat', buildWechatNotifyBody((string) $fund->id, $secret, 'WX_OTHER_TXN', 9999))
        ->assertOk()
        ->assertSee('SUCCESS', false);

    expect((string) $user->refresh()->balance)->toBe($balanceBefore); // ACK 不变、零状态变更
    expect($fund->refresh()->status)->toBe(1);
    expect(ErrorLog::where('message', 'like', '%微信回调重放数据与已终态充值单不匹配%')->exists())->toBeTrue();
});

test('微信回调指向支付宝终态单不被消噪放行仍走验签', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = DB::transaction(fn () => Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '88.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay', // 非微信单，消噪分支 pay_method 过滤必须挡住
        'status' => 1,
        'pay_sn' => 'ALI_SETTLED_001',
    ]));
    $secret = seedWechatPayConfigCache();
    $balanceBefore = (string) $user->refresh()->balance;

    $this->postJson('/callback/wechat', buildWechatNotifyBody((string) $fund->id, $secret))
        ->assertStatus(400); // 未被 ACK，走完整验签被拒

    expect($fund->refresh()->status)->toBe(1);
    expect((string) $user->refresh()->balance)->toBe($balanceBefore);
});

test('微信回调处理中订单不被消噪拦截仍走完整验签', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '123.45',
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 0, // 处理中
        'pay_sn' => null,
    ]);
    $secret = seedWechatPayConfigCache();

    // 未被忽略分支放行 → 进入 SDK 完整验签 → 无有效签名被拒
    $this->postJson('/callback/wechat', buildWechatNotifyBody((string) $fund->id, $secret))
        ->assertStatus(400);

    expect($fund->refresh()->status)->toBe(0); // 未入账
    expect((string) $user->refresh()->balance)->toBe('1000.00');
});

test('微信回调密文无法解密时回落完整验签不误放行', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = DB::transaction(fn () => Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '66.00',
        'type' => 'addfunds',
        'pay_method' => 'wechat',
        'status' => 1,
        'pay_sn' => 'WX_REPLAY_BADCIPHER',
    ]));
    seedWechatPayConfigCache(); // 本地密钥与密文密钥不一致 → 解密失败

    $this->postJson('/callback/wechat', buildWechatNotifyBody((string) $fund->id, str_repeat('x', 32)))
        ->assertStatus(400);
});

/**
 * 种入微信支付配置缓存（getPayConfig 缓存命中分支直接使用），返回 APIv3 密钥。
 */
function seedWechatPayConfigCache(): string
{
    $secret = str_repeat('k', 32);
    cache()->put('pay_config_wechat', [
        'mch_id' => '1677356476',
        'mch_secret_key' => $secret,
        'notify_url' => 'https://example.com/callback/wechat',
    ], now()->addDay());

    return $secret;
}

/**
 * 构造微信 v3 回调通知体，resource 为真实 AEAD_AES_256_GCM 加密密文。
 */
function buildWechatNotifyBody(string $outTradeNo, string $secret, string $transactionId = 'WX_REPLAY_TXN', int $amountTotal = 12345): array
{
    $plain = json_encode([
        'trade_state' => 'SUCCESS',
        'out_trade_no' => $outTradeNo,
        'transaction_id' => $transactionId,
        'amount' => ['total' => $amountTotal],
    ]);
    $nonce = 'ab12cd34ef56';
    $aad = 'transaction';
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

    return [
        'id' => 'notify-'.$outTradeNo,
        'create_time' => '2026-07-10T11:21:09+08:00',
        'resource_type' => 'encrypt_resource',
        'event_type' => 'TRANSACTION.SUCCESS',
        'summary' => '支付成功',
        'resource' => [
            'original_type' => 'transaction',
            'algorithm' => 'AEAD_AES_256_GCM',
            'ciphertext' => base64_encode($cipher.$tag),
            'associated_data' => $aad,
            'nonce' => $nonce,
        ],
    ];
}

function mockPayCallback(string $driver, array $payload, int $times = 1, bool $shouldAck = true): void
{
    Pay::clear();

    $provider = Mockery::mock();
    $provider->shouldReceive('callback')
        ->times($times)
        ->andReturn($payload);

    $successExpectation = $provider->shouldReceive('success');
    if ($shouldAck) {
        $successExpectation->times($times)->andReturn(new Response(200, [], 'success'));
    } else {
        $successExpectation->never();
    }

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive($driver)->andReturn($provider);
    app()->instance(PaymentGateway::class, $gateway);
}

<?php

use App\Models\CnameDelegation;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Order\AutoRenewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    $this->service = app(AutoRenewService::class);
});

afterEach(function () {
    Mockery::close();
});

// ==================== willAutoRenewExecute ====================

test('will auto renew execute returns false when disabled', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => false,
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(28),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto renew execute order setting overrides user', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => false, // 订单级别禁用
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(28),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto renew execute falls back to user setting', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => null, // 回落到用户设置
        'period_till' => now()->addDays(10), // ≤15天，走续费
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeTrue();
});

test('will auto renew execute returns false when product disabled', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 0, 'renew' => 1]); // 产品禁用
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(28),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto renew execute returns false when product not renewable', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 0]); // 不支持续费
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(28),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto renew execute returns false when order has too many days remaining', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(20), // >15天，应走重签而非续费
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto renew execute returns true when order days remaining within threshold', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(10), // ≤15天，走续费
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeTrue();
});

test('will auto renew execute boundary 15 days returns true', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(15), // 正好15天，≤15 走续费
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeTrue(); // 等于15天应该返回true（走续费）
});

test('will auto renew execute boundary 16 days returns false', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(16), // >15天，走重签
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoRenewExecute($order, $user);

    expect($result)->toBeFalse(); // 超过15天应该返回false（走重签）
});

// ==================== willAutoReissueExecute ====================

test('will auto reissue execute returns false when disabled', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => false,
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10), // 差20天
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto reissue execute order setting overrides user', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => false, // 订单级别禁用
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto reissue execute falls back to user setting', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => null, // 回落到用户设置
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeTrue();
});

test('will auto reissue execute returns true when product disabled but reissue enabled', function () {
    // 重签不限产品 status：产品禁用（status=0）但 reissue=1 仍应重签，与 getReissueOrders 只查 reissue==1 对齐
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 0, 'reissue' => 1]); // 产品禁用但允许重签
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeTrue();
});

test('will auto reissue execute returns false when product reissue disabled', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1, 'reissue' => 0]); // 产品不支持重签
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(30),
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto reissue execute returns false when order days remaining too small', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(10), // ≤15天，应走续费而非重签
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeFalse();
});

test('will auto reissue execute returns true when order days remaining exceeds threshold', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(30), // >15天，走重签
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(10),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeTrue();
});

test('will auto reissue execute boundary 15 days returns false', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(15), // 正好15天，≤15 不走重签
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeFalse(); // 等于15天应该返回false（走续费）
});

test('will auto reissue execute boundary 16 days returns true', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(16), // >15天，走重签
    ]);
    $this->createTestCert($order, [
        'channel' => 'api',
        'expires_at' => now()->addDays(5),
    ]);

    $order->refresh();
    $result = $this->service->willAutoReissueExecute($order, $user);

    expect($result)->toBeTrue(); // 超过15天应该返回true（走重签）
});

// ==================== checkDelegationValidity ====================

test('check delegation validity auto creates delegation when missing', function () {
    $user = $this->createTestUser();
    // 不手动创建委托记录

    $result = $this->service->checkDelegationValidity($user->id, 'example.com', 'sectigo');

    // 验证失败（无真实 DNS），但委托记录已自动创建
    expect($result)->toBeFalse();
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ])->exists())->toBeTrue();
});

test('check delegation validity returns false when verification fails', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
        'valid' => true,
    ]);

    // 实际 CNAME 验证会失败（因为没有真实的 DNS 记录）
    $result = $this->service->checkDelegationValidity($user->id, 'example.com', 'sectigo');

    expect($result)->toBeFalse();
});

test('check delegation validity uses correct ca for lookup and creation', function () {
    $user = $this->createTestUser();

    // 创建 Sectigo 的委托记录（使用 _pki-validation 前缀）
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
        'valid' => true,
    ]);

    // 使用 letsencrypt（回落 default _dnsauth），findDelegation 找不到 → 自动创建 → 验证失败
    // 重构后：findDelegation 接收 ca（非 prefix），resolveZone 派生 zone，createOrGet 收 prefix
    $mockDelegationService = Mockery::mock(CnameDelegationService::class)->makePartial();
    $mockDelegationService->shouldReceive('findDelegation')
        ->with($user->id, 'example.com', 'letsencrypt')
        ->andReturn(null);
    $mockDelegationService->shouldReceive('resolveZone')
        ->with('example.com', 'letsencrypt')
        ->andReturn('example.com');
    $mockDelegationService->shouldReceive('createOrGet')
        ->with($user->id, 'example.com', '_dnsauth')
        ->once()
        ->andReturn($this->createTestDelegation($user, [
            'zone' => 'example.com',
            'prefix' => '_dnsauth',
        ]));
    $mockDelegationService->shouldReceive('checkAndUpdateValidity')
        ->andReturn(false);

    $service = new AutoRenewService($mockDelegationService);
    $result = $service->checkDelegationValidity($user->id, 'example.com', 'letsencrypt');

    expect($result)->toBeFalse();
});

test('check delegation validity handles multiple domains auto creates missing', function () {
    $user = $this->createTestUser();
    $existingDelegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);
    $newDelegation = $this->createTestDelegation($user, [
        'zone' => 'other.com',
        'prefix' => '_pki-validation',
    ]);

    // mock：findDelegation 对 example.com 返回已有记录，对 other.com 返回 null（均传 ca=sectigo）
    $mockService = Mockery::mock(CnameDelegationService::class)->makePartial();
    $mockService->shouldReceive('findDelegation')
        ->with($user->id, 'example.com', 'sectigo')
        ->andReturn($existingDelegation);
    $mockService->shouldReceive('findDelegation')
        ->with($user->id, 'other.com', 'sectigo')
        ->andReturn(null);
    $mockService->shouldReceive('resolveZone')
        ->with('other.com', 'sectigo')
        ->andReturn('other.com');
    $mockService->shouldReceive('createOrGet')
        ->with($user->id, 'other.com', '_pki-validation')
        ->once()
        ->andReturn($newDelegation);
    $mockService->shouldReceive('checkAndUpdateValidity')
        ->andReturn(true);

    $service = new AutoRenewService($mockService);
    $result = $service->checkDelegationValidity(
        $user->id,
        'example.com,other.com',
        'sectigo'
    );

    expect($result)->toBeTrue();
});

test('check delegation validity auto creates dnsauth with root domain when not exact', function () {
    $user = $this->createTestUser();

    // 有意语义变更：letsencrypt 回落 default(_dnsauth, exact=false)，子域按根域创建
    $this->service->checkDelegationValidity($user->id, 'sub.example.com', 'letsencrypt');

    // exact=false → 按根域创建（覆盖所有子域）
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ])->exists())->toBeTrue();

    // 不应创建子域级别的记录
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'sub.example.com',
        'prefix' => '_dnsauth',
    ])->exists())->toBeFalse();
});

test('check delegation validity auto creates dnsauth with exact domain when exact', function () {
    config(['delegation.ca_map.digicert.exact' => true]);

    $user = $this->createTestUser();

    // exact=true：digicert 按精确域名创建
    $this->service->checkDelegationValidity($user->id, 'sub.example.com', 'digicert');

    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'sub.example.com',
        'prefix' => '_dnsauth',
    ])->exists())->toBeTrue();
});

test('check delegation validity auto creates pki-validation with root domain', function () {
    $user = $this->createTestUser();

    $this->service->checkDelegationValidity($user->id, 'sub.example.com', 'sectigo');

    // _pki-validation 应按根域创建
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ])->exists())->toBeTrue();

    // 不应创建子域级别的记录
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'sub.example.com',
        'prefix' => '_pki-validation',
    ])->exists())->toBeFalse();
});

test('check delegation validity auto creates certum with root domain', function () {
    $user = $this->createTestUser();

    $this->service->checkDelegationValidity($user->id, 'sub.example.com', 'certum');

    // _certum 应按根域创建
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'example.com',
        'prefix' => '_certum',
    ])->exists())->toBeTrue();
});

test('check delegation validity auto creates strips wildcard prefix', function () {
    $user = $this->createTestUser();

    $this->service->checkDelegationValidity($user->id, '*.example.com', 'letsencrypt');

    // 通配符应去除后按 example.com 创建
    expect(CnameDelegation::where([
        'user_id' => $user->id,
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ])->exists())->toBeTrue();
});

test('check delegation validity skips empty domains', function () {
    $user = $this->createTestUser();

    // 没有委托记录
    $result = $this->service->checkDelegationValidity($user->id, ',,,', 'sectigo');

    // 所有域名都是空的，应该返回 true（没有需要验证的）
    expect($result)->toBeTrue();
});

// ==================== isAutoRenewEnabled ====================

test('is auto renew enabled order setting', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => false]]);
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product, ['auto_renew' => true]);

    $result = $this->service->isAutoRenewEnabled($order, $user);

    expect($result)->toBeTrue();
});

test('is auto renew enabled falls back to user setting', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product, ['auto_renew' => null]);

    $result = $this->service->isAutoRenewEnabled($order, $user);

    expect($result)->toBeTrue();
});

test('is auto renew enabled returns false when both disabled', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => false]]);
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product, ['auto_renew' => null]);

    $result = $this->service->isAutoRenewEnabled($order, $user);

    expect($result)->toBeFalse();
});

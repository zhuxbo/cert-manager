<?php

use App\Services\Order\AutoRenewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

// 收敛：willBeHandledByAutoRenew 三腿谓词单一源。
// 派发侧 ExpireCommand 与重查侧 CertExpireNotificationBuilder 共用本方法，杜绝口径漂移
// （漂移会致「派发了 user 但 Builder 重查为空 → 整封静默漏发」或反向双发，见 auto-renew.md）。
// 锁三道 gate：① auto_renew_failed 模板停用 → false（回落发 cert_expire，防两头空）；
// ② latestCert.channel==api → false（下游自处理）；③ willAutoRenewExecute||willAutoReissueExecute。
uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    $this->service = app(AutoRenewService::class);
});

afterEach(function () {
    Mockery::close();
});

test('gate①：auto_renew_failed 模板停用 → false（即使 willAutoRenew 本会为真）', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(10), // ≤15 → willAutoRenewExecute 本为真
    ]);
    $this->createTestCert($order, ['channel' => 'web', 'expires_at' => now()->addDays(7)]);
    $order->refresh();

    // 模板停用（第三参 false）→ gate① 短路 false，不排除
    expect($this->service->willBeHandledByAutoRenew($order, $user, false))->toBeFalse();
});

test('gate②：latestCert.channel==api → false（下游自处理，即使 willAutoRenew 本会为真）', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(10),
    ]);
    $this->createTestCert($order, ['channel' => 'api', 'expires_at' => now()->addDays(7)]);
    $order->refresh();

    expect($this->service->willBeHandledByAutoRenew($order, $user, true))->toBeFalse();
});

test('gate③续费：非 api + 模板启用 + willAutoRenewExecute → true', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => true, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => true,
        'period_till' => now()->addDays(10), // ≤15 走续费
    ]);
    $this->createTestCert($order, ['channel' => 'web', 'expires_at' => now()->addDays(7)]);
    $order->refresh();

    expect($this->service->willBeHandledByAutoRenew($order, $user, true))->toBeTrue();
});

test('gate③重签：非 api + 模板启用 + willAutoReissueExecute → true', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = $this->createTestProduct(['status' => 1, 'reissue' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_reissue' => true,
        'period_till' => now()->addDays(30), // >15 走重签
    ]);
    $this->createTestCert($order, ['channel' => 'web', 'expires_at' => now()->addDays(7)]);
    $order->refresh();

    expect($this->service->willBeHandledByAutoRenew($order, $user, true))->toBeTrue();
});

test('gate③兜底：非 api + 模板启用但 auto 全关（willAuto* 均假）→ false（照发 cert_expire）', function () {
    $user = $this->createTestUser(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => false]]);
    $product = $this->createTestProduct(['status' => 1, 'renew' => 1]);
    $order = $this->createTestOrder($user, $product, [
        'auto_renew' => false,
        'auto_reissue' => false,
        'period_till' => now()->addDays(10),
    ]);
    $this->createTestCert($order, ['channel' => 'web', 'expires_at' => now()->addDays(7)]);
    $order->refresh();

    expect($this->service->willBeHandledByAutoRenew($order, $user, true))->toBeFalse();
});

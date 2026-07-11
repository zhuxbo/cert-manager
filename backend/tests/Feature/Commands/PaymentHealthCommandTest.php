<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

function paymentSetupAdmin(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
    Admin::factory()->create(['email' => 'ops@corp.example']);
    Cache::flush();
}

function paymentCaptureCenter(): object
{
    $state = new class
    {
        public int $count = 0;

        public mixed $captured = null;
    };
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        $state->count++;
        $state->captured = $intent;
    });
    app()->instance(NotificationCenter::class, $mock);

    return $state;
}

/** 生成自签证书 PEM，有效期 now + $days 天 */
function makePayCert(int $days): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'pay.test'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $pem);

    return $pem;
}

/** 写入某支付渠道 system_setting 分组 */
function setPaymentGroup(string $group, array $kv): void
{
    $g = SettingGroup::firstOrCreate(['name' => $group], ['title' => $group, 'weight' => 1]);
    foreach ($kv as $k => $v) {
        Setting::updateOrCreate(
            ['group_id' => $g->id, 'key' => $k],
            ['type' => 'string', 'value' => $v, 'weight' => 0]
        );
    }
    Setting::clearGroupCache($g->id);
}

/** 一套完整、远期有效的 alipay 配置（可用 overrides 覆盖单键） */
function healthyAlipay(array $overrides = []): array
{
    return array_merge([
        'app_id' => '2021000000000000',
        'app_secret_cert' => 'dummy-private-key',
        'appCertPublicKey' => makePayCert(400),
        'certPublicKeyRSA2' => makePayCert(400),
        'rootCert' => makePayCert(400),
    ], $overrides);
}

beforeEach(function () {
    paymentSetupAdmin();
    config()->set('monitoring.payment_health.enabled', true);
    config()->set('monitoring.payment_health.cert_warn_days', 30);
    config()->set('monitoring.payment_health.dedupe_ttl_hours', 168);
});

test('① 未配置渠道（无 app_id/mch_id）→ 无告警', function () {
    // 两渠道均未配置（空 app_id/mch_id）
    setPaymentGroup('alipay', ['app_id' => '']);
    setPaymentGroup('wechat', ['mch_id' => '']);
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('② 证书远期 → 无告警 + 清键', function () {
    Cache::put('system_alert:payment_health', 'stale', now()->addHours(168));
    setPaymentGroup('alipay', healthyAlipay());
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::has('system_alert:payment_health'))->toBeFalse();
});

test('③ 证书 <30 天 → 告警（含字段名，不含 PEM）', function () {
    setPaymentGroup('alipay', healthyAlipay(['certPublicKeyRSA2' => makePayCert(10)]));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(1)
        ->and($state->captured->context['category'])->toBe('payment_health');

    // 断言 details 含字段名但绝不含 PEM 内容
    $detailsJson = json_encode($state->captured->context['details']);
    expect($detailsJson)->toContain('certPublicKeyRSA2')
        ->and($detailsJson)->not->toContain('BEGIN CERTIFICATE');
});

test('④ 缺必填字段 → 完整性告警', function () {
    setPaymentGroup('alipay', healthyAlipay(['app_secret_cert' => '']));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(1);
    $detailsJson = json_encode($state->captured->context['details']);
    expect($detailsJson)->toContain('app_secret_cert');
});

test('⑤ 坏证书串 → 告警不 crash', function () {
    setPaymentGroup('alipay', healthyAlipay(['certPublicKeyRSA2' => 'not-a-valid-cert']));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('⑥ 连续两日同状态 → 第二日不重发（状态指纹）', function () {
    setPaymentGroup('alipay', healthyAlipay(['certPublicKeyRSA2' => makePayCert(10)]));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();
    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('⑦ 新增一张到期证书 → 指纹变 → 立即再发', function () {
    // 首轮：仅 certPublicKeyRSA2 到期
    setPaymentGroup('alipay', healthyAlipay(['certPublicKeyRSA2' => makePayCert(10)]));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();
    expect($state->count)->toBe(1);

    // 次轮：appCertPublicKey 也到期（新增到期项）→ 指纹变 → 再发
    setPaymentGroup('alipay', ['appCertPublicKey' => makePayCert(10)]);
    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(2);
});

test('⑧ 恢复后清键、再异常立即发', function () {
    setPaymentGroup('alipay', healthyAlipay(['certPublicKeyRSA2' => makePayCert(10)]));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();
    expect($state->count)->toBe(1);

    // 恢复（换远期证书）→ 清键
    setPaymentGroup('alipay', ['certPublicKeyRSA2' => makePayCert(400)]);
    $this->artisan('schedule:payment-health')->assertSuccessful();
    expect(Cache::has('system_alert:payment_health'))->toBeFalse();

    // 再异常 → 立即发
    setPaymentGroup('alipay', ['certPublicKeyRSA2' => makePayCert(10)]);
    $this->artisan('schedule:payment-health')->assertSuccessful();
    expect($state->count)->toBe(2);
});

test('⑨ rootCert 到期不触发（跳过 notAfter，只查非空）', function () {
    setPaymentGroup('alipay', healthyAlipay(['rootCert' => makePayCert(5)]));
    $state = paymentCaptureCenter();

    $this->artisan('schedule:payment-health')->assertSuccessful();

    expect($state->count)->toBe(0);
});

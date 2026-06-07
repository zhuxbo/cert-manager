<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\Action;
use App\Services\Order\Utils\CsrUtil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

// ==================== T1: getEncryptionParams 大小写归一 ====================

test('getEncryptionParams 大写 ECDSA 归一为小写并映射曲线', function () {
    $r = CsrUtil::getEncryptionParams(['encryption' => ['alg' => 'ECDSA', 'bits' => 384]]);
    expect($r['alg'])->toBe('ecdsa');
    expect($r['curve'])->toBe('secp384r1');
});

test('getEncryptionParams 大写 SM2 归一并强制 SM2 曲线 + sm3', function () {
    $r = CsrUtil::getEncryptionParams(['encryption' => ['alg' => 'SM2']]);
    expect($r['alg'])->toBe('sm2');
    expect($r['curve'])->toBe('SM2');
    expect($r['digest_alg'])->toBe('sm3');
});

test('getEncryptionParams 大写 RSA 归一并设 bits', function () {
    $r = CsrUtil::getEncryptionParams(['encryption' => ['alg' => 'RSA', 'bits' => 4096]]);
    expect($r['alg'])->toBe('rsa');
    expect($r['bits'])->toBe(4096);
});

// ==================== T2: inheritEncryptionFromLastCert 映射 ====================

test('inheritEncryptionFromLastCert 大写列值归一为小写 alg/bits/digest', function () {
    $action = app(Action::class);
    $reflect = new ReflectionMethod($action, 'inheritEncryptionFromLastCert');

    $ecdsa = new Cert(['encryption_alg' => 'ECDSA', 'encryption_bits' => 384, 'signature_digest_alg' => 'SHA384']);
    expect($reflect->invoke($action, $ecdsa))->toBe(['alg' => 'ecdsa', 'bits' => 384, 'digest_alg' => 'sha384']);

    $sm2 = new Cert(['encryption_alg' => 'SM2', 'encryption_bits' => 256, 'signature_digest_alg' => 'SM3']);
    expect($reflect->invoke($action, $sm2))->toBe(['alg' => 'sm2', 'bits' => 256, 'digest_alg' => 'sm3']);

    $rsa = new Cert(['encryption_alg' => 'RSA', 'encryption_bits' => 4096, 'signature_digest_alg' => 'SHA256']);
    expect($reflect->invoke($action, $rsa))->toBe(['alg' => 'rsa', 'bits' => 4096, 'digest_alg' => 'sha256']);
});

// ==================== T3: 续费 SM2 + gmEnabled 关 → 报错（决策①） ====================

test('initParams 续费 SM2 原证书 + gmEnabled 关 → 报错国密未启用（继承后 gate）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(); // gate 在 product.renew 校验前抛，默认产品即可
    $order = Order::factory()->create([
        'user_id' => $user->id, 'product_id' => $product->id,
        'period' => 12, 'period_from' => now()->subYear(), 'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'encryption_alg' => 'SM2', 'encryption_bits' => 256, 'signature_digest_alg' => 'SM3',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    expect((bool) get_system_setting('site', 'gmEnabled', false))->toBeFalse();

    $action = app(Action::class);
    $reflect = new ReflectionMethod($action, 'initParams');
    try {
        $reflect->invoke($action, ['action' => 'renew', 'order_id' => $order->id, 'channel' => 'admin', 'period' => 12]);
        test()->fail('应抛 ApiResponseException（国密未启用）');
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse()['msg'])->toContain('国密');
    }
});

// ==================== T4: 续费 ECDSA reuse_csr=0 → 全链路保持 ECDSA ====================

test('续费 ECDSA 原证书 + 不传 encryption → initParams 继承 ecdsa 且 getCert 生成 ECDSA CSR', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'product_type' => 'ssl', 'validation_type' => 'dv', 'status' => 1, 'renew' => 1,
        'encryption_alg' => ['rsa', 'ecdsa'],
        'signature_digest_alg' => ['sha256'],
        'validation_methods' => ['txt'],
        'periods' => [12, 24],
        'common_name_types' => ['standard'],
        'alternative_name_types' => ['standard', 'wildcard'],
        'add_san' => 1, 'replace_san' => 1, 'reuse_csr' => 0,
        'standard_max' => 100, 'wildcard_max' => 100, 'total_max' => 100, 'gift_root_domain' => 0,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id, 'product_id' => $product->id,
        'period' => 12, 'period_from' => now()->subYear(), 'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'encryption_alg' => 'ECDSA', 'encryption_bits' => 256, 'signature_digest_alg' => 'SHA256',
        'common_name' => 'renew-ecdsa.example.com', 'alternative_names' => 'renew-ecdsa.example.com',
        'standard_count' => 1, 'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $action = app(Action::class);

    $reflectInit = new ReflectionMethod($action, 'initParams');
    $params = $reflectInit->invoke($action, [
        'action' => 'renew', 'order_id' => $order->id, 'channel' => 'admin',
        'domains' => 'renew-ecdsa.example.com', 'validation_method' => 'txt',
        'period' => 12, 'csr_generate' => 1,
    ]);
    expect(strtolower((string) $params['encryption']['alg']))->toBe('ecdsa');

    $reflectCert = new ReflectionMethod($action, 'getCert');
    $certData = $reflectCert->invoke($action, $params);
    $pub = openssl_pkey_get_details(openssl_csr_get_public_key($certData['csr']));
    expect($pub['type'])->toBe(OPENSSL_KEYTYPE_EC);
});

<?php

use App\Exceptions\ApiResponseException;
use App\Services\Order\Action;
use Tests\TestCase;

uses(TestCase::class);

// parseCert / isSM2Cert 是 ActionTrait 的 protected 方法，经 Order\Action 反射调用测试

test('parseCert 识别 SM2 国密证书（真实 fixture，SM2/SM3/256）', function () {
    $cert = file_get_contents(base_path('tests/Fixtures/sm2_cert.pem'));
    $action = app(Action::class);
    $reflect = new ReflectionMethod($action, 'parseCert');
    $data = $reflect->invoke($action, $cert);

    expect($data['encryption_alg'])->toBe('SM2');
    expect($data['signature_digest_alg'])->toBe('SM3');
    expect($data['encryption_bits'])->toBe(256);
    expect($data['issuer'])->toBe('sm2test.example.com');
    expect($data['serial_number'])->not->toBeEmpty();
    expect($data['expires_at'])->toBeGreaterThan(0);
});

test('parseCert 对 RSA 证书不误判为 SM2', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'rsa.example.com'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $certPem);

    $action = app(Action::class);
    $reflect = new ReflectionMethod($action, 'parseCert');
    $data = $reflect->invoke($action, $certPem);

    expect($data['encryption_alg'])->toBe('RSA');
    expect($data['encryption_bits'])->toBe(2048);
});

test('isSM2Cert 走 DER OID 兜底（signatureTypeSN 不含 SM2 时也能识别）', function () {
    $sm2Cert = file_get_contents(base_path('tests/Fixtures/sm2_cert.pem'));

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'rsa.example.com'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $rsaCert);

    $action = app(Action::class);
    $reflect = new ReflectionMethod($action, 'isSM2Cert');

    // 传空 detectedAlg，强制走 DER OID 检测：SM2 命中、RSA 不命中
    expect($reflect->invoke($action, $sm2Cert, ''))->toBeTrue();
    expect($reflect->invoke($action, $rsaCert, ''))->toBeFalse();
});

test('gmEnabled 关闭时 SM2 下单被后端拒绝（initParams 兜底防绕过）', function () {
    // 测试环境未配置 gmEnabled → 默认 false
    expect((bool) get_system_setting('site', 'gmEnabled', false))->toBeFalse();

    $action = app(Action::class);
    $reflect = new ReflectionMethod($action, 'initParams');

    try {
        $reflect->invoke($action, ['action' => 'new', 'encryption' => ['alg' => 'sm2']]);
        $this->fail('应抛 ApiResponseException（国密未启用）');
    } catch (ApiResponseException $e) {
        // ApiResponseException 消息在 getApiResponse()['msg']，getMessage() 恒空（反模式 16）
        expect($e->getApiResponse()['msg'])->toContain('国密');
    }
});

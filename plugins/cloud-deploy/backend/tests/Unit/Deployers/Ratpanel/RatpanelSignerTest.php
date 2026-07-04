<?php

use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * RatPanel HMAC-SHA256 签名算法验证（buildAuthHeaders 纯函数）。
 * 金标向量由独立 PHP 复算（与 certimate signer.go 同算法）固化，确保线协议字节级对齐。
 */
test('buildAuthHeaders 产出与金标向量逐字节一致', function () {
    $headers = RatpanelRestClient::buildAuthHeaders(
        'POST',
        '/api/setting/cert',
        '',
        '{"cert":"C","key":"K"}',
        7,
        'secretkey',
        1700000000,
    );

    expect($headers['Authorization'])->toBe(
        'HMAC-SHA256 Credential=7, Signature=966c0981bd65fc41e6deaec36d0feeb4d78f1d11c7bd142f8ea0becdae6135f8'
    );
    expect($headers['X-Timestamp'])->toBe('1700000000');
});

test('Authorization 格式：HMAC-SHA256 Credential=<id>, Signature=<hex64>（逗号后有空格）', function () {
    $headers = RatpanelRestClient::buildAuthHeaders('POST', '/api/website/cert', '', '{}', 42, 'k', 1);
    expect($headers['Authorization'])->toMatch('/^HMAC-SHA256 Credential=42, Signature=[0-9a-f]{64}$/');
});

test('body 变化 → 签名变化（证明 body 进了 canonicalRequest 的 sha256）', function () {
    $a = RatpanelRestClient::buildAuthHeaders('POST', '/api/setting/cert', '', '{"cert":"A"}', 1, 'k', 1700000000);
    $b = RatpanelRestClient::buildAuthHeaders('POST', '/api/setting/cert', '', '{"cert":"B"}', 1, 'k', 1700000000);
    expect($a['Authorization'])->not->toBe($b['Authorization']);
});

test('accessToken（HMAC 密钥）变化 → 签名变化', function () {
    $a = RatpanelRestClient::buildAuthHeaders('POST', '/api/setting/cert', '', '{}', 1, 'key1', 1700000000);
    $b = RatpanelRestClient::buildAuthHeaders('POST', '/api/setting/cert', '', '{}', 1, 'key2', 1700000000);
    expect($a['Authorization'])->not->toBe($b['Authorization']);
});

test('timestamp 进 stringToSign：时间变化 → 签名变化，且 X-Timestamp 跟随', function () {
    $a = RatpanelRestClient::buildAuthHeaders('POST', '/api/setting/cert', '', '{}', 1, 'k', 1700000000);
    $b = RatpanelRestClient::buildAuthHeaders('POST', '/api/setting/cert', '', '{}', 1, 'k', 1700000001);
    expect($a['Authorization'])->not->toBe($b['Authorization']);
    expect($b['X-Timestamp'])->toBe('1700000001');
});

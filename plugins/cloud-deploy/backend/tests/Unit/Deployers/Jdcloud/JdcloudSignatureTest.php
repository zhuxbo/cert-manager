<?php

use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * JDCLOUD2-HMAC-SHA256 签名固定输入 → 固定输出回归。
 *
 * 期望签名由 jdcloud-sdk-go core/Signer.go 同算法的 Go 程序对同一输入（AK/SK/time/nonce/method/path/
 * query/body）算出（逐字节对齐），写死在此。任一签名步骤（canonical 拼接 / path 转义 / key 链 / 头排序）
 * 漂移即红 —— 这是「签错一字节全部 403」杀手场景的护栏。
 */
function jdSigFixedDate(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-06-28T10:20:30', new DateTimeZone('UTC'));
}

function jdSigFixedHeaders(): array
{
    $date = jdSigFixedDate();

    return [
        'Content-Type' => 'application/json',
        // User-Agent 故意带值：必须被签名忽略（ignoredHeaders），不进 SignedHeaders。
        'User-Agent' => 'X/Y',
        'x-jdcloud-date' => $date->format('Ymd\THis\Z'),
        'x-jdcloud-nonce' => '11111111-2222-4333-8444-555555555555',
    ];
}

test('POST 带 body + path 含冒号（/v1/sslCert:upload）签名逐字节对齐 Go 参考', function () {
    $auth = JdcloudRestClient::buildAuthorization(
        method: 'POST',
        host: 'ssl.jdcloud-api.com',
        // path 由 client 经 escapePath 转义为 %3A（与发送 URL 同串）
        uriPath: '/v1/sslCert%3Aupload',
        rawQuery: '',
        headers: jdSigFixedHeaders(),
        payload: '{"certName":"clouddeploy-1","certFile":"CERT","keyFile":"KEY"}',
        serviceName: 'ssl',
        accessKeyId: 'AKID-TEST-1234567890',
        accessKeySecret: 'SECRET-abcdefghijklmnop',
        date: jdSigFixedDate(),
    );

    expect($auth)->toBe(
        'JDCLOUD2-HMAC-SHA256 Credential=AKID-TEST-1234567890/20260628/jdcloud-api/ssl/jdcloud2_request, '
        .'SignedHeaders=content-type;host;x-jdcloud-date;x-jdcloud-nonce, '
        .'Signature=03ac43aa03cf75273d6e7c470fec0cf24e0a3034fbc78e410c422a123fcf739e'
    );
});

test('GET 带 query（空 body 用 emptyStringSHA256）签名逐字节对齐 Go 参考', function () {
    $auth = JdcloudRestClient::buildAuthorization(
        method: 'GET',
        host: 'vod.jdcloud-api.com',
        uriPath: '/v1/domains',
        rawQuery: 'pageNumber=1&pageSize=100',
        headers: jdSigFixedHeaders(),
        payload: '',
        serviceName: 'vod',
        accessKeyId: 'AKID-TEST-1234567890',
        accessKeySecret: 'SECRET-abcdefghijklmnop',
        date: jdSigFixedDate(),
    );

    expect($auth)->toBe(
        'JDCLOUD2-HMAC-SHA256 Credential=AKID-TEST-1234567890/20260628/jdcloud-api/vod/jdcloud2_request, '
        .'SignedHeaders=content-type;host;x-jdcloud-date;x-jdcloud-nonce, '
        .'Signature=a37cf486107f2bcaac72c152913d3e7a28c53edcf31bb3a8ad691cf037de9583'
    );
});

test('credential scope = shortDate/region/service/jdcloud2_request；service 进 scope', function () {
    // 仅服务名不同（cdn）即应改变 Credential scope（间接证明 service 进签名 key 链）
    $auth = JdcloudRestClient::buildAuthorization(
        method: 'GET',
        host: 'cdn.jdcloud-api.com',
        uriPath: '/v1/domain/a.example.com/config',
        rawQuery: '',
        headers: jdSigFixedHeaders(),
        payload: '',
        serviceName: 'cdn',
        accessKeyId: 'AKID-TEST-1234567890',
        accessKeySecret: 'SECRET-abcdefghijklmnop',
        date: jdSigFixedDate(),
    );

    expect($auth)->toContain('Credential=AKID-TEST-1234567890/20260628/jdcloud-api/cdn/jdcloud2_request');
    // SK 绝不出现在 Authorization 头里（仅派生签名）
    expect($auth)->not->toContain('SECRET-abcdefghijklmnop');
});

test('region 参与 key 链：不同 region 产生不同签名', function () {
    $base = [
        'method' => 'GET', 'host' => 'lb.jdcloud-api.com', 'uriPath' => '/v1/regions/x/listeners/lsr-1',
        'rawQuery' => '', 'headers' => jdSigFixedHeaders(), 'payload' => '', 'serviceName' => 'lb',
        'accessKeyId' => 'AK', 'accessKeySecret' => 'SK', 'date' => jdSigFixedDate(),
    ];
    $a = JdcloudRestClient::buildAuthorization(...$base + ['region' => 'cn-north-1']);
    $b = JdcloudRestClient::buildAuthorization(...$base + ['region' => 'cn-east-2']);

    expect($a)->not->toBe($b);
    expect($a)->toContain('/cn-north-1/lb/jdcloud2_request');
    expect($b)->toContain('/cn-east-2/lb/jdcloud2_request');
});

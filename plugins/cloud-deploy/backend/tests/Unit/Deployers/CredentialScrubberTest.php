<?php

use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunErrorSanitizer;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Plugins\CloudDeploy\Deployers\Tencent\TencentErrorSanitizer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 加固 1 — sanitizer 凭证子串兜底扫描（纵深防御）验证：
 *   ① CredentialScrubber::scrub 对各凭证 pattern 命中即 redact；普通文案不误伤。
 *   ② **关键**：构造一个 sanitizer「正常分支会透传」但含凭证的输入，断言经 sanitize() 出口被兜底 redact
 *      —— 证明兜底不是装饰，确实在威胁模型边界被破时拦下凭证。
 */
test('scrub 命中阿里 AccessKeyId 字面量（AKIA + 16 位）', function () {
    expect(CredentialScrubber::scrub('id is AKIAEXAMPLE123456789 here'))
        ->toContain('[redacted]')
        ->not->toContain('AKIAEXAMPLE123456789');
});

test('scrub 命中阿里云 LTAI 前缀 AccessKeyId', function () {
    expect(CredentialScrubber::scrub('ak=LTAI5tFakeKeyId00000'))
        ->toContain('[redacted]')
        ->not->toContain('LTAI5tFakeKeyId00000');
});

test('scrub 命中签名查询串 AccessKeyId= / Signature=（连值一并抹）', function () {
    $in = 'https://x.aliyuncs.com/?AccessKeyId=AKIAEXAMPLE123456789&Signature=wJalrXUtnFEMIbcdEXAMPLEKEY&Action=Foo';
    $out = CredentialScrubber::scrub($in);

    expect($out)
        ->not->toContain('AKIAEXAMPLE123456789')
        ->not->toContain('wJalrXUtnFEMIbcdEXAMPLEKEY')
        ->not->toContain('AccessKeyId=')
        ->not->toContain('Signature=');
});

test('scrub 命中 OSSAccessKeyId= 与 AccessKeySecret=', function () {
    $out = CredentialScrubber::scrub('OSSAccessKeyId=AKIAEXAMPLE123456789&x=1 AccessKeySecret=topsecretvalue');

    expect($out)
        ->not->toContain('AKIAEXAMPLE123456789')
        ->not->toContain('topsecretvalue')
        ->not->toContain('OSSAccessKeyId=')
        ->not->toContain('AccessKeySecret=');
});

test('scrub 命中腾讯 secret_id / secret_key（下划线写法，连值抹）', function () {
    $out = CredentialScrubber::scrub('cred secret_id=AKIDz8krbsJ5yKBZQpnEXAMPLE secret_key=wJalrXUtnFEMIK7MDENGEXAMPLEKEY tail');

    expect($out)
        ->not->toContain('AKIDz8krbsJ5yKBZQpnEXAMPLE')
        ->not->toContain('wJalrXUtnFEMIK7MDENGEXAMPLEKEY')
        ->not->toContain('secret_id=')
        ->not->toContain('secret_key=');
});

test('scrub 命中 SecretId / SecretKey（驼峰写法）', function () {
    $out = CredentialScrubber::scrub('SecretId: AKIDz8krbsJ5yKBZQpnEXAMPLE, SecretKey: wJalrXUtnFEMIK7MDENGEXAMPLEKEY');

    expect($out)
        ->not->toContain('AKIDz8krbsJ5yKBZQpnEXAMPLE')
        ->not->toContain('wJalrXUtnFEMIK7MDENGEXAMPLEKEY');
});

test('scrub 命中 PEM 私钥头', function () {
    $out = CredentialScrubber::scrub("error body: -----BEGIN PRIVATE KEY-----\nMIIEvQ\n...");

    expect($out)
        ->toContain('[redacted]')
        ->not->toContain('-----BEGIN');
});

test('scrub 不误伤普通错误文案（无凭证 pattern 原样返回）', function () {
    $msg = '[InvalidDomain.NotFound] 域名不存在 request id: req-123';
    expect(CredentialScrubber::scrub($msg))->toBe($msg);
});

test('加固生效证明：腾讯 sanitizer 正常透传分支含凭证时被兜底 redact', function () {
    // 腾讯 sanitizer 设计为「透传 SDK 自身 message」（威胁模型假设凭证在 TC3 头、不入 message）。
    // 但若某天 SDK message 意外带出凭证（边界被破），兜底必须拦下。构造一个 message 含凭证的
    // TencentCloudSDKException —— 走的是正常透传分支（[code] message），断言凭证被 scrub。
    $leakMsg = 'auth failed: secret_id=AKIDz8krbsJ5yKBZQpnEXAMPLE secret_key=wJalrXUtnFEMIK7MDENGEXAMPLEKEY AccessKeyId=AKIAEXAMPLE123456789 Signature=wJalrXUtnFEMIbcdEXAMPLEKEY';
    $out = TencentErrorSanitizer::sanitize(new TencentCloudSDKException('AuthFailure', $leakMsg, 'req-1'));

    // 错误码保留（响应体可读信息），但凭证子串全被兜底抹掉
    expect($out)->toContain('AuthFailure');
    expect($out)
        ->not->toContain('AKIDz8krbsJ5yKBZQpnEXAMPLE')
        ->not->toContain('wJalrXUtnFEMIK7MDENGEXAMPLEKEY')
        ->not->toContain('AKIAEXAMPLE123456789')
        ->not->toContain('wJalrXUtnFEMIbcdEXAMPLEKEY')
        ->not->toContain('secret_id=')
        ->not->toContain('secret_key=')
        ->not->toContain('Signature=');
});

test('加固生效证明：阿里 sanitizer 结构化分支 Message 含凭证时被兜底 redact', function () {
    // 阿里结构化错误分支取 data['Message']（响应体，正常不含凭证）。若上游响应 Message 意外混入凭证，
    // 兜底拦下。构造 data['Message'] 含凭证的 TeaError，断言走 [code] Message 分支后凭证被 scrub。
    $leak = new TeaError([
        'code' => 'SomeError',
        'message' => 'whatever',
        'data' => ['Code' => 'SomeError', 'Message' => 'leaked AccessKeyId=AKIAEXAMPLE123456789 Signature=wJalrXUtnFEMIbcdEXAMPLEKEY', 'RequestId' => 'r'],
    ]);
    $out = AliyunErrorSanitizer::sanitize($leak);

    expect($out)->toContain('SomeError');
    expect($out)
        ->not->toContain('AKIAEXAMPLE123456789')
        ->not->toContain('wJalrXUtnFEMIbcdEXAMPLEKEY')
        ->not->toContain('AccessKeyId=')
        ->not->toContain('Signature=');
});

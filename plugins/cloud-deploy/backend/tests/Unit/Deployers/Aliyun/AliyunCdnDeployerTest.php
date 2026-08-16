<?php

use AlibabaCloud\SDK\Cdn\V20180510\Cdn;
use AlibabaCloud\SDK\Cdn\V20180510\Models\DescribeUserDomainsResponse;
use AlibabaCloud\SDK\Cdn\V20180510\Models\DescribeUserDomainsResponseBody;
use AlibabaCloud\SDK\Cdn\V20180510\Models\DescribeUserDomainsResponseBody\domains;
use AlibabaCloud\SDK\Cdn\V20180510\Models\DescribeUserDomainsResponseBody\domains\pageData;
use AlibabaCloud\SDK\Cdn\V20180510\Models\SetCdnDomainSSLCertificateRequest;
use AlibabaCloud\SDK\Cdn\V20180510\Models\SetCdnDomainSSLCertificateResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCdnDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock client（不 new 真实 SDK client，不触网）。
 * 通过 $captured 暴露 bind 传给 SDK 的 request，供断言。
 */
function aliyunCdnDeployerWith(callable $clientFactory): AliyunCdnDeployer
{
    return new class($clientFactory) extends AliyunCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('阿里云 CDN 经 CAS 证书服务绑定', function () {
    $deployer = new AliyunCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'ap-southeast-1'])->storeKind())->toBe('cas:ap-southeast-1');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('cdn');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region')->toContain('domain_match_pattern');
});

test('bind 调 setCdnDomainSSLCertificate 使用 CAS CertId/CertRegion', function () {
    $captured = null;
    $mock = Mockery::mock(Cdn::class);
    $mock->shouldReceive('setCdnDomainSSLCertificate')
        ->once()
        ->andReturnUsing(function (SetCdnDomainSSLCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new SetCdnDomainSSLCertificateResponse;
        });

    $deployer = aliyunCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $mock : new stdClass);
    $deployer->bind(
        '12345-ap-southeast-1',
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'cdn.example.com'],
    );

    expect($captured)->toBeInstanceOf(SetCdnDomainSSLCertificateRequest::class);
    expect($captured->domainName)->toBe('cdn.example.com');
    expect($captured->certType)->toBe('cas');
    expect($captured->certId)->toBe(12345);
    expect($captured->certRegion)->toBe('ap-southeast-1');
    expect($captured->SSLProtocol)->toBe('on');
});

test('wildcard 分页列举并更新所有匹配域名', function () {
    $updated = [];
    $mock = Mockery::mock(Cdn::class);
    $mock->shouldReceive('describeUserDomains')->once()->andReturn(new DescribeUserDomainsResponse([
        'body' => new DescribeUserDomainsResponseBody([
            'domains' => new domains(['pageData' => [
                new pageData(['domainName' => 'a.example.com', 'domainStatus' => 'online']),
                new pageData(['domainName' => 'deep.a.example.com', 'domainStatus' => 'online']),
                new pageData(['domainName' => 'b.example.com', 'domainStatus' => 'offline']),
            ]]),
        ]),
    ]));
    $mock->shouldReceive('setCdnDomainSSLCertificate')->once()->andReturnUsing(function ($request) use (&$updated) {
        $updated[] = $request->domainName;

        return new SetCdnDomainSSLCertificateResponse;
    });

    aliyunCdnDeployerWith(fn () => $mock)->bind(
        '1-cn-hangzhou',
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK', 'resource_group_id' => 'rg-1'],
        ['domain_match_pattern' => 'wildcard', 'domain' => '*.example.com'],
    );

    expect($updated)->toBe(['a.example.com']);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(
        '1-cn-hangzhou',
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        [],
    ))->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('SDK 抛 TeaError 时 deployer 重抛脱敏异常（含错误码、无 AK/SK）', function () {
    $mock = Mockery::mock(Cdn::class);
    // 模拟结构化 API 错误（data=响应体），sanitize 应取 code + Message
    $mock->shouldReceive('setCdnDomainSSLCertificate')->andThrow(new TeaError([
        'code' => 'InvalidDomain.NotFound',
        'message' => 'code: 404, the domain does not exist request id: req-1',
        'data' => ['Code' => 'InvalidDomain.NotFound', 'Message' => 'the domain does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunCdnDeployerWith(fn () => $mock);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidDomain.NotFound')->toContain('the domain does not exist');
        // 脱敏：不带凭证、不挂 previous（trace 不带 SDK 帧）
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('SDK 抛网络类异常（message 含签名 URI）时脱敏只暴露类名，绝不回传 message', function () {
    $mock = Mockery::mock(Cdn::class);
    // 模拟 SDK 把底层 Guzzle 异常包成 TeaError（data=null），message 含 AccessKeyId 签名查询串
    $mock->shouldReceive('setCdnDomainSSLCertificate')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://cdn.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunCdnDeployerWith(fn () => $mock);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
    }
});

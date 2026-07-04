<?php

use AlibabaCloud\SDK\FC\V20230330\FC;
use AlibabaCloud\SDK\FC\V20230330\Models\CertConfig;
use AlibabaCloud\SDK\FC\V20230330\Models\CustomDomain;
use AlibabaCloud\SDK\FC\V20230330\Models\GetCustomDomainResponse;
use AlibabaCloud\SDK\FC\V20230330\Models\TLSConfig;
use AlibabaCloud\SDK\FC\V20230330\Models\UpdateCustomDomainRequest;
use AlibabaCloud\SDK\FC\V20230330\Models\UpdateCustomDomainResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunFcDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock client（不 new 真实 SDK client，不触网）。
 */
function aliyunFcDeployerWith(callable $clientFactory): AliyunFcDeployer
{
    return new class($clientFactory) extends AliyunFcDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

/**
 * 构造 GetCustomDomain 响应（body=CustomDomain）。
 */
function fcGetResponse(?string $protocol, ?TLSConfig $tls, ?CertConfig $cert): GetCustomDomainResponse
{
    return new GetCustomDomainResponse(['body' => new CustomDomain([
        'domainName' => 'fc.example.com',
        'protocol' => $protocol,
        'tlsConfig' => $tls,
        'certConfig' => $cert,
    ])]);
}

test('阿里云 FC 直传，不走证书服务', function () {
    $deployer = new AliyunFcDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('fc');
    // configSchema 覆盖 bind 实际读取的 domain + region
    expect(array_column($deployer->configSchema(), 'key'))->toContain('domain')->toContain('region');
});

test('bind get-then-update：灌 CertConfig（Certificate=cert+chain、PrivateKey=key）并保留 protocol/tlsConfig', function () {
    $tls = new TLSConfig(['minVersion' => 'TLSv1.2']);
    $get = Mockery::mock(FC::class);
    $get->shouldReceive('getCustomDomain')
        ->once()
        ->with('fc.example.com')
        ->andReturn(fcGetResponse('HTTPS', $tls, new CertConfig(['certificate' => 'OLDCERT'])));

    $capturedDomain = null;
    $capturedReq = null;
    $get->shouldReceive('updateCustomDomain')
        ->once()
        ->andReturnUsing(function (string $domain, UpdateCustomDomainRequest $req) use (&$capturedDomain, &$capturedReq) {
            $capturedDomain = $domain;
            $capturedReq = $req;

            return new UpdateCustomDomainResponse;
        });

    $deployer = aliyunFcDeployerWith(fn (string $kind) => $kind === 'fc' ? $get : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'fc.example.com', 'region' => 'cn-hangzhou'],
    );

    expect($capturedDomain)->toBe('fc.example.com');
    $body = $capturedReq->body;
    expect($body->certConfig->certificate)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($body->certConfig->privateKey)->toBe('KEYPEM');
    expect($body->certConfig->certName)->toStartWith('clouddeploy_');
    // 保留既有 protocol + tlsConfig（全量覆盖接口，不带会被重置）
    expect($body->protocol)->toBe('HTTPS');
    expect($body->tlsConfig)->toBe($tls);
});

test('bind 既有 protocol=HTTP 时升级为 HTTP,HTTPS', function () {
    $get = Mockery::mock(FC::class);
    $get->shouldReceive('getCustomDomain')->once()->andReturn(fcGetResponse('HTTP', null, null));

    $capturedReq = null;
    $get->shouldReceive('updateCustomDomain')
        ->once()
        ->andReturnUsing(function (string $domain, UpdateCustomDomainRequest $req) use (&$capturedReq) {
            $capturedReq = $req;

            return new UpdateCustomDomainResponse;
        });

    $deployer = aliyunFcDeployerWith(fn () => $get);
    $deployer->bind(
        ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'fc.example.com', 'region' => 'cn-hangzhou'],
    );

    expect($capturedReq->body->protocol)->toBe('HTTP,HTTPS');
});

test('bind 证书未变化时短路跳过 update（幂等）', function () {
    // 既有 certConfig.certificate 恰等于本次将上传的 cert+chain → 不应再 update
    $sameCert = rtrim('CERTPEM')."\n".trim('CHAINPEM');
    $get = Mockery::mock(FC::class);
    $get->shouldReceive('getCustomDomain')->once()->andReturn(fcGetResponse('HTTPS', null, new CertConfig(['certificate' => $sameCert])));
    $get->shouldReceive('updateCustomDomain')->never();

    $deployer = aliyunFcDeployerWith(fn () => $get);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'fc.example.com', 'region' => 'cn-hangzhou'],
    );

    // 无异常即通过；updateCustomDomain 被断言 never()
    expect(true)->toBeTrue();
});

test('bind 既有无 certConfig（首次绑定）也照常 update', function () {
    $get = Mockery::mock(FC::class);
    $get->shouldReceive('getCustomDomain')->once()->andReturn(fcGetResponse('HTTPS', null, null));
    $capturedReq = null;
    $get->shouldReceive('updateCustomDomain')->once()->andReturnUsing(function (string $d, UpdateCustomDomainRequest $req) use (&$capturedReq) {
        $capturedReq = $req;

        return new UpdateCustomDomainResponse;
    });

    $deployer = aliyunFcDeployerWith(fn () => $get);
    $deployer->bind(
        ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'fc.example.com', 'region' => 'cn-hangzhou'],
    );

    expect($capturedReq->body->certConfig->certificate)->toContain('C');
});

test('makeClient 把 region 透传进 credentials（供真实 makeClient 计算 endpoint）', function () {
    $seenCredentials = null;
    $get = Mockery::mock(FC::class);
    $get->shouldReceive('getCustomDomain')->once()->andReturn(fcGetResponse('HTTPS', null, null));
    $get->shouldReceive('updateCustomDomain')->once()->andReturn(new UpdateCustomDomainResponse);

    $deployer = aliyunFcDeployerWith(function (string $kind, array $credentials) use (&$seenCredentials, $get) {
        $seenCredentials = $credentials;

        return $get;
    });
    $deployer->bind(
        ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'fc.example.com', 'region' => 'cn-shenzhen'],
    );

    expect($seenCredentials['region'])->toBe('cn-shenzhen');
});

test('缺 domain / region 配置抛业务错误（不调 SDK）', function () {
    $deployer = aliyunFcDeployerWith(fn () => new stdClass);

    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['region' => 'r']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'fc.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('bind SDK 抛 TeaError（结构化）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $get = Mockery::mock(FC::class);
    $get->shouldReceive('getCustomDomain')->andThrow(new TeaError([
        'code' => 'DomainNameNotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'DomainNameNotFound', 'Message' => 'the domain does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunFcDeployerWith(fn () => $get);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK-LEAK-FC', 'access_key_secret' => 'SK-LEAK-FC'], ['domain' => 'x.example.com', 'region' => 'cn-hangzhou']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DomainNameNotFound')->toContain('the domain does not exist');
        expect($e->getMessage())->not->toContain('AK-LEAK-FC')->not->toContain('SK-LEAK-FC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-FC')->not->toContain('SK-LEAK-FC');
    }
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $get = Mockery::mock(FC::class);
    // data=null 走网络/未知分支：message 含签名 URI，绝不回传
    $get->shouldReceive('getCustomDomain')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://fcv3.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunFcDeployerWith(fn () => $get);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], ['domain' => 'x.example.com', 'region' => 'cn-hangzhou']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
    }
});

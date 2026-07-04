<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudFaasDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（faas）。 */
function ctyunFaasDeployerWith(callable $clientFactory): CtcccloudFaasDeployer
{
    return new class($clientFactory) extends CtcccloudFaasDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunFaasCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function ctyunFaasCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('天翼云 FaaS：内联型（usesRemoteCertStore=false + certUploader=null + schema）', function () {
    $deployer = new CtcccloudFaasDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('ctcccloud');
    expect($deployer->product())->toBe('faas');
    expect($deployer->label())->toBe('天翼云函数计算 FaaS');
    expect(array_column($deployer->configSchema(), 'key'))->toBe(['region_id', 'domain']);
});

test('bind：GET 自定义域名（regionId 头 + cnameCheck=false）→ PUT 直灌 PEM（protocol 加 HTTPS + 回写 authConfig）', function () {
    $getCall = null;
    $putCall = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query, array $headers) use (&$getCall) {
        $getCall = compact('path', 'query', 'headers');

        return ['statusCode' => '0', 'returnObj' => [
            'domainName' => 'd.example.com',
            'protocol' => 'HTTP',  // 不含 HTTPS → 追加 ,HTTPS
            'authConfig' => ['authType' => 'noAuth'],
            'certConfig' => ['certificate' => 'OLD', 'privateKey' => 'OLDKEY'],
        ]];
    });
    $client->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body, array $query, array $headers) use (&$putCall) {
        $putCall = compact('path', 'body', 'query', 'headers');

        return ['statusCode' => '0'];
    });

    $deployer = ctyunFaasDeployerWith(fn () => $client);
    $deployer->bind(ctyunFaasCertRef(), ctyunFaasCreds(), ['region_id' => 'cn-bj', 'domain' => 'd.example.com']);

    // GET：路径含域名（rawurlencode）、regionId 头、cnameCheck=false
    expect($getCall['path'])->toBe('/openapi/v1/domains/customdomains/d.example.com');
    expect($getCall['query'])->toBe(['cnameCheck' => 'false']);
    expect($getCall['headers'])->toBe(['regionId' => 'cn-bj']);

    // PUT：同路径 + regionId 头；body 直灌完整链 + 私钥；protocol 追加 HTTPS；回写 authConfig
    expect($putCall['path'])->toBe('/openapi/v1/domains/customdomains/d.example.com');
    expect($putCall['headers'])->toBe(['regionId' => 'cn-bj']);
    expect($putCall['body']['domainName'])->toBe('d.example.com');
    expect($putCall['body']['protocol'])->toBe('HTTP,HTTPS');
    expect($putCall['body']['authConfig'])->toBe(['authType' => 'noAuth']);
    expect($putCall['body']['certConfig']['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($putCall['body']['certConfig']['privateKey'])->toBe('KEYPEM');
    expect($putCall['body']['certConfig']['certName'])->toStartWith('clouddeploy-');
});

test('bind：protocol 已含 HTTPS 时不重复追加', function () {
    $putCall = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['statusCode' => '0', 'returnObj' => [
        'protocol' => 'HTTP,HTTPS', 'certConfig' => ['certificate' => 'X', 'privateKey' => 'Y'],
    ]]);
    $client->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body) use (&$putCall) {
        $putCall = compact('path', 'body');

        return ['statusCode' => '0'];
    });

    $deployer = ctyunFaasDeployerWith(fn () => $client);
    $deployer->bind(ctyunFaasCertRef(), ctyunFaasCreds(), ['region_id' => 'cn-bj', 'domain' => 'd.example.com']);

    expect($putCall['body']['protocol'])->toBe('HTTP,HTTPS');
});

test('bind：protocol 为空时设为 HTTPS；无 authConfig 时不带该键', function () {
    $putCall = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['statusCode' => '0', 'returnObj' => [
        'protocol' => '', 'certConfig' => ['certificate' => 'X', 'privateKey' => 'Y'],
    ]]);
    $client->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body) use (&$putCall) {
        $putCall = compact('path', 'body');

        return ['statusCode' => '0'];
    });

    $deployer = ctyunFaasDeployerWith(fn () => $client);
    $deployer->bind(ctyunFaasCertRef(), ctyunFaasCreds(), ['region_id' => 'cn-bj', 'domain' => 'd.example.com']);

    expect($putCall['body']['protocol'])->toBe('HTTPS');
    expect($putCall['body'])->not->toHaveKey('authConfig');
});

test('bind：证书 + 私钥与已部署完全一致 → 幂等跳过（不调 PUT）', function () {
    $fullChain = "CERTPEM\nCHAINPEM";  // = rtrim(cert)."\n".trim(chain)
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['statusCode' => '0', 'returnObj' => [
        'protocol' => 'HTTPS',
        'certConfig' => ['certificate' => $fullChain, 'privateKey' => 'KEYPEM'],
    ]]);
    $client->shouldReceive('put')->never();

    $deployer = ctyunFaasDeployerWith(fn () => $client);
    $deployer->bind(ctyunFaasCertRef(), ctyunFaasCreds(), ['region_id' => 'cn-bj', 'domain' => 'd.example.com']);

    expect(true)->toBeTrue();
});

test('缺 region_id / domain 配置抛业务错误', function () {
    $deployer = ctyunFaasDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(ctyunFaasCertRef(), ctyunFaasCreds(), ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 region_id');
    expect(fn () => $deployer->bind(ctyunFaasCertRef(), ctyunFaasCreds(), ['region_id' => 'cn-bj']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛异常脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->andThrow(new RuntimeException('cURL https://cf-global.ctapi.ctyun.cn/x AK-LEAK'));

    $deployer = ctyunFaasDeployerWith(fn () => $client);
    try {
        $deployer->bind(ctyunFaasCertRef(), ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], ['region_id' => 'cn-bj', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('天翼云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('cf-global');
        expect($e->getPrevious())->toBeNull();
    }
});

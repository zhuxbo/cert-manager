<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudIcdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（icdn）。 */
function ctyunIcdnDeployerWith(callable $clientFactory): CtcccloudIcdnDeployer
{
    return new class($clientFactory) extends CtcccloudIcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunIcdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('天翼云 ICDN：证书服务型（storeKind ctcccloud_icdn + 元信息 + schema）', function () {
    $deployer = new CtcccloudIcdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('ctcccloud_icdn');
    expect($deployer->provider())->toBe('ctcccloud');
    expect($deployer->product())->toBe('icdn');
    expect($deployer->label())->toBe('天翼云国际 CDN');
    expect(array_column($deployer->configSchema(), 'key'))->toBe(['domain']);
});

test('uploader.upload 走 ICDN endpoint 创建证书 /v1/cert/creat-cert 返回 CertName', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['statusCode' => '100000', 'returnObj' => ['id' => 9]];
    });

    $deployer = ctyunIcdnDeployerWith(fn (string $kind) => $kind === 'icdn' ? $client : new stdClass);
    $certName = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ctyunIcdnCreds());

    expect($certName)->toStartWith('clouddeploy-');
    expect($captured['path'])->toBe('/v1/cert/creat-cert');
});

test('bind：query-domain-detail + update-domain（https_status=on + cert_name）', function () {
    $getCall = null;
    $postCall = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query) use (&$getCall) {
        $getCall = compact('path', 'query');

        return ['statusCode' => '100000', 'returnObj' => []];
    });
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$postCall) {
        $postCall = compact('path', 'body');

        return ['statusCode' => '100000'];
    });

    $deployer = ctyunIcdnDeployerWith(fn () => $client);
    $deployer->bind('icdn-cert-1', ctyunIcdnCreds(), ['domain' => 'i.example.com']);

    expect($getCall['path'])->toBe('/v1/domain/query-domain-detail');
    expect($getCall['query']['domain'])->toBe('i.example.com');
    expect($postCall['path'])->toBe('/v1/domain/update-domain');
    expect($postCall['body'])->toBe([
        'domain' => 'i.example.com',
        'https_status' => 'on',
        'cert_name' => 'icdn-cert-1',
    ]);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = ctyunIcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ctyunIcdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛网络类异常时脱敏只暴露类名、无凭证', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->andThrow(new RuntimeException('cURL error https://icdn-global.ctapi.ctyun.cn/?x=1 AK-LEAK'));

    $deployer = ctyunIcdnDeployerWith(fn () => $client);
    try {
        $deployer->bind('cert-1', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('天翼云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('icdn-global');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudApiException;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cdn）。 */
function ctyunCdnDeployerWith(callable $clientFactory): CtcccloudCdnDeployer
{
    return new class($clientFactory) extends CtcccloudCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunCdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('天翼云 CDN：证书服务型（usesRemoteCertStore + storeKind ctcccloud_cdn + 元信息 + schema）', function () {
    $deployer = new CtcccloudCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('ctcccloud_cdn');
    expect($deployer->provider())->toBe('ctcccloud');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('天翼云 CDN');
    expect(array_column($deployer->configSchema(), 'key'))->toBe(['domain']);
});

test('uploader.upload 创建证书到 CDN 证书空间，返回 CertName（不返回 id 则报错）', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $body) use (&$captured) {
            $captured = compact('path', 'body');

            return ['statusCode' => '100000', 'returnObj' => ['id' => 123]];
        });

    $deployer = ctyunCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $certName = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ctyunCdnCreds());

    expect($certName)->toStartWith('clouddeploy-');
    expect($captured['path'])->toBe('/v1/cert/creat-cert');
    // 证书本体 + 中间证书拼完整链；私钥 trim
    expect($captured['body']['certs'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['key'])->toBe('KEYPEM');
    expect($captured['body']['name'])->toBe($certName);
});

test('uploader.upload 缺 returnObj.id 抛错', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturn(['statusCode' => '100000', 'returnObj' => []]);

    $deployer = ctyunCdnDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ctyunCdnCreds()))
        ->toThrow(RuntimeException::class, '未返回 id');
});

test('bind：query-domain-detail 确认域名 + update-domain 绑 cert_name + https_status=on', function () {
    $getCall = null;
    $postCall = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query) use (&$getCall) {
        $getCall = compact('path', 'query');

        return ['statusCode' => '100000', 'returnObj' => ['domain' => 'cdn.example.com']];
    });
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$postCall) {
        $postCall = compact('path', 'body');

        return ['statusCode' => '100000'];
    });

    $deployer = ctyunCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $deployer->bind('clouddeploy-cert-1', ctyunCdnCreds(), ['domain' => 'cdn.example.com']);

    expect($getCall['path'])->toBe('/v1/domain/query-domain-detail');
    expect($getCall['query']['domain'])->toBe('cdn.example.com');
    expect($postCall['path'])->toBe('/v1/domain/update-domain');
    expect($postCall['body'])->toBe([
        'domain' => 'cdn.example.com',
        'https_status' => 'on',
        'cert_name' => 'clouddeploy-cert-1',
    ]);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = ctyunCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ctyunCdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 CtcccloudApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->andThrow(new CtcccloudApiException('800001', 'domain not found'));

    $deployer = ctyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('cert-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('800001')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->andThrow(new RuntimeException('cURL error 7: Failed to connect https://ctcdn-global.ctapi.ctyun.cn/?x=1'));

    $deployer = ctyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('cert-1', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('天翼云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('ctcdn-global');
        expect($e->getPrevious())->toBeNull();
    }
});

test('uploader.upload SDK 抛异常脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new CtcccloudApiException('CertExist', 'cert already exists'));

    $deployer = ctyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('CertExist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

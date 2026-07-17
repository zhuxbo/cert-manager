<?php

use Plugins\CloudDeploy\Deployers\Dogecloud\DogecloudApiException;
use Plugins\CloudDeploy\Deployers\Dogecloud\DogecloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Dogecloud\DogecloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cdn）。
 * uploader 经 certUploader() 复用同一 makeClient('cdn')，故 mock cdn 即覆盖上传 + 绑定路径。
 */
function dogecloudCdnDeployerWith(callable $clientFactory): DogecloudCdnDeployer
{
    return new class($clientFactory) extends DogecloudCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function dogecloudCreds(): array
{
    return ['access_key' => 'AK', 'secret_key' => 'SK'];
}

test('多吉云 CDN：证书服务型（usesRemoteCertStore + storeKind dogecloud + 元信息）', function () {
    $deployer = new DogecloudCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('dogecloud');
    expect($deployer->provider())->toBe('dogecloud');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('多吉云 CDN');
});

test('uploader.upload 调 POST /cdn/cert/upload.json 返回证书 id（cert=完整链, private=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(DogecloudRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $body) use (&$captured) {
            $captured = compact('path', 'body');

            return ['code' => 200, 'data' => ['id' => 88001]];
        });

    $deployer = dogecloudCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', dogecloudCreds());

    expect($id)->toBe('88001');
    expect($captured['path'])->toBe('/cdn/cert/upload.json');
    expect($captured['body']['note'])->toStartWith('clouddeploy_');
    expect($captured['body']['cert'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['private'])->toBe('KEYPEM');
});

test('upload 未返回证书 id 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(DogecloudRestClient::class);
    $client->shouldReceive('post')->andReturn(['code' => 200, 'data' => []]);

    $deployer = dogecloudCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', dogecloudCreds()))
        ->toThrow(RuntimeException::class, '证书 id');
});

test('bind：POST /cdn/cert/bind.json 把证书 id（int64）绑定到 exact 域名', function () {
    $captured = null;
    $client = Mockery::mock(DogecloudRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $body) use (&$captured) {
            $captured = compact('path', 'body');

            return ['code' => 200];
        });

    $deployer = dogecloudCdnDeployerWith(fn () => $client);
    $deployer->bind('88001', dogecloudCreds(), ['domain' => 'cdn.example.com']);

    expect($captured['path'])->toBe('/cdn/cert/bind.json');
    // id 转 int64（多吉云 BindCdnCert 收数字 id）
    expect($captured['body']['id'])->toBe(88001);
    expect($captured['body']['domain'])->toBe('cdn.example.com');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = dogecloudCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('88001', dogecloudCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 DogecloudApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(DogecloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new DogecloudApiException('4003', 'domain not found'));

    $deployer = dogecloudCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('1', ['access_key' => 'AK-SECRET-XYZ', 'secret_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('4003')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(DogecloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect https://api.dogecloud.com/cdn/cert/bind.json',
    ));

    $deployer = dogecloudCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('1', ['access_key' => 'AK-LEAK', 'secret_key' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('多吉云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('api.dogecloud.com');
        expect($e->getPrevious())->toBeNull();
    }
});

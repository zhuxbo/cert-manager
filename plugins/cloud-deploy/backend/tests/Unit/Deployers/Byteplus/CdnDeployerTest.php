<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApiException;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusCdnDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('cdn')，故 mock 即覆盖上传 + 绑定路径。
 */
function byteplusCdnDeployerWith(callable $clientFactory): BytePlusCdnDeployer
{
    return new class($clientFactory) extends BytePlusCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function byteplusCdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus CDN：证书服务型（storeKind=byteplus_cdn）', function () {
    $deployer = new BytePlusCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_cdn');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('BytePlus CDN');
});

test('uploader.upload 调 CDN AddCertificate（source=cert_center）返回 CertId', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')
        ->once()
        ->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body) use (&$captured) {
            $captured = compact('method', 'action', 'version', 'body');

            return (object) ['CertId' => 'cdn-cert-001'];
        });

    $deployer = byteplusCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', byteplusCdnCreds());

    expect($ref)->toBe('cdn-cert-001');
    expect($captured['method'])->toBe('POST');
    expect($captured['action'])->toBe('AddCertificate');
    expect($captured['version'])->toBe('2021-03-01');
    expect($captured['body']['Source'])->toBe('cert_center');
    expect($captured['body']['Certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['PrivateKey'])->toBe('KEYPEM');
    expect($captured['body']['Desc'])->toStartWith('clouddeploy_');
});

test('upload 未返回 CertId 抛明确异常', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturn(new stdClass);

    $deployer = byteplusCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', byteplusCdnCreds()))
        ->toThrow(RuntimeException::class, 'CertId');
});

test('bind 调 BatchDeployCert（CertId + Domain）', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')
        ->once()
        ->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body) use (&$captured) {
            $captured = compact('method', 'action', 'version', 'body');

            return new stdClass;
        });

    $deployer = byteplusCdnDeployerWith(fn () => $client);
    $deployer->bind('cdn-cert-001', byteplusCdnCreds(), ['domain' => 'cdn.example.com']);

    expect($captured['action'])->toBe('BatchDeployCert');
    expect($captured['version'])->toBe('2021-03-01');
    expect($captured['body'])->toBe(['CertId' => 'cdn-cert-001', 'Domain' => 'cdn.example.com']);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = byteplusCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', byteplusCdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 BytePlusApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andThrow(new BytePlusApiException('CertNotFound', 'cert not found'));

    $deployer = byteplusCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('CertNotFound')->toContain('cert not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类异常时脱敏只暴露类名（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andThrow(new RuntimeException(
        'cURL error 7: connect open.byteplusapi.com with AK-LEAK-9999',
    ));

    $deployer = byteplusCdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'secret_access_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999');
        expect($e->getMessage())->toContain('BytePlus 调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

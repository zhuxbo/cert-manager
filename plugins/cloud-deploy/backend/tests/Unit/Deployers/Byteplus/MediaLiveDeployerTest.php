<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApiException;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusMediaLiveDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

function byteplusMediaLiveDeployerWith(callable $clientFactory): BytePlusMediaLiveDeployer
{
    return new class($clientFactory) extends BytePlusMediaLiveDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function byteplusMlCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus 视频直播：证书服务型（storeKind=byteplus_medialive）', function () {
    $deployer = new BytePlusMediaLiveDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_medialive');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('medialive');
});

test('uploader.upload 调直播 CreateCert（Rsa:{Prikey,Pubkey}, UseWay=https）返回 ChainID', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')
        ->once()
        ->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body) use (&$captured) {
            $captured = compact('action', 'version', 'body');

            return (object) ['ChainID' => 'chain-001'];
        });

    $deployer = byteplusMediaLiveDeployerWith(fn (string $kind) => $kind === 'live' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', byteplusMlCreds());

    expect($ref)->toBe('chain-001');
    expect($captured['action'])->toBe('CreateCert');
    expect($captured['version'])->toBe('2023-01-01');
    expect($captured['body']['Rsa']['Prikey'])->toBe('KEYPEM');
    expect($captured['body']['Rsa']['Pubkey'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['UseWay'])->toBe('https');
    expect($captured['body']['CertName'])->toStartWith('clouddeploy_');
});

test('upload 未返回 ChainID 抛明确异常', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturn(new stdClass);

    $deployer = byteplusMediaLiveDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', byteplusMlCreds()))
        ->toThrow(RuntimeException::class, 'ChainID');
});

test('bind 调 BindCert（ChainID + Domain + HTTPS=true）', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')
        ->once()
        ->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body) use (&$captured) {
            $captured = compact('action', 'version', 'body');

            return new stdClass;
        });

    $deployer = byteplusMediaLiveDeployerWith(fn () => $client);
    $deployer->bind('chain-001', byteplusMlCreds(), ['domain' => 'live.example.com']);

    expect($captured['action'])->toBe('BindCert');
    expect($captured['version'])->toBe('2023-01-01');
    expect($captured['body'])->toBe(['ChainID' => 'chain-001', 'Domain' => 'live.example.com', 'HTTPS' => true]);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = byteplusMediaLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', byteplusMlCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 BytePlusApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andThrow(new BytePlusApiException('DomainNotFound', 'no domain'));

    $deployer = byteplusMediaLiveDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DomainNotFound');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Qiniu\QiniuApiException;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuPiliDeployer;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuRestClient;
use Tests\TestCase;

uses(TestCase::class);

function qiniuPiliDeployerWith(callable $clientFactory): QiniuPiliDeployer
{
    return new class($clientFactory) extends QiniuPiliDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('七牛云 Pili 走证书服务（storeKind=qiniu）', function () {
    $deployer = new QiniuPiliDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('qiniu');
    expect($deployer->provider())->toBe('qiniu');
    expect($deployer->product())->toBe('pili');
});

test('uploader.upload 上传返回复合 remote_cert_id', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('uploadSslCert')->once()->andReturn('pilicert-5');

    $deployer = qiniuPiliDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key' => 'AK', 'secret_key' => 'SK']);

    expect($ref)->toStartWith('pilicert-5|clouddeploy_');
});

test('bind 用 certName（非 certID）调 setPiliDomainCert（POST /v2/hubs/{hub}/domains/{domain}/cert）', function () {
    $captured = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('setPiliDomainCert')
        ->once()
        ->andReturnUsing(function (string $hub, string $domain, string $certName) use (&$captured) {
            $captured = compact('hub', 'domain', 'certName');
        });

    $deployer = qiniuPiliDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    // 复合串：certID=pilicert-5，certName=clouddeploy_777；Pili 必须用 certName
    $deployer->bind('pilicert-5|clouddeploy_777', ['access_key' => 'AK', 'secret_key' => 'SK'], ['hub' => 'myhub', 'domain' => 'live.example.com']);

    expect($captured)->toBe(['hub' => 'myhub', 'domain' => 'live.example.com', 'certName' => 'clouddeploy_777']);
});

test('缺 hub 配置抛业务错误', function () {
    $deployer = qiniuPiliDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c|n', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'live.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 hub');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = qiniuPiliDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c|n', ['access_key' => 'AK', 'secret_key' => 'SK'], ['hub' => 'myhub']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 收到无效 remote_cert_id 抛业务错误', function () {
    $deployer = qiniuPiliDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('nopipe', ['access_key' => 'AK', 'secret_key' => 'SK'], ['hub' => 'myhub', 'domain' => 'live.example.com']))
        ->toThrow(RuntimeException::class, '七牛 remote_cert_id');
});

test('bind SDK 抛 QiniuApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('setPiliDomainCert')->andThrow(new QiniuApiException('614', 'hub not found'));

    $deployer = qiniuPiliDeployerWith(fn () => $client);

    try {
        $deployer->bind('c|n', ['access_key' => 'AK-LEAK-PILI', 'secret_key' => 'SK-LEAK-PILI'], ['hub' => 'h', 'domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('614')->toContain('hub not found');
        expect($e->getMessage())->not->toContain('AK-LEAK-PILI')->not->toContain('SK-LEAK-PILI');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-PILI')->not->toContain('SK-LEAK-PILI');
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Qiniu\QiniuApiException;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuKodoDeployer;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuRestClient;
use Tests\TestCase;

uses(TestCase::class);

function qiniuKodoDeployerWith(callable $clientFactory): QiniuKodoDeployer
{
    return new class($clientFactory) extends QiniuKodoDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('七牛云 Kodo 走证书服务（storeKind=qiniu）', function () {
    $deployer = new QiniuKodoDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('qiniu');
    expect($deployer->provider())->toBe('qiniu');
    expect($deployer->product())->toBe('kodo');
});

test('uploader.upload 上传返回复合 remote_cert_id', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('uploadSslCert')->once()->andReturn('kodocert-9');

    $deployer = qiniuKodoDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key' => 'AK', 'secret_key' => 'SK']);

    expect($ref)->toStartWith('kodocert-9|clouddeploy_');
});

test('bind 用 certId 调 bindKodoBucketCert（PUT /cert/bind）', function () {
    $captured = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('bindKodoBucketCert')
        ->once()
        ->andReturnUsing(function (string $domain, string $certId) use (&$captured) {
            $captured = compact('domain', 'certId');
        });

    $deployer = qiniuKodoDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $deployer->bind('kodocert-9|clouddeploy_123', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'static.example.com', 'bucket' => 'mybucket']);

    // Kodo 用 certID（复合串左段），不用 certName
    expect($captured)->toBe(['domain' => 'static.example.com', 'certId' => 'kodocert-9']);
});

test('bucket 为可选字段，缺省也能 bind（bind 不消费 bucket）', function () {
    $captured = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('bindKodoBucketCert')
        ->once()
        ->andReturnUsing(function (string $domain, string $certId) use (&$captured) {
            $captured = compact('domain', 'certId');
        });

    $deployer = qiniuKodoDeployerWith(fn () => $client);
    $deployer->bind('kodocert-9|clouddeploy_1', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'static.example.com']);

    expect($captured)->toBe(['domain' => 'static.example.com', 'certId' => 'kodocert-9']);
    // bucket 不在 touchedConfigKeys（未 requireConfig）
    expect($deployer->touchedConfigKeys())->toBe(['domain']);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = qiniuKodoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c|n', ['access_key' => 'AK', 'secret_key' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 收到无效 remote_cert_id 抛业务错误', function () {
    $deployer = qiniuKodoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('no-pipe', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '七牛 remote_cert_id');
});

test('bind SDK 抛 QiniuApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('bindKodoBucketCert')->andThrow(new QiniuApiException('612', 'no such domain'));

    $deployer = qiniuKodoDeployerWith(fn () => $client);

    try {
        $deployer->bind('c|n', ['access_key' => 'AK-LEAK-KODO', 'secret_key' => 'SK-LEAK-KODO'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('612')->toContain('no such domain');
        expect($e->getMessage())->not->toContain('AK-LEAK-KODO')->not->toContain('SK-LEAK-KODO');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-KODO')->not->toContain('SK-LEAK-KODO');
    }
});

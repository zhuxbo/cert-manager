<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUpdateDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUploader;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UpdateCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\UpdateCertificateInstanceResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（ssl kind）。 */
function tencentSslUpdateDeployerWith(callable $clientFactory): TencentSslUpdateDeployer
{
    return new class($clientFactory) extends TencentSslUpdateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function sslUpdateResponse(): UpdateCertificateInstanceResponse
{
    $resp = new UpdateCertificateInstanceResponse;
    $resp->deserialize(['DeployRecordId' => 1, 'DeployStatus' => 1, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云 SSL 一键更新走证书服务（storeKind=tencent_ssl）+ 元信息', function () {
    $deployer = new TencentSslUpdateDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('ssl-update');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(TencentSslUploader::class);
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('certificate_id')->toContain('resource_products')->toContain('resource_regions');
});

test('bind 调 UpdateCertificateInstance（OldCertificateId 旧、CertificateId 新、ResourceTypes）', function () {
    $req = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturnUsing(function (UpdateCertificateInstanceRequest $r) use (&$req) {
        $req = $r;

        return sslUpdateResponse();
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind('new-cert-id', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old-cert-id',
        'resource_products' => ['cdn', 'live'],
    ]);

    expect($req->OldCertificateId)->toBe('old-cert-id');
    expect($req->CertificateId)->toBe('new-cert-id');
    expect($req->ResourceTypes)->toBe(['cdn', 'live']);
});

test('需要地域的产品（clb/waf）构造 ResourceTypesRegions，不需要的（cdn）不带', function () {
    $req = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturnUsing(function (UpdateCertificateInstanceRequest $r) use (&$req) {
        $req = $r;

        return sslUpdateResponse();
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind('new-cert-id', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old-cert-id',
        'resource_products' => 'cdn,clb,waf',
        'resource_regions' => 'ap-guangzhou,ap-shanghai',
    ]);

    // cdn 不在白名单 → 不出现在 ResourceTypesRegions；clb/waf 各带两个地域
    $regions = $req->ResourceTypesRegions;
    expect($regions)->toHaveCount(2);
    $byType = [];
    foreach ($regions as $entry) {
        $byType[$entry->ResourceType] = $entry->Regions;
    }
    expect($byType)->toHaveKey('clb')->toHaveKey('waf');
    expect($byType)->not->toHaveKey('cdn');
    expect($byType['clb'])->toBe(['ap-guangzhou', 'ap-shanghai']);
});

test('无地域时不带 ResourceTypesRegions', function () {
    $req = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturnUsing(function (UpdateCertificateInstanceRequest $r) use (&$req) {
        $req = $r;

        return sslUpdateResponse();
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind('new-cert-id', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old-cert-id',
        'resource_products' => 'clb',
    ]);

    expect($req->ResourceTypesRegions)->toBeNull();
});

test('缺 certificate_id / resource_products 抛业务错误', function () {
    $deployer = tencentSslUpdateDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('new', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_products' => 'cdn',
    ]))->toThrow(RuntimeException::class, '缺少配置 certificate_id');

    expect(fn () => $deployer->bind('new', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old',
    ]))->toThrow(RuntimeException::class, '缺少配置 resource_products');
});

test('bind SDK 抛异常时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->andThrow(new TencentCloudSDKException('FailedOperation', 'update failed', 'req-1'));

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->bind('new', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'certificate_id' => 'old', 'resource_products' => 'cdn',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});

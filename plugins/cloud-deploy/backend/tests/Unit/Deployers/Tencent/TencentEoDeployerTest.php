<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentEoDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateResponse;
use TencentCloud\Teo\V20220901\TeoClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/teo）。 */
function tencentEoDeployerWith(callable $clientFactory): TencentEoDeployer
{
    return new class($clientFactory) extends TencentEoDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function eoUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云 EdgeOne 走证书服务（storeKind=tencent_ssl）', function () {
    $deployer = new TencentEoDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('eo');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('EO uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => eoUploadCertResponse('cert-eo'));

    $deployer = tencentEoDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-eo');
});

test('EO bind 用 certId 调 teo.ModifyHostsCertificate 设 ZoneId/Mode/Hosts/ServerCertInfo.CertId', function () {
    $captured = null;
    $teo = Mockery::mock(TeoClient::class);
    $teo->shouldReceive('ModifyHostsCertificate')
        ->once()
        ->andReturnUsing(function (ModifyHostsCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new ModifyHostsCertificateResponse;
        });

    $deployer = tencentEoDeployerWith(fn (string $kind) => $kind === 'teo' ? $teo : new stdClass);
    $deployer->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['zone_id' => 'zone-1', 'domain' => 'eo.example.com']);

    expect($captured->ZoneId)->toBe('zone-1');
    expect($captured->Mode)->toBe('sslcert');
    expect($captured->Hosts)->toBe(['eo.example.com']);
    expect($captured->ServerCertInfo)->toHaveCount(1);
    expect($captured->ServerCertInfo[0]->CertId)->toBe('cert-eo');
});

test('EO 缺 zone_id 配置抛业务错误', function () {
    $deployer = tencentEoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['domain' => 'eo.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 zone_id');
});

test('EO 缺 domain 配置抛业务错误', function () {
    $deployer = tencentEoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['zone_id' => 'zone-1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('EO bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $teo = Mockery::mock(TeoClient::class);
    $teo->shouldReceive('ModifyHostsCertificate')->andThrow(new TencentCloudSDKException('ResourceNotFound.Zone', 'zone missing', 'req-1'));

    $deployer = tencentEoDeployerWith(fn () => $teo);

    try {
        $deployer->bind('cert-eo', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], ['zone_id' => 'zone-1', 'domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ResourceNotFound.Zone')->toContain('zone missing');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

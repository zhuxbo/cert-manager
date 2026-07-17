<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentCdnDeployer;
use TencentCloud\Cdn\V20180606\CdnClient;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigRequest;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigResponse;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/cdn）。
 * uploader 经 certUploader() 复用同一 makeClient('ssl')，故 mock ssl 即覆盖上传路径。
 */
function tencentCdnDeployerWith(callable $clientFactory): TencentCdnDeployer
{
    return new class($clientFactory) extends TencentCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function uploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云 CDN 走证书服务', function () {
    $deployer = new TencentCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $captured = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(function (UploadCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return uploadCertResponse('cert-x');
        });

    $deployer = tencentCdnDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-x');
    expect($captured->CertificatePublicKey)->toContain('CERT')->toContain('CHAIN');
    expect($captured->CertificatePrivateKey)->toBe('KEY');
    expect($captured->CertificateType)->toBe('SVR');
});

test('upload 未返回 CertificateId 时抛明确异常（非 TypeError）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->andReturn(uploadCertResponse(''));

    $deployer = tencentCdnDeployerWith(fn () => $ssl);

    expect(fn () => $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']))
        ->toThrow(RuntimeException::class, 'CertificateId');
});

test('bind 用 certId 调 cdn.UpdateDomainConfig 设 Https.CertInfo.CertId', function () {
    $captured = null;
    $cdn = Mockery::mock(CdnClient::class);
    $cdn->shouldReceive('UpdateDomainConfig')
        ->once()
        ->andReturnUsing(function (UpdateDomainConfigRequest $req) use (&$captured) {
            $captured = $req;

            return new UpdateDomainConfigResponse;
        });

    $deployer = tencentCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $cdn : new stdClass);
    $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['domain' => 'cdn.example.com']);

    expect($captured->Domain)->toBe('cdn.example.com');
    expect($captured->Https->Switch)->toBe('on');
    expect($captured->Https->CertInfo->CertId)->toBe('cert-x');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = tencentCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $cdn = Mockery::mock(CdnClient::class);
    $cdn->shouldReceive('UpdateDomainConfig')->andThrow(new TencentCloudSDKException('InvalidParameter', 'domain not found', 'req-1'));

    $deployer = tencentCdnDeployerWith(fn () => $cdn);

    try {
        $deployer->bind('cert-x', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidParameter')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

test('upload SDK 抛异常时脱敏重抛（无 AK/SK）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->andThrow(new TencentCloudSDKException('AuthFailure', 'signature expired', 'req-2'));

    $deployer = tencentCdnDeployerWith(fn () => $ssl);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['secret_id' => 'ID-LEAK-2', 'secret_key' => 'KEY-LEAK-2']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AuthFailure')->toContain('signature expired');
        expect($e->getMessage())->not->toContain('ID-LEAK-2')->not->toContain('KEY-LEAK-2');
        expect($e->getPrevious())->toBeNull();
    }
});

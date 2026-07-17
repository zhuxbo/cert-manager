<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentEcdnDeployer;
use TencentCloud\Cdn\V20180606\CdnClient;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigRequest;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigResponse;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/cdn）。ECDN 复用 CDN SDK。 */
function tencentEcdnDeployerWith(callable $clientFactory): TencentEcdnDeployer
{
    return new class($clientFactory) extends TencentEcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ecdnUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云 ECDN 走证书服务（storeKind=tencent_ssl）', function () {
    $deployer = new TencentEcdnDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('ecdn');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('ECDN uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $captured = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(function (UploadCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return ecdnUploadCertResponse('cert-ecdn');
        });

    $deployer = tencentEcdnDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-ecdn');
    expect($captured->CertificatePublicKey)->toContain('CERT')->toContain('CHAIN');
    expect($captured->CertificatePrivateKey)->toBe('KEY');
    expect($captured->CertificateType)->toBe('SVR');
});

test('ECDN bind 用 certId 调 cdn.UpdateDomainConfig 设 Https.CertInfo.CertId', function () {
    $captured = null;
    $cdn = Mockery::mock(CdnClient::class);
    $cdn->shouldReceive('UpdateDomainConfig')
        ->once()
        ->andReturnUsing(function (UpdateDomainConfigRequest $req) use (&$captured) {
            $captured = $req;

            return new UpdateDomainConfigResponse;
        });

    $deployer = tencentEcdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $cdn : new stdClass);
    $deployer->bind('cert-ecdn', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['domain' => 'ecdn.example.com']);

    expect($captured->Domain)->toBe('ecdn.example.com');
    expect($captured->Https->Switch)->toBe('on');
    expect($captured->Https->CertInfo->CertId)->toBe('cert-ecdn');
});

test('ECDN 缺 domain 配置抛业务错误', function () {
    $deployer = tencentEcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-ecdn', ['secret_id' => 'AK', 'secret_key' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('ECDN bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $cdn = Mockery::mock(CdnClient::class);
    $cdn->shouldReceive('UpdateDomainConfig')->andThrow(new TencentCloudSDKException('InvalidParameter', 'domain not found', 'req-1'));

    $deployer = tencentEcdnDeployerWith(fn () => $cdn);

    try {
        $deployer->bind('cert-ecdn', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidParameter')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

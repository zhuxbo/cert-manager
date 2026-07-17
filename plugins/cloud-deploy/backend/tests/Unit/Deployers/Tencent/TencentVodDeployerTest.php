<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentVodDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Vod\V20180717\Models\SetVodDomainCertificateRequest;
use TencentCloud\Vod\V20180717\Models\SetVodDomainCertificateResponse;
use TencentCloud\Vod\V20180717\VodClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/vod）。 */
function tencentVodDeployerWith(callable $clientFactory): TencentVodDeployer
{
    return new class($clientFactory) extends TencentVodDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function vodUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云点播 VOD 走证书服务（storeKind=tencent_ssl）', function () {
    $deployer = new TencentVodDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('vod');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('VOD uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => vodUploadCertResponse('cert-vod'));

    $deployer = tencentVodDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-vod');
});

test('VOD bind 用 certId 调 vod.SetVodDomainCertificate 设 Domain/Operation/CertID（大写 ID）', function () {
    $captured = null;
    $vod = Mockery::mock(VodClient::class);
    $vod->shouldReceive('SetVodDomainCertificate')
        ->once()
        ->andReturnUsing(function (SetVodDomainCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new SetVodDomainCertificateResponse;
        });

    $deployer = tencentVodDeployerWith(fn (string $kind) => $kind === 'vod' ? $vod : new stdClass);
    $deployer->bind('cert-vod', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['domain' => 'vod.example.com']);

    expect($captured->Domain)->toBe('vod.example.com');
    expect($captured->Operation)->toBe('Set');
    // 腾讯点播官方字段为大写 CertID（区别于 cdn 的 CertId），断言其确实被填充
    expect($captured->CertID)->toBe('cert-vod');
});

test('VOD 缺 domain 配置抛业务错误', function () {
    $deployer = tencentVodDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-vod', ['secret_id' => 'AK', 'secret_key' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('VOD bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $vod = Mockery::mock(VodClient::class);
    $vod->shouldReceive('SetVodDomainCertificate')->andThrow(new TencentCloudSDKException('FailedOperation', 'set cert failed', 'req-1'));

    $deployer = tencentVodDeployerWith(fn () => $vod);

    try {
        $deployer->bind('cert-vod', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('set cert failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

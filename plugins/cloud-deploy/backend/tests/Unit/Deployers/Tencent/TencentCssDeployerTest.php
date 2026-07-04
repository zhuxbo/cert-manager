<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentCssDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Live\V20180801\LiveClient;
use TencentCloud\Live\V20180801\Models\ModifyLiveDomainCertBindingsRequest;
use TencentCloud\Live\V20180801\Models\ModifyLiveDomainCertBindingsResponse;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/live）。 */
function tencentCssDeployerWith(callable $clientFactory): TencentCssDeployer
{
    return new class($clientFactory) extends TencentCssDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function cssUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云直播 CSS 走证书服务（storeKind=tencent_ssl）', function () {
    $deployer = new TencentCssDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('css');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('CSS uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => cssUploadCertResponse('cert-css'));

    $deployer = tencentCssDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-css');
});

test('CSS bind 用 certId 调 live.ModifyLiveDomainCertBindings 设 CloudCertId + DomainInfos', function () {
    $captured = null;
    $live = Mockery::mock(LiveClient::class);
    $live->shouldReceive('ModifyLiveDomainCertBindings')
        ->once()
        ->andReturnUsing(function (ModifyLiveDomainCertBindingsRequest $req) use (&$captured) {
            $captured = $req;

            return new ModifyLiveDomainCertBindingsResponse;
        });

    $deployer = tencentCssDeployerWith(fn (string $kind) => $kind === 'live' ? $live : new stdClass);
    $deployer->bind('cert-css', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['domain' => 'live.example.com']);

    expect($captured->CloudCertId)->toBe('cert-css');
    expect($captured->DomainInfos)->toHaveCount(1);
    expect($captured->DomainInfos[0]->DomainName)->toBe('live.example.com');
    expect($captured->DomainInfos[0]->Status)->toBe(1);
});

test('CSS 缺 domain 配置抛业务错误', function () {
    $deployer = tencentCssDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-css', ['secret_id' => 'AK', 'secret_key' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('CSS bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $live = Mockery::mock(LiveClient::class);
    $live->shouldReceive('ModifyLiveDomainCertBindings')->andThrow(new TencentCloudSDKException('InvalidParameter.DomainNotExist', 'domain not exist', 'req-1'));

    $deployer = tencentCssDeployerWith(fn () => $live);

    try {
        $deployer->bind('cert-css', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidParameter.DomainNotExist')->toContain('domain not exist');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

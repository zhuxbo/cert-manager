<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentGaapDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Gaap\V20180529\GaapClient;
use TencentCloud\Gaap\V20180529\Models\DescribeHTTPSListenersRequest;
use TencentCloud\Gaap\V20180529\Models\DescribeHTTPSListenersResponse;
use TencentCloud\Gaap\V20180529\Models\ModifyHTTPSListenerAttributeRequest;
use TencentCloud\Gaap\V20180529\Models\ModifyHTTPSListenerAttributeResponse;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（ssl/gaap kind）。 */
function tencentGaapDeployerWith(callable $clientFactory): TencentGaapDeployer
{
    return new class($clientFactory) extends TencentGaapDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function gaapUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

/** $count 个 HTTPS 监听器（空元素即可，deployer 仅判 ListenerSet 是否非空）。 */
function gaapListenersResponse(int $count): DescribeHTTPSListenersResponse
{
    $resp = new DescribeHTTPSListenersResponse;
    $resp->deserialize([
        'RequestId' => 'r',
        'TotalCount' => $count,
        'ListenerSet' => array_fill(0, $count, []),
    ]);

    return $resp;
}

test('腾讯云 GAAP 走证书服务（storeKind=tencent_ssl）+ 基本元信息', function () {
    $deployer = new TencentGaapDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('gaap');
    expect($deployer->label())->toBe('腾讯云 GAAP');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('listener_id')->toContain('proxy_id');
});

test('GAAP uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => gaapUploadCertResponse('cert-gaap'));

    $deployer = tencentGaapDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-gaap');
});

test('GAAP bind 先 DescribeHTTPSListeners 校验监听器，再 ModifyHTTPSListenerAttribute 设 ListenerId/CertificateId/ProxyId', function () {
    $descReq = null;
    $modReq = null;
    $gaap = Mockery::mock(GaapClient::class);
    $gaap->shouldReceive('DescribeHTTPSListeners')
        ->once()
        ->andReturnUsing(function (DescribeHTTPSListenersRequest $req) use (&$descReq) {
            $descReq = $req;

            return gaapListenersResponse(1);
        });
    $gaap->shouldReceive('ModifyHTTPSListenerAttribute')
        ->once()
        ->andReturnUsing(function (ModifyHTTPSListenerAttributeRequest $req) use (&$modReq) {
            $modReq = $req;

            return new ModifyHTTPSListenerAttributeResponse;
        });

    $deployer = tencentGaapDeployerWith(fn (string $kind) => $kind === 'gaap' ? $gaap : new stdClass);
    $deployer->bind('cert-gaap', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'listener_id' => 'listener-1',
        'proxy_id' => 'proxy-1',
    ]);

    // Describe 用 ListenerId 过滤
    expect($descReq->ListenerId)->toBe('listener-1');
    // Modify 设 ListenerId + CertificateId + ProxyId（常规驼峰）
    expect($modReq->ListenerId)->toBe('listener-1');
    expect($modReq->CertificateId)->toBe('cert-gaap');
    expect($modReq->ProxyId)->toBe('proxy-1');
});

test('GAAP bind proxy_id 选填：未传时不下发 ProxyId', function () {
    $modReq = null;
    $gaap = Mockery::mock(GaapClient::class);
    $gaap->shouldReceive('DescribeHTTPSListeners')->once()->andReturn(gaapListenersResponse(1));
    $gaap->shouldReceive('ModifyHTTPSListenerAttribute')
        ->once()
        ->andReturnUsing(function (ModifyHTTPSListenerAttributeRequest $req) use (&$modReq) {
            $modReq = $req;

            return new ModifyHTTPSListenerAttributeResponse;
        });

    $deployer = tencentGaapDeployerWith(fn (string $kind) => $kind === 'gaap' ? $gaap : new stdClass);
    $deployer->bind('cert-gaap', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'listener_id' => 'listener-1',
    ]);

    expect($modReq->ListenerId)->toBe('listener-1');
    expect($modReq->CertificateId)->toBe('cert-gaap');
    // 未传 proxy_id → ProxyId 保持 null（deserialize 未设）
    expect($modReq->ProxyId)->toBeNull();
});

test('GAAP bind 监听器不存在（ListenerSet 空）抛业务错误', function () {
    $gaap = Mockery::mock(GaapClient::class);
    $gaap->shouldReceive('DescribeHTTPSListeners')->once()->andReturn(gaapListenersResponse(0));
    // 不应调用 Modify
    $gaap->shouldNotReceive('ModifyHTTPSListenerAttribute');

    $deployer = tencentGaapDeployerWith(fn (string $kind) => $kind === 'gaap' ? $gaap : new stdClass);
    expect(fn () => $deployer->bind('cert-gaap', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'listener_id' => 'listener-missing',
    ]))->toThrow(RuntimeException::class, '未找到 HTTPS 监听器 listener-missing');
});

test('GAAP 缺 listener_id 配置抛业务错误', function () {
    $deployer = tencentGaapDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'proxy_id' => 'proxy-1',
    ]))->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('GAAP bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $gaap = Mockery::mock(GaapClient::class);
    $gaap->shouldReceive('DescribeHTTPSListeners')->andReturn(gaapListenersResponse(1));
    $gaap->shouldReceive('ModifyHTTPSListenerAttribute')
        ->andThrow(new TencentCloudSDKException('FailedOperation', 'modify listener failed', 'req-1'));

    $deployer = tencentGaapDeployerWith(fn () => $gaap);

    try {
        $deployer->bind('cert-gaap', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'listener_id' => 'listener-1', 'proxy_id' => 'proxy-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('modify listener failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

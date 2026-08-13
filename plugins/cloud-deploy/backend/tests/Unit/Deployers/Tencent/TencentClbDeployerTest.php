<?php

use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Tencent\TencentClbDeployer;
use TencentCloud\Clb\V20180317\ClbClient;
use TencentCloud\Clb\V20180317\Models\DescribeListenersRequest;
use TencentCloud\Clb\V20180317\Models\DescribeListenersResponse;
use TencentCloud\Clb\V20180317\Models\DescribeTaskStatusRequest;
use TencentCloud\Clb\V20180317\Models\DescribeTaskStatusResponse;
use TencentCloud\Clb\V20180317\Models\ModifyDomainAttributesRequest;
use TencentCloud\Clb\V20180317\Models\ModifyDomainAttributesResponse;
use TencentCloud\Clb\V20180317\Models\ModifyListenerRequest;
use TencentCloud\Clb\V20180317\Models\ModifyListenerResponse;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/clb）。
 * 注：makeClient 带第三个 region 参数（clb 按 region 实例化），闭包按需接收。
 */
function tencentClbDeployerWith(callable $clientFactory): TencentClbDeployer
{
    return new class($clientFactory) extends TencentClbDeployer
    {
        public function __construct(private $factory) {}

        public function fastPoll(): void
        {
            $this->maxPollAttempts = 1;
            $this->resumePollAttempts = 1;
            $this->pollIntervalSeconds = 0;
        }

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function clbUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

function clbDescribeListenersResponse(string $listenerId, string $sslMode = 'UNIDIRECTIONAL', ?string $certCaId = null): DescribeListenersResponse
{
    $response = new DescribeListenersResponse;
    $certificate = ['SSLMode' => $sslMode];
    if ($certCaId !== null) {
        $certificate['CertCaId'] = $certCaId;
    }
    $response->deserialize(['Listeners' => [[
        'ListenerId' => $listenerId,
        'Certificate' => $certificate,
    ]]]);

    return $response;
}

test('腾讯云 CLB 走证书服务（storeKind=tencent_ssl）+ 基本元信息', function () {
    $deployer = new TencentClbDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('clb');
    expect($deployer->label())->toBe('腾讯云 CLB');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    // configSchema 覆盖 bind 实际读取的三项
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('load_balancer_id')->toContain('listener_id')->toContain('region');
});

test('CLB uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => clbUploadCertResponse('cert-clb'));

    $deployer = tencentClbDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-clb');
});

test('CLB bind 用 certId 调 clb.ModifyListener 设 LoadBalancerId/ListenerId/Certificate(SSLMode+CertId)', function () {
    $captured = null;
    $clb = Mockery::mock(ClbClient::class);
    $clb->shouldReceive('DescribeListeners')->once()->andReturn(clbDescribeListenersResponse('lbl-xyz'));
    $clb->shouldReceive('ModifyListener')
        ->once()
        ->andReturnUsing(function (ModifyListenerRequest $req) use (&$captured) {
            $captured = $req;

            return new ModifyListenerResponse;
        });

    $deployer = tencentClbDeployerWith(fn (string $kind) => $kind === 'clb' ? $clb : new stdClass);
    $deployer->bind('cert-clb', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'load_balancer_id' => 'lb-abc',
        'listener_id' => 'lbl-xyz',
        'region' => 'ap-guangzhou',
    ]);

    expect($captured->LoadBalancerId)->toBe('lb-abc');
    expect($captured->ListenerId)->toBe('lbl-xyz');
    // Certificate 子结构：SSLMode 大写 SSL + CertId
    expect($captured->Certificate->SSLMode)->toBe('UNIDIRECTIONAL');
    expect($captured->Certificate->CertId)->toBe('cert-clb');
});

test('CLB bind 保留监听器现有双向认证模式与 CA 证书', function () {
    $captured = null;
    $clb = Mockery::mock(ClbClient::class);
    $clb->shouldReceive('DescribeListeners')->once()->andReturnUsing(function (DescribeListenersRequest $request) {
        expect($request->LoadBalancerId)->toBe('lb-abc');
        expect($request->ListenerIds)->toBe(['lbl-xyz']);
        $response = new DescribeListenersResponse;
        $response->deserialize(['Listeners' => [[
            'ListenerId' => 'lbl-xyz',
            'Certificate' => ['SSLMode' => 'MUTUAL', 'CertCaId' => 'ca-old'],
        ]]]);

        return $response;
    });
    $clb->shouldReceive('ModifyListener')->once()->andReturnUsing(function (ModifyListenerRequest $request) use (&$captured) {
        $captured = $request;

        return new ModifyListenerResponse;
    });

    $deployer = tencentClbDeployerWith(fn (string $kind) => $kind === 'clb' ? $clb : new stdClass);
    $deployer->bind('cert-clb', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'load_balancer_id' => 'lb-abc',
        'listener_id' => 'lbl-xyz',
        'region' => 'ap-guangzhou',
    ]);

    expect($captured->Certificate->SSLMode)->toBe('MUTUAL');
    expect($captured->Certificate->CertCaId)->toBe('ca-old');
    expect($captured->Certificate->CertId)->toBe('cert-clb');
});

test('CLB loadbalancer target 分批更新全部 HTTPS TCP_SSL QUIC 监听器并可续查剩余进度', function () {
    $modified = [];
    $clb = Mockery::mock(ClbClient::class);
    $all = new DescribeListenersResponse;
    $all->deserialize(['Listeners' => [
        ['ListenerId' => 'lbl-https', 'Protocol' => 'HTTPS'],
        ['ListenerId' => 'lbl-http', 'Protocol' => 'HTTP'],
        ['ListenerId' => 'lbl-quic', 'Protocol' => 'QUIC'],
    ]]);
    $clb->shouldReceive('DescribeListeners')->once()->andReturn($all);
    $clb->shouldReceive('DescribeListeners')->once()->andReturn(clbDescribeListenersResponse('lbl-https'));
    $clb->shouldReceive('DescribeListeners')->once()->andReturn(clbDescribeListenersResponse('lbl-quic'));
    $clb->shouldReceive('ModifyListener')->twice()->andReturnUsing(function (ModifyListenerRequest $request) use (&$modified) {
        $modified[] = $request->ListenerId;

        return new ModifyListenerResponse;
    });

    $deployer = tencentClbDeployerWith(fn (string $kind) => $kind === 'clb' ? $clb : new stdClass);
    $deployer->fastPoll();
    try {
        $deployer->bind('cert-clb', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
            'deploy_target' => 'loadbalancer',
            'load_balancer_id' => 'lb-abc',
            'region' => 'ap-guangzhou',
        ]);
        expect(false)->toBeTrue('存在剩余监听器时应持久化续查状态');
    } catch (DeployPollPendingException $e) {
        $deployer->resumePoll($e->remoteJobId, ['secret_id' => 'AK', 'secret_key' => 'SK'], [
            'deploy_target' => 'loadbalancer',
            'load_balancer_id' => 'lb-abc',
            'region' => 'ap-guangzhou',
        ]);
    }

    expect($modified)->toBe(['lbl-https', 'lbl-quic']);
});

test('CLB ruledomain target 调 ModifyDomainAttributes 并轮询 DescribeTaskStatus', function () {
    $captured = null;
    $clb = Mockery::mock(ClbClient::class);
    $modifyResponse = new ModifyDomainAttributesResponse;
    $modifyResponse->deserialize(['RequestId' => 'task-rule-1']);
    $statusResponse = new DescribeTaskStatusResponse;
    $statusResponse->deserialize(['Status' => 0]);
    $clb->shouldReceive('ModifyDomainAttributes')->once()->andReturnUsing(function (ModifyDomainAttributesRequest $request) use (&$captured, $modifyResponse) {
        $captured = $request;

        return $modifyResponse;
    });
    $clb->shouldReceive('DescribeTaskStatus')->once()->andReturnUsing(function (DescribeTaskStatusRequest $request) use ($statusResponse) {
        expect($request->TaskId)->toBe('task-rule-1');

        return $statusResponse;
    });

    $deployer = tencentClbDeployerWith(fn (string $kind) => $kind === 'clb' ? $clb : new stdClass);
    $deployer->fastPoll();
    $deployer->bind('cert-clb', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'deploy_target' => 'ruledomain',
        'load_balancer_id' => 'lb-abc',
        'listener_id' => 'lbl-https',
        'domain' => '*.example.com',
        'region' => 'ap-guangzhou',
    ]);

    expect($captured->LoadBalancerId)->toBe('lb-abc');
    expect($captured->ListenerId)->toBe('lbl-https');
    expect($captured->Domain)->toBe('*.example.com');
    expect($captured->Certificate->CertId)->toBe('cert-clb');
});

test('CLB bind 把 region 透传进 clb client（按 region 实例化）', function () {
    $seenRegion = null;
    $clb = Mockery::mock(ClbClient::class);
    $clb->shouldReceive('DescribeListeners')->once()->andReturn(clbDescribeListenersResponse('lbl-x'));
    $clb->shouldReceive('ModifyListener')->andReturn(new ModifyListenerResponse);

    $deployer = tencentClbDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $clb) {
        if ($kind === 'clb') {
            $seenRegion = $region;

            return $clb;
        }

        return new stdClass;
    });
    $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'load_balancer_id' => 'lb-x',
        'listener_id' => 'lbl-x',
        'region' => 'ap-singapore',
    ]);

    expect($seenRegion)->toBe('ap-singapore');
});

test('CLB 缺 load_balancer_id 配置抛业务错误', function () {
    $deployer = tencentClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'listener_id' => 'lbl-x', 'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 load_balancer_id');
});

test('CLB 缺 listener_id 配置抛业务错误', function () {
    $deployer = tencentClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'load_balancer_id' => 'lb-x', 'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('CLB 缺 region 配置抛业务错误（region 必填，client 构造需要）', function () {
    $deployer = tencentClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'load_balancer_id' => 'lb-x', 'listener_id' => 'lbl-x',
    ]))->toThrow(RuntimeException::class, '缺少配置 region');
});

test('CLB bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $clb = Mockery::mock(ClbClient::class);
    $clb->shouldReceive('DescribeListeners')->once()->andReturn(clbDescribeListenersResponse('lbl-x'));
    $clb->shouldReceive('ModifyListener')->andThrow(new TencentCloudSDKException('FailedOperation', 'modify listener failed', 'req-1'));

    $deployer = tencentClbDeployerWith(fn () => $clb);

    try {
        $deployer->bind('cert-clb', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'load_balancer_id' => 'lb-x', 'listener_id' => 'lbl-x', 'region' => 'ap-guangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('modify listener failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

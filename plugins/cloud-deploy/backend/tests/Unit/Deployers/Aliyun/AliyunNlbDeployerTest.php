<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\SDK\Nlb\V20220430\Models\GetLoadBalancerAttributeRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Models\ListListenersRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Models\UpdateListenerAttributeRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Models\UpdateListenerAttributeResponse;
use AlibabaCloud\SDK\Nlb\V20220430\Nlb;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunNlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/nlb）。
 * bind 调 makeClient('nlb', $cred, $region) 带 region 第三参，闭包按需接收。
 */
function aliyunNlbDeployerWith(callable $clientFactory): AliyunNlbDeployer
{
    return new class($clientFactory) extends AliyunNlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function nlbCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function nlbCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

test('阿里云 NLB 走证书服务（CAS）+ 基本元信息', function () {
    $deployer = new AliyunNlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('nlb');
    expect($deployer->label())->toBe('阿里云 NLB');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region')->toContain('deploy_target')->toContain('load_balancer_id')->toContain('listener_id');
});

test('bind loadbalancer：GetLoadBalancerAttribute 后分页列 TCPSSL 监听并批量更新', function () {
    $listReq = null;
    $updated = [];
    $nlb = Mockery::mock(Nlb::class);
    $nlb->shouldReceive('getLoadBalancerAttribute')->once()->with(Mockery::on(fn (GetLoadBalancerAttributeRequest $r) => $r->loadBalancerId === 'nlb-1'))->andReturn(new stdClass);
    $nlb->shouldReceive('listListeners')->once()->andReturnUsing(function (ListListenersRequest $req) use (&$listReq) {
        $listReq = $req;

        return (object) ['body' => (object) ['listeners' => [
            (object) ['listenerId' => 'lsn-1'],
            (object) ['listenerId' => 'lsn-2'],
        ]]];
    });
    $nlb->shouldReceive('updateListenerAttribute')->twice()->andReturnUsing(function (UpdateListenerAttributeRequest $req) use (&$updated) {
        $updated[] = $req->listenerId;

        return new UpdateListenerAttributeResponse;
    });

    $deployer = aliyunNlbDeployerWith(fn (string $kind) => $kind === 'nlb' ? $nlb : new stdClass);
    $deployer->bind('cert-1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-hangzhou',
        'deploy_target' => 'loadbalancer',
        'load_balancer_id' => 'nlb-1',
    ]);

    expect($listReq->loadBalancerIds)->toBe(['nlb-1']);
    expect($listReq->listenerProtocol)->toBe('TCPSSL');
    expect($listReq->maxResults)->toBe(100);
    expect($updated)->toBe(['lsn-1', 'lsn-2']);
});

test('uploader.upload 调 cas.UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return nlbCasUploadResponse(333444);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return nlbCasDetailResponse('333444-cn-shanghai');
        });

    $deployer = aliyunNlbDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('333444-cn-shanghai');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(333444);
    expect($detailReq->certFilter)->toBeTrue();
});

test('bind 用完整 CertIdentifier 调 nlb.UpdateListenerAttribute（扁平 CertificateIds 数组）', function () {
    $captured = null;
    $nlb = Mockery::mock(Nlb::class);
    $nlb->shouldReceive('updateListenerAttribute')
        ->once()
        ->andReturnUsing(function (UpdateListenerAttributeRequest $req) use (&$captured) {
            $captured = $req;

            return new UpdateListenerAttributeResponse;
        });

    $deployer = aliyunNlbDeployerWith(fn (string $kind) => $kind === 'nlb' ? $nlb : new stdClass);
    $deployer->bind('555666-cn-shanghai', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-shanghai',
        'listener_id' => 'lsn-nlbxyz',
    ]);

    expect($captured->listenerId)->toBe('lsn-nlbxyz');
    // NLB 是扁平 string 数组，元素为完整 CertIdentifier（不拆）
    expect($captured->certificateIds)->toBe(['555666-cn-shanghai']);
});

test('bind 把 region 透传进 nlb client endpoint（按 region 实例化）', function () {
    $seenRegion = null;
    $nlb = Mockery::mock(Nlb::class);
    $nlb->shouldReceive('updateListenerAttribute')->andReturn(new UpdateListenerAttributeResponse);

    $deployer = aliyunNlbDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $nlb) {
        if ($kind === 'nlb') {
            $seenRegion = $region;

            return $nlb;
        }

        return new stdClass;
    });
    $deployer->bind('1-us-east-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'us-east-1',
        'listener_id' => 'lsn-x',
    ]);

    expect($seenRegion)->toBe('us-east-1');
});

test('缺 region 配置抛业务错误', function () {
    $deployer = aliyunNlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['listener_id' => 'lsn-x']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('缺 listener_id 配置抛业务错误', function () {
    $deployer = aliyunNlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['region' => 'cn-hangzhou']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛 TeaError（API 错误）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $nlb = Mockery::mock(Nlb::class);
    $nlb->shouldReceive('updateListenerAttribute')->andThrow(new TeaError([
        'code' => 'ResourceNotFound.Listener',
        'message' => 'code: 404 request id: req-2',
        'data' => ['Code' => 'ResourceNotFound.Listener', 'Message' => 'the specified listener is not found', 'RequestId' => 'req-2'],
    ]));

    $deployer = aliyunNlbDeployerWith(fn (string $kind) => $kind === 'nlb' ? $nlb : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'region' => 'cn-hangzhou',
            'listener_id' => 'lsn-x',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ResourceNotFound.Listener')->toContain('the specified listener is not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $nlb = Mockery::mock(Nlb::class);
    $nlb->shouldReceive('updateListenerAttribute')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://nlb.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunNlbDeployerWith(fn (string $kind) => $kind === 'nlb' ? $nlb : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], [
            'region' => 'cn-hangzhou',
            'listener_id' => 'lsn-x',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

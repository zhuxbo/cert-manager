<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\SDK\Ga\V20191120\Ga;
use AlibabaCloud\SDK\Ga\V20191120\Models\ListListenerCertificatesRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\ListListenersRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateAdditionalCertificateWithListenerRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateListenerRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateListenerResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunGaDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/ga）。
 * GA 无 region 维度，makeClient 仍是两参（与基类签名一致）。
 */
function aliyunGaDeployerWith(callable $clientFactory): AliyunGaDeployer
{
    return new class($clientFactory) extends AliyunGaDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function gaCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function gaCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

test('阿里云 GA 走证书服务（CAS）+ 基本元信息', function () {
    $deployer = new AliyunGaDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('ga');
    expect($deployer->label())->toBe('阿里云全球加速');
    // GA 无 region；config 为 accelerator_id + listener_id
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('deploy_target')->toContain('accelerator_id')->toContain('listener_id')->toContain('domain');
    expect($keys)->not->toContain('region');
});

test('bind accelerator：分页列 HTTPS 监听并批量更新', function () {
    $listReq = null;
    $updated = [];
    $ga = Mockery::mock(Ga::class);
    $ga->shouldReceive('listListeners')->once()->andReturnUsing(function (ListListenersRequest $req) use (&$listReq) {
        $listReq = $req;

        return (object) ['body' => (object) ['listeners' => [
            (object) ['listenerId' => 'https-1', 'protocol' => 'HTTPS'],
            (object) ['listenerId' => 'http-1', 'protocol' => 'HTTP'],
            (object) ['listenerId' => 'https-2', 'protocol' => 'https'],
        ]]];
    });
    $ga->shouldReceive('updateListener')->twice()->andReturnUsing(function (UpdateListenerRequest $req) use (&$updated) {
        $updated[] = $req->listenerId;

        return new UpdateListenerResponse;
    });

    $deployer = aliyunGaDeployerWith(fn (string $kind) => $kind === 'ga' ? $ga : new stdClass);
    $deployer->bind('cert-1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'deploy_target' => 'accelerator',
        'accelerator_id' => 'ga-1',
    ]);

    expect($listReq->regionId)->toBe('cn-hangzhou');
    expect($listReq->acceleratorId)->toBe('ga-1');
    expect($listReq->pageNumber)->toBe(1);
    expect($listReq->pageSize)->toBe(50);
    expect($updated)->toBe(['https-1', 'https-2']);
});

test('bind SNI：同 domain 已有扩展证书时调用 UpdateAdditionalCertificateWithListener 替换', function () {
    $captured = null;
    $ga = Mockery::mock(Ga::class);
    $ga->shouldReceive('listListenerCertificates')->once()->with(Mockery::on(fn (ListListenerCertificatesRequest $r) => $r->regionId === 'cn-hangzhou' && $r->acceleratorId === 'ga-1' && $r->listenerId === 'lsr-1' && $r->maxResults === 20))->andReturn((object) ['body' => (object) ['certificates' => [
        (object) ['certificateId' => 'old-cert', 'isDefault' => false, 'domain' => 'api.example.com'],
    ]]]);
    $ga->shouldReceive('updateAdditionalCertificateWithListener')->once()->andReturnUsing(function (UpdateAdditionalCertificateWithListenerRequest $req) use (&$captured) {
        $captured = $req;

        return new stdClass;
    });

    aliyunGaDeployerWith(fn () => $ga)->bind('new-cert', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'accelerator_id' => 'ga-1', 'listener_id' => 'lsr-1', 'domain' => 'api.example.com',
    ]);

    expect($captured->regionId)->toBe('cn-hangzhou');
    expect($captured->acceleratorId)->toBe('ga-1');
    expect($captured->listenerId)->toBe('lsr-1');
    expect($captured->certificateId)->toBe('new-cert');
    expect($captured->domain)->toBe('api.example.com');
});

test('uploader.upload 调 cas.UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return gaCasUploadResponse(777888);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return gaCasDetailResponse('777888-cn-hangzhou');
        });

    $deployer = aliyunGaDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('777888-cn-hangzhou');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(777888);
    expect($detailReq->certFilter)->toBeTrue();
});

test('bind 用完整 CertIdentifier 调 ga.UpdateListener（Certificates[].Id + RegionId 固定 cn-hangzhou）', function () {
    $captured = null;
    $ga = Mockery::mock(Ga::class);
    $ga->shouldReceive('updateListener')
        ->once()
        ->andReturnUsing(function (UpdateListenerRequest $req) use (&$captured) {
            $captured = $req;

            return new UpdateListenerResponse;
        });

    $deployer = aliyunGaDeployerWith(fn (string $kind) => $kind === 'ga' ? $ga : new stdClass);
    $deployer->bind('999000-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'accelerator_id' => 'ga-acc-123',
        'listener_id' => 'lsr-ga-xyz',
    ]);

    expect($captured->regionId)->toBe('cn-hangzhou');
    expect($captured->listenerId)->toBe('lsr-ga-xyz');
    expect($captured->certificates)->toHaveCount(1);
    // GA 嵌套对象键名是 id（非 alb 的 certificateId），值为完整 CertIdentifier
    expect($captured->certificates[0]->id)->toBe('999000-cn-hangzhou');
});

test('缺 accelerator_id 配置抛业务错误', function () {
    $deployer = aliyunGaDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['listener_id' => 'lsr-x']))
        ->toThrow(RuntimeException::class, '缺少配置 accelerator_id');
});

test('缺 listener_id 配置抛业务错误', function () {
    $deployer = aliyunGaDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['accelerator_id' => 'ga-acc-1']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛 TeaError（API 错误）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $ga = Mockery::mock(Ga::class);
    $ga->shouldReceive('updateListener')->andThrow(new TeaError([
        'code' => 'NotExist.Listener',
        'message' => 'code: 404 request id: req-3',
        'data' => ['Code' => 'NotExist.Listener', 'Message' => 'the listener does not exist', 'RequestId' => 'req-3'],
    ]));

    $deployer = aliyunGaDeployerWith(fn (string $kind) => $kind === 'ga' ? $ga : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'accelerator_id' => 'ga-acc-1',
            'listener_id' => 'lsr-x',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NotExist.Listener')->toContain('the listener does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $ga = Mockery::mock(Ga::class);
    $ga->shouldReceive('updateListener')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://ga.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunGaDeployerWith(fn (string $kind) => $kind === 'ga' ? $ga : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], [
            'accelerator_id' => 'ga-acc-1',
            'listener_id' => 'lsr-x',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

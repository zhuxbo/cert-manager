<?php

use AlibabaCloud\SDK\Alb\V20200616\Alb;
use AlibabaCloud\SDK\Alb\V20200616\Models\AssociateAdditionalCertificatesWithListenerRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\DissociateAdditionalCertificatesFromListenerRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\GetListenerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\GetLoadBalancerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\ListListenerCertificatesRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\ListListenersRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\UpdateListenerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\UpdateListenerAttributeResponse;
use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunAlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/alb）。
 * uploader 经 certUploader() 复用同一 makeClient('cas')，故 mock cas 即覆盖上传路径。
 * 注：bind 调 makeClient('alb', $cred, $region) 带第三个 region 参数，闭包按需接收（忽略亦可）。
 */
function aliyunAlbDeployerWith(callable $clientFactory): AliyunAlbDeployer
{
    return new class($clientFactory) extends AliyunAlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function albCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function albCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

function aliyunAlbCertificateWithSans(): string
{
    $conf = sys_get_temp_dir().'/alialb_san_'.uniqid('', true).'.cnf';
    file_put_contents($conf, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:api.example.com,DNS:*.example.com\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'api.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3']);
    openssl_x509_export($cert, $pem);
    @unlink($conf);

    return $pem;
}

test('阿里云 ALB 走证书服务（CAS）+ 基本元信息', function () {
    $deployer = new AliyunAlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('alb');
    expect($deployer->label())->toBe('阿里云 ALB');
    // configSchema 覆盖 bind 实际读取的 region + listener_id
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region')->toContain('deploy_target')->toContain('load_balancer_id')->toContain('listener_id')->toContain('domain');
});

test('bind loadbalancer：列 HTTPS 与 QUIC 监听并批量更新', function () {
    $protocols = [];
    $updated = [];
    $alb = Mockery::mock(Alb::class);
    $alb->shouldReceive('getLoadBalancerAttribute')->once()->with(Mockery::on(fn (GetLoadBalancerAttributeRequest $r) => $r->loadBalancerId === 'alb-1'))->andReturn(new stdClass);
    $alb->shouldReceive('listListeners')->twice()->andReturnUsing(function (ListListenersRequest $req) use (&$protocols) {
        $protocols[] = $req->listenerProtocol;

        return (object) ['body' => (object) ['listeners' => [
            (object) ['listenerId' => strtolower((string) $req->listenerProtocol).'-1'],
        ]]];
    });
    $alb->shouldReceive('updateListenerAttribute')->twice()->andReturnUsing(function (UpdateListenerAttributeRequest $req) use (&$updated) {
        $updated[] = $req->listenerId;

        return new UpdateListenerAttributeResponse;
    });

    $deployer = aliyunAlbDeployerWith(fn (string $kind) => $kind === 'alb' ? $alb : new stdClass);
    $deployer->bind('cert-1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-hangzhou', 'deploy_target' => 'loadbalancer', 'load_balancer_id' => 'alb-1',
    ]);

    expect($protocols)->toBe(['HTTPS', 'QUIC']);
    expect($updated)->toBe(['https-1', 'quic-1']);
});

test('bind SNI：关联新证书并解除同 SAN 的旧扩展证书', function () {
    $associated = null;
    $dissociated = null;
    $alb = Mockery::mock(Alb::class);
    $alb->shouldReceive('listListenerCertificates')->once()->with(Mockery::on(fn (ListListenerCertificatesRequest $r) => $r->listenerId === 'lsr-1' && $r->certificateType === 'Server' && $r->maxResults === 100))->andReturn((object) ['body' => (object) ['certificates' => [
        (object) ['certificateId' => '111-cn-hangzhou', 'isDefault' => false, 'certificateType' => 'Server', 'status' => 'Associated'],
    ]]]);
    $alb->shouldReceive('getListenerAttribute')->twice()->with(Mockery::type(GetListenerAttributeRequest::class))->andReturn((object) ['body' => (object) ['listenerStatus' => 'Active']]);
    $alb->shouldReceive('associateAdditionalCertificatesWithListener')->once()->andReturnUsing(function (AssociateAdditionalCertificatesWithListenerRequest $req) use (&$associated) {
        $associated = $req;

        return new stdClass;
    });
    $alb->shouldReceive('dissociateAdditionalCertificatesFromListener')->once()->andReturnUsing(function (DissociateAdditionalCertificatesFromListenerRequest $req) use (&$dissociated) {
        $dissociated = $req;

        return new stdClass;
    });
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('getCertificateDetail')->once()->andReturn((object) ['body' => (object) [
        'domain' => 'api.example.com,*.example.com',
        'notAfter' => (time() + 86400) * 1000,
    ]]);

    $deployer = aliyunAlbDeployerWith(fn (string $kind) => $kind === 'alb' ? $alb : $cas);
    $deployer->bind([
        'remote_cert_id' => '222-cn-hangzhou',
        'cert' => aliyunAlbCertificateWithSans(),
        'chain' => '',
    ], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-hangzhou', 'listener_id' => 'lsr-1', 'domain' => 'api.example.com',
    ]);

    expect($associated->certificates[0]->certificateId)->toBe('222-cn-hangzhou');
    expect($dissociated->certificates[0]->certificateId)->toBe('111-cn-hangzhou');
});

test('uploader.upload 调 cas.UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return albCasUploadResponse(111222);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return albCasDetailResponse('111222-cn-hangzhou');
        });

    $deployer = aliyunAlbDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('111222-cn-hangzhou');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(111222);
    expect($detailReq->certFilter)->toBeTrue();
});

test('bind 用完整 CertIdentifier（不拆）调 alb.UpdateListenerAttribute（Certificates[].CertificateId）', function () {
    $captured = null;
    $alb = Mockery::mock(Alb::class);
    $alb->shouldReceive('updateListenerAttribute')
        ->once()
        ->andReturnUsing(function (UpdateListenerAttributeRequest $req) use (&$captured) {
            $captured = $req;

            return new UpdateListenerAttributeResponse;
        });

    $deployer = aliyunAlbDeployerWith(fn (string $kind) => $kind === 'alb' ? $alb : new stdClass);
    $deployer->bind('987654-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-hangzhou',
        'listener_id' => 'lsr-abc123',
    ]);

    expect($captured->listenerId)->toBe('lsr-abc123');
    expect($captured->certificates)->toHaveCount(1);
    // 关键：传完整 CertIdentifier（含 region 段），不拆成 bare int
    expect($captured->certificates[0]->certificateId)->toBe('987654-cn-hangzhou');
});

test('bind 把 region 透传进 alb client endpoint（按 region 实例化）', function () {
    $seenRegion = null;
    $alb = Mockery::mock(Alb::class);
    $alb->shouldReceive('updateListenerAttribute')->andReturn(new UpdateListenerAttributeResponse);

    $deployer = aliyunAlbDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $alb) {
        if ($kind === 'alb') {
            $seenRegion = $region;

            return $alb;
        }

        return new stdClass;
    });
    $deployer->bind('1-ap-southeast-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'ap-southeast-1',
        'listener_id' => 'lsr-x',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
});

test('缺 region 配置抛业务错误', function () {
    $deployer = aliyunAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['listener_id' => 'lsr-x']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('缺 listener_id 配置抛业务错误', function () {
    $deployer = aliyunAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['region' => 'cn-hangzhou']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛 TeaError（API 错误）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $alb = Mockery::mock(Alb::class);
    $alb->shouldReceive('updateListenerAttribute')->andThrow(new TeaError([
        'code' => 'InvalidListenerId.NotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'InvalidListenerId.NotFound', 'Message' => 'the listener does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunAlbDeployerWith(fn (string $kind) => $kind === 'alb' ? $alb : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'region' => 'cn-hangzhou',
            'listener_id' => 'lsr-x',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidListenerId.NotFound')->toContain('the listener does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $alb = Mockery::mock(Alb::class);
    $alb->shouldReceive('updateListenerAttribute')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://alb.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunAlbDeployerWith(fn (string $kind) => $kind === 'alb' ? $alb : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], [
            'region' => 'cn-hangzhou',
            'listener_id' => 'lsr-x',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyCloudResourceCertRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDefaultHttpsRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDefaultHttpsResponse;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDomainRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Wafopenapi;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunWafDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/waf）。
 * uploader 经 certUploader() 复用同一 makeClient('cas')，故 mock cas 即覆盖上传路径。
 * 注：bind 调 makeClient('waf', $cred, $region) 带第三个 region 参数。
 */
function aliyunWafDeployerWith(callable $clientFactory): AliyunWafDeployer
{
    return new class($clientFactory) extends AliyunWafDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function wafCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function wafCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

test('阿里云 WAF 走证书服务（CAS）+ 基本元信息', function () {
    $deployer = new AliyunWafDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('waf');
    expect($deployer->label())->toBe('阿里云 WAF');
    // configSchema 覆盖 bind 实际读取的 instance_id + region
    expect(array_column($deployer->configSchema(), 'key'))->toContain('instance_id')->toContain('region');
});

test('uploader.upload 调 cas.UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return wafCasUploadResponse(333444);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return wafCasDetailResponse('333444-cn-hangzhou');
        });

    $deployer = aliyunWafDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('333444-cn-hangzhou');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(333444);
    expect($detailReq->certFilter)->toBeTrue();
});

test('bind 用完整 CertIdentifier（不拆）调 waf.ModifyDefaultHttps（CertId + InstanceId + RegionId + TLS 默认）', function () {
    $captured = null;
    $waf = Mockery::mock(Wafopenapi::class);
    $waf->shouldReceive('describeDefaultHttps')->once()->andReturn((object) ['body' => (object) ['defaultHttps' => (object) [
        'TLSVersion' => 'tlsv1.1', 'enableTLSv3' => false,
    ]]]);
    $waf->shouldReceive('modifyDefaultHttps')
        ->once()
        ->andReturnUsing(function (ModifyDefaultHttpsRequest $req) use (&$captured) {
            $captured = $req;

            return new ModifyDefaultHttpsResponse;
        });

    $deployer = aliyunWafDeployerWith(fn (string $kind) => $kind === 'waf' ? $waf : new stdClass);
    $deployer->bind('987654-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'instance_id' => 'waf-cn-abc',
        'region' => 'cn-hangzhou',
    ]);

    expect($captured->instanceId)->toBe('waf-cn-abc');
    // 关键：传完整 CertIdentifier（含 region 段），不拆成 bare int（对齐 certimate WAF）
    expect($captured->certId)->toBe('987654-cn-hangzhou');
    expect($captured->regionId)->toBe('cn-hangzhou');
    expect($captured->TLSVersion)->toBe('tlsv1.1');
    // Certimate d418b5a 的条件只在返回 nil 时覆盖，因此非 nil false 仍保留种子 true。
    expect($captured->enableTLSv3)->toBeTrue();
});

test('bind cloudresource：替换同 CN 扩展证书并过滤过期和不存在证书', function () {
    $captured = null;
    $waf = Mockery::mock(Wafopenapi::class);
    $waf->shouldReceive('describeResourceInstanceCerts')->once()->andReturn((object) ['body' => (object) ['certs' => [
        (object) ['certIdentifier' => '111-cn-hangzhou', 'commonName' => 'api.example.com', 'afterDate' => (time() + 86400) * 1000],
        (object) ['certIdentifier' => '222-cn-hangzhou', 'commonName' => 'other.example.com', 'afterDate' => (time() + 86400) * 1000],
        (object) ['certIdentifier' => '333-cn-hangzhou', 'commonName' => 'expired.example.com', 'afterDate' => (time() - 86400) * 1000],
    ]]]);
    $waf->shouldReceive('describeCloudResourceAccessPortDetails')->once()->andReturn((object) ['body' => (object) ['accessPortDetails' => [
        (object) ['cloudResourceId' => 'cloud-res-1', 'certificates' => [
            (object) ['certificateId' => 'default-cert', 'appliedType' => 'default'],
            (object) ['certificateId' => '111-cn-hangzhou', 'appliedType' => 'extension'],
            (object) ['certificateId' => '222-cn-hangzhou', 'appliedType' => 'extension'],
            (object) ['certificateId' => '333-cn-hangzhou', 'appliedType' => 'extension'],
            (object) ['certificateId' => '444-cn-hangzhou', 'appliedType' => 'extension'],
        ]],
    ]]]);
    $waf->shouldReceive('modifyCloudResourceCert')->once()->andReturnUsing(function (ModifyCloudResourceCertRequest $req) use (&$captured) {
        $captured = $req;

        return new stdClass;
    });

    aliyunWafDeployerWith(fn () => $waf)->bind('new-cert', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_version' => '3.0', 'service_type' => 'cloudresource', 'instance_id' => 'waf-1', 'region' => 'cn-hangzhou',
        'resource_product' => 'clb4', 'resource_id' => 'lb-1', 'resource_port' => 443, 'domain' => 'api.example.com',
    ]);

    expect($captured->cloudResourceId)->toBe('cloud-res-1');
    expect(array_map(fn ($c) => [$c->certificateId, $c->appliedType], $captured->certificates))->toBe([
        ['222-cn-hangzhou', 'extension'], ['new-cert', 'extension'],
    ]);
});

test('bind CNAME 扩展域名：ModifyDomain 回填 Listen 与 Redirect 原配置', function () {
    $captured = null;
    $waf = Mockery::mock(Wafopenapi::class);
    $waf->shouldReceive('describeDomainDetail')->once()->andReturn((object) ['body' => (object) [
        'domainId' => 'd-1',
        'listen' => (object) ['TLSVersion' => 'tlsv1.1', 'http2Enabled' => true, 'httpPorts' => [80], 'httpsPorts' => [443]],
        'redirect' => (object) ['loadbalance' => 'roundRobin', 'connectTimeout' => 12, 'backends' => [(object) ['backend' => '1.2.3.4']]],
    ]]);
    $waf->shouldReceive('modifyDomain')->once()->andReturnUsing(function (ModifyDomainRequest $req) use (&$captured) {
        $captured = $req;

        return new stdClass;
    });

    aliyunWafDeployerWith(fn () => $waf)->bind('new-cert', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_version' => '3.0', 'service_type' => 'cname', 'instance_id' => 'waf-1', 'region' => 'cn-hangzhou',
        'domain' => 'api.example.com',
    ]);

    expect($captured->domainId)->toBe('d-1');
    expect($captured->listen->certId)->toBe('new-cert');
    expect($captured->listen->TLSVersion)->toBe('tlsv1.1');
    expect($captured->listen->httpPorts)->toBe([80]);
    expect($captured->redirect->loadbalance)->toBe('roundRobin');
    expect($captured->redirect->backends)->toBe(['1.2.3.4']);
});

test('bind 把 region 透传进 waf client endpoint（按 region 实例化）', function () {
    $seenRegion = null;
    $waf = Mockery::mock(Wafopenapi::class);
    $waf->shouldReceive('describeDefaultHttps')->andReturn((object) ['body' => null]);
    $waf->shouldReceive('modifyDefaultHttps')->andReturn(new ModifyDefaultHttpsResponse);

    $deployer = aliyunWafDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $waf) {
        if ($kind === 'waf') {
            $seenRegion = $region;

            return $waf;
        }

        return new stdClass;
    });
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'instance_id' => 'waf-x',
        'region' => 'ap-southeast-1',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
});

test('缺 instance_id 配置抛业务错误', function () {
    $deployer = aliyunWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['region' => 'cn-hangzhou']))
        ->toThrow(RuntimeException::class, '缺少配置 instance_id');
});

test('缺 region 配置抛业务错误', function () {
    $deployer = aliyunWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['instance_id' => 'waf-x']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('bind SDK 抛 TeaError（API 错误）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $waf = Mockery::mock(Wafopenapi::class);
    $waf->shouldReceive('describeDefaultHttps')->andReturn((object) ['body' => null]);
    $waf->shouldReceive('modifyDefaultHttps')->andThrow(new TeaError([
        'code' => 'InstanceNotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'InstanceNotFound', 'Message' => 'the waf instance does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunWafDeployerWith(fn (string $kind) => $kind === 'waf' ? $waf : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'instance_id' => 'waf-x', 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InstanceNotFound')->toContain('the waf instance does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://cas.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunWafDeployerWith(fn () => $cas);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

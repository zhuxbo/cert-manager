<?php

use AlibabaCloud\SDK\APIG\V20240327\APIG;
use AlibabaCloud\SDK\APIG\V20240327\Models\DomainInfo;
use AlibabaCloud\SDK\APIG\V20240327\Models\GetDomainRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\GetDomainResponse;
use AlibabaCloud\SDK\APIG\V20240327\Models\GetDomainResponseBody;
use AlibabaCloud\SDK\APIG\V20240327\Models\GetDomainResponseBody\data as GetDomainData;
use AlibabaCloud\SDK\APIG\V20240327\Models\ListDomainsRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\ListDomainsResponse;
use AlibabaCloud\SDK\APIG\V20240327\Models\ListDomainsResponseBody;
use AlibabaCloud\SDK\APIG\V20240327\Models\ListDomainsResponseBody\data as ListDomainsData;
use AlibabaCloud\SDK\APIG\V20240327\Models\UpdateDomainRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\UpdateDomainResponse;
use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunApigwDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/apig）。
 * uploader 经 certUploader() 复用同一 makeClient('cas')，故 mock cas 即覆盖上传路径。
 * makeClient('apig', $cred) 的 $cred 含 region（用于 endpoint，由 bind 透传）。
 */
function aliyunApigwDeployerWith(callable $clientFactory): AliyunApigwDeployer
{
    return new class($clientFactory) extends AliyunApigwDeployer
    {
        /** @var callable */
        public $seen;

        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            if (isset($this->seen)) {
                ($this->seen)($kind, $credentials);
            }

            return ($this->factory)($kind, $credentials);
        }
    };
}

function apigListDomainsResponse(array $items): ListDomainsResponse
{
    $domainInfos = array_map(
        fn (array $d) => new DomainInfo(['name' => $d['name'], 'domainId' => $d['id'], 'status' => $d['status'] ?? 'Published']),
        $items,
    );

    return new ListDomainsResponse(['body' => new ListDomainsResponseBody([
        'data' => new ListDomainsData(['items' => $domainInfos]),
    ])]);
}

function apigGetDomainResponse(array $data): GetDomainResponse
{
    return new GetDomainResponse(['body' => new GetDomainResponseBody([
        'data' => new GetDomainData($data),
    ])]);
}

function apigCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function apigCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

test('阿里云 API 网关走证书服务（CAS）+ 基本元信息 + configSchema 覆盖 bind 读取键', function () {
    $deployer = new AliyunApigwDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('apigw');
    expect($deployer->label())->toBe('阿里云 API 网关');
    // configSchema 覆盖 bind 实际读取的 gateway_id + domain（service_type/region 可选）
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('service_type')->toContain('gateway_id')->toContain('domain')->toContain('region');
});

test('uploader.upload 复用 CAS：UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return apigCasUploadResponse(778899);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return apigCasDetailResponse('778899-cn-hangzhou');
        });

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('778899-cn-hangzhou');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(778899);
});

test('bind cloudnative：ListDomains 精确找域名 ID → GetDomain 读 TLS → UpdateDomain 绑完整 CertIdentifier + 回填 TLS', function () {
    $listReq = null;
    $getDomainId = null;
    $updateDomainId = null;
    $updateReq = null;

    $apig = Mockery::mock(APIG::class);
    $apig->shouldReceive('listDomains')
        ->once()
        ->andReturnUsing(function (ListDomainsRequest $req) use (&$listReq) {
            $listReq = $req;

            // 候选含一个不匹配 + 目标（精确匹配 name，大小写不敏感）
            return apigListDomainsResponse([
                ['name' => 'other.example.com', 'id' => 'd-other'],
                ['name' => 'API.Example.com', 'id' => 'd-target'],
            ]);
        });
    $apig->shouldReceive('getDomain')
        ->once()
        ->andReturnUsing(function (string $domainId, GetDomainRequest $req) use (&$getDomainId) {
            $getDomainId = $domainId;

            return apigGetDomainResponse([
                'forceHttps' => true,
                'mTLSEnabled' => false,
                'http2Option' => 'Open',
                'tlsMin' => 'TLSv1.2',
                'tlsMax' => 'TLSv1.3',
                'certIdentifier' => 'old-cert-1-cn-hangzhou',
            ]);
        });
    $apig->shouldReceive('updateDomain')
        ->once()
        ->andReturnUsing(function (string $domainId, UpdateDomainRequest $req) use (&$updateDomainId, &$updateReq) {
            $updateDomainId = $domainId;
            $updateReq = $req;

            return new UpdateDomainResponse;
        });

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);
    $deployer->bind('445566-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_type' => 'cloudnative',
        'gateway_id' => 'gw-abc',
        'domain' => 'api.example.com',
    ]);

    // ListDomains 用 gatewayId + nameLike 缩小候选
    expect($listReq->gatewayId)->toBe('gw-abc');
    expect($listReq->nameLike)->toBe('api.example.com');
    // 精确匹配（大小写不敏感）命中 d-target，GetDomain/UpdateDomain 用该 domainId
    expect($getDomainId)->toBe('d-target');
    expect($updateDomainId)->toBe('d-target');
    // 关键：传完整 CertIdentifier（含 region 段），不拆成 bare int（对齐 certimate cloudnative）
    expect($updateReq->certIdentifier)->toBe('445566-cn-hangzhou');
    expect($updateReq->protocol)->toBe('HTTPS');
    // 回填 GetDomain 既有 TLS 配置（避免 UpdateDomain 全量覆盖重置）
    expect($updateReq->forceHttps)->toBeTrue();
    expect($updateReq->mTLSEnabled)->toBeFalse();
    expect($updateReq->http2Option)->toBe('Open');
    expect($updateReq->tlsMin)->toBe('TLSv1.2');
    expect($updateReq->tlsMax)->toBe('TLSv1.3');
});

test('bind 默认 service_type（缺省）按 cloudnative 走', function () {
    $apig = Mockery::mock(APIG::class);
    $apig->shouldReceive('listDomains')->once()->andReturn(apigListDomainsResponse([['name' => 'a.com', 'id' => 'd-1']]));
    $apig->shouldReceive('getDomain')->once()->andReturn(apigGetDomainResponse(['forceHttps' => false]));
    $apig->shouldReceive('updateDomain')->once()->andReturn(new UpdateDomainResponse);

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);
    // 不传 service_type，应不抛、正常走 cloudnative
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'gateway_id' => 'gw-1',
        'domain' => 'a.com',
    ]);

    expect(true)->toBeTrue();
});

test('bind 分页翻页找域名（第一页未命中，第二页命中）', function () {
    $pages = [];
    $apig = Mockery::mock(APIG::class);
    $apig->shouldReceive('listDomains')
        ->twice()
        ->andReturnUsing(function (ListDomainsRequest $req) use (&$pages) {
            $pages[] = $req->pageNumber;
            if ($req->pageNumber === 1) {
                // 满页（PAGE_SIZE=10）但都不匹配 → 触发翻页
                $items = [];
                for ($i = 0; $i < 10; $i++) {
                    $items[] = ['name' => "noise$i.com", 'id' => "d-$i"];
                }

                return apigListDomainsResponse($items);
            }

            return apigListDomainsResponse([['name' => 'target.com', 'id' => 'd-found']]);
        });
    $getId = null;
    $apig->shouldReceive('getDomain')->once()->andReturnUsing(function (string $id) use (&$getId) {
        $getId = $id;

        return apigGetDomainResponse(['forceHttps' => true]);
    });
    $apig->shouldReceive('updateDomain')->once()->andReturn(new UpdateDomainResponse);

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);
    $deployer->bind('9-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'gateway_id' => 'gw-1',
        'domain' => 'target.com',
    ]);

    expect($pages)->toBe([1, 2]);
    expect($getId)->toBe('d-found');
});

test('bind 域名未找到抛业务错误（不静默成功）', function () {
    $apig = Mockery::mock(APIG::class);
    // 未满页（<PAGE_SIZE）且无匹配 → 停止翻页、抛未找到
    $apig->shouldReceive('listDomains')->once()->andReturn(apigListDomainsResponse([['name' => 'nope.com', 'id' => 'd-x']]));
    $apig->shouldNotReceive('getDomain');
    $apig->shouldNotReceive('updateDomain');

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'gateway_id' => 'gw-1',
        'domain' => 'missing.example.com',
    ]))->toThrow(RuntimeException::class, '未找到域名 missing.example.com');
});

test('bind service_type=traditional 抛明确「暂未实现」业务错误（不走错栈、不调任何 SDK）', function () {
    $deployer = aliyunApigwDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_type' => 'traditional',
        'gateway_id' => 'gw-1',
        'domain' => 'a.com',
    ]))->toThrow(RuntimeException::class, '经典版（traditional）」暂未实现');
});

test('bind 未知 service_type 抛业务错误', function () {
    $deployer = aliyunApigwDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_type' => 'bogus',
        'gateway_id' => 'gw-1',
        'domain' => 'a.com',
    ]))->toThrow(RuntimeException::class, '不支持的 API 网关服务类型: bogus');
});

test('缺 gateway_id 配置抛业务错误', function () {
    $deployer = aliyunApigwDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'a.com']))
        ->toThrow(RuntimeException::class, '缺少配置 gateway_id');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunApigwDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['gateway_id' => 'gw-1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 把 region 透传进 apig client（endpoint 按 region 实例化）', function () {
    $seenRegion = null;
    $apig = Mockery::mock(APIG::class);
    $apig->shouldReceive('listDomains')->once()->andReturn(apigListDomainsResponse([['name' => 'a.com', 'id' => 'd-1']]));
    $apig->shouldReceive('getDomain')->once()->andReturn(apigGetDomainResponse(['forceHttps' => true]));
    $apig->shouldReceive('updateDomain')->once()->andReturn(new UpdateDomainResponse);

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);
    $deployer->seen = function (string $kind, array $cred) use (&$seenRegion) {
        if ($kind === 'apig') {
            $seenRegion = $cred['region'] ?? null;
        }
    };
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'gateway_id' => 'gw-1',
        'domain' => 'a.com',
        'region' => 'ap-southeast-1',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
});

test('bind SDK 抛 TeaError（API 错误）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $apig = Mockery::mock(APIG::class);
    $apig->shouldReceive('listDomains')->once()->andReturn(apigListDomainsResponse([['name' => 'a.com', 'id' => 'd-1']]));
    $apig->shouldReceive('getDomain')->once()->andReturn(apigGetDomainResponse(['forceHttps' => true]));
    $apig->shouldReceive('updateDomain')->andThrow(new TeaError([
        'code' => 'DomainNotFound',
        'message' => 'code: 404 request id: req-9',
        'data' => ['Code' => 'DomainNotFound', 'Message' => 'the domain does not exist', 'RequestId' => 'req-9'],
    ]));

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'gateway_id' => 'gw-1', 'domain' => 'a.com',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DomainNotFound')->toContain('the domain does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $apig = Mockery::mock(APIG::class);
    // listDomains 直接网络失败：errorInfo=[]、message 含签名 URI
    $apig->shouldReceive('listDomains')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://apig.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], [
            'gateway_id' => 'gw-1', 'domain' => 'a.com',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

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
use AlibabaCloud\SDK\CloudAPI\V20160714\CloudAPI;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\DescribeApiGroupRequest;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\DescribeApiGroupResponse;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\DescribeApiGroupResponseBody;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\DescribeApiGroupResponseBody\customDomains;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\DescribeApiGroupResponseBody\customDomains\domainItem;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\SetDomainCertificateRequest;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\SetDomainCertificateResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunApigwDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertificateDeliveryMode;
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

function aliyunApigwDeployerWithDomainMatcher(callable $clientFactory, callable $matcher): AliyunApigwDeployer
{
    return new class($clientFactory, $matcher) extends AliyunApigwDeployer
    {
        public function __construct(private $factory, private $matcher) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function certificateMatches(string $certPem, string $hostname): bool
        {
            return ($this->matcher)($hostname);
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

function apigTraditionalGroupResponse(array $domains): DescribeApiGroupResponse
{
    return new DescribeApiGroupResponse(['body' => new DescribeApiGroupResponseBody([
        'customDomains' => new customDomains([
            'domainItem' => array_map(
                fn (array $domain) => new domainItem([
                    'domainName' => $domain['name'],
                    'domainBindingStatus' => $domain['binding'] ?? 'BINDING',
                ]),
                $domains,
            ),
        ]),
    ])]);
}

function apigClientOption(object $client, string $property): mixed
{
    $reflection = new ReflectionObject($client);
    $option = $reflection->getProperty($property);
    $option->setAccessible(true);

    return $option->getValue($client);
}

test('阿里云 API 网关按服务类型选择证书交付方式，schema 提供条件必填字段', function () {
    $deployer = new AliyunApigwDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('apigw');
    expect($deployer->label())->toBe('阿里云 API 网关');
    expect($deployer->certificateDeliveryMode(['service_type' => 'traditional']))->toBe(CertificateDeliveryMode::Inline);
    expect($deployer->certificateDeliveryMode(['service_type' => 'cloudnative']))->toBe(CertificateDeliveryMode::RemoteStore);
    expect($deployer->certificateDeliveryMode([]))->toBe(CertificateDeliveryMode::RemoteStore);

    $schema = collect($deployer->configSchema())->keyBy('key');
    expect($schema->keys()->all())->toContain('service_type')->toContain('gateway_id')->toContain('group_id')->toContain('domain_match_pattern')->toContain('domain')->toContain('region');
    expect($schema['gateway_id']['required_when'])->toBe(['key' => 'service_type', 'equals' => 'cloudnative']);
    expect($schema['group_id']['required_when'])->toBe(['key' => 'service_type', 'equals' => 'traditional']);
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
                'tlsCipherSuitesConfig' => ['cipherSuites' => ['TLS_AES_128_GCM_SHA256']],
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
    expect($updateReq->tlsCipherSuitesConfig)->toBe(['cipherSuites' => ['TLS_AES_128_GCM_SHA256']]);
});

test('bind traditional：仅 BINDING 域名参加 wildcard 匹配，并以内联完整链逐个更新', function () {
    $describeReq = null;
    $updates = [];
    $traditional = Mockery::mock(CloudAPI::class);
    $traditional->shouldReceive('describeApiGroup')->once()->andReturnUsing(function (DescribeApiGroupRequest $request) use (&$describeReq) {
        $describeReq = $request;

        return apigTraditionalGroupResponse([
            ['name' => 'a.example.com'],
            ['name' => 'deep.a.example.com'],
            ['name' => 'pending.example.com', 'binding' => 'BINDING'],
            ['name' => 'unbound.example.com', 'binding' => 'UNBOUND'],
        ]);
    });
    $traditional->shouldReceive('setDomainCertificate')->twice()->andReturnUsing(function (SetDomainCertificateRequest $request) use (&$updates) {
        $updates[] = $request;

        return new SetDomainCertificateResponse;
    });

    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'cloudapi' ? $traditional : new stdClass);
    $deployer->bind(['cert' => 'LEAFPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_type' => 'traditional',
        'group_id' => 'group-1',
        'domain_match_pattern' => 'wildcard',
        'domain' => '*.example.com',
    ]);

    expect($describeReq->groupId)->toBe('group-1');
    expect(array_map(fn (SetDomainCertificateRequest $request) => $request->domainName, $updates))->toBe(['a.example.com', 'pending.example.com']);
    foreach ($updates as $request) {
        expect($request->groupId)->toBe('group-1');
        expect($request->certificateName)->toStartWith('clouddeploy_');
        expect($request->certificateBody)->toBe("LEAFPEM\nCHAINPEM");
        expect($request->certificatePrivateKey)->toBe('KEYPEM');
    }
});

test('bind traditional：exact 也必须命中 BINDING 域名', function () {
    $traditional = Mockery::mock(CloudAPI::class);
    $traditional->shouldReceive('describeApiGroup')->once()->andReturn(apigTraditionalGroupResponse([
        ['name' => 'api.example.com', 'binding' => 'BINDING'],
    ]));
    $traditional->shouldReceive('setDomainCertificate')->once()->andReturnUsing(function (SetDomainCertificateRequest $request) {
        expect($request->domainName)->toBe('api.example.com');

        return new SetDomainCertificateResponse;
    });
    aliyunApigwDeployerWith(fn (string $kind) => $kind === 'cloudapi' ? $traditional : new stdClass)->bind(
        ['cert' => 'LEAFPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['service_type' => 'traditional', 'group_id' => 'group-1', 'domain' => 'api.example.com'],
    );
});

test('bind traditional：certsan 对 BINDING 域名匹配，零匹配明确失败', function () {
    $traditional = Mockery::mock(CloudAPI::class);
    $traditional->shouldReceive('describeApiGroup')->twice()->andReturn(apigTraditionalGroupResponse([
        ['name' => 'one.example.com'],
        ['name' => 'two.example.com'],
        ['name' => 'ignored.example.com', 'binding' => 'UNBOUND'],
    ]));
    $traditional->shouldReceive('setDomainCertificate')->once()->andReturn(new SetDomainCertificateResponse);
    $matchesCertificate = true;
    $deployer = aliyunApigwDeployerWithDomainMatcher(
        fn (string $kind) => $kind === 'cloudapi' ? $traditional : new stdClass,
        function (string $hostname) use (&$matchesCertificate): bool {
            return $matchesCertificate && $hostname === 'two.example.com';
        },
    );
    $credentials = ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
    $certificate = ['cert' => 'LEAFPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];

    $deployer->bind($certificate, $credentials, [
        'service_type' => 'traditional', 'group_id' => 'group-1', 'domain_match_pattern' => 'certsan',
    ]);
    $matchesCertificate = false;
    expect(fn () => $deployer->bind($certificate, $credentials, [
        'service_type' => 'traditional', 'group_id' => 'group-1', 'domain_match_pattern' => 'certsan',
    ]))->toThrow(RuntimeException::class, '未找到匹配的 API 网关域名');
});

test('bind cloudnative：wildcard 和 certsan 遍历已发布域名，沿用 GetDomain TLS 全量回填', function () {
    $updated = [];
    $allDomainsCalls = 0;
    $apig = Mockery::mock(APIG::class);
    $apig->shouldReceive('listDomains')->andReturnUsing(function (ListDomainsRequest $request) use (&$allDomainsCalls) {
        if ($request->nameLike !== null) {
            return apigListDomainsResponse([['name' => $request->nameLike, 'id' => $request->nameLike === 'a.example.com' ? 'd-a' : 'd-san']]);
        }
        $allDomainsCalls++;

        return apigListDomainsResponse($allDomainsCalls === 1
            ? [
                ['name' => 'a.example.com', 'id' => 'd-a'],
                ['name' => 'deep.a.example.com', 'id' => 'd-deep'],
                ['name' => 'unpublished.example.com', 'id' => 'd-unpublished', 'status' => 'Unpublished'],
            ]
            : [
                ['name' => 'san.example.com', 'id' => 'd-san'],
                ['name' => 'unpublished.example.com', 'id' => 'd-unpublished', 'status' => 'Unpublished'],
            ]);
    });
    $apig->shouldReceive('getDomain')->twice()->andReturn(apigGetDomainResponse([
        'forceHttps' => true, 'mTLSEnabled' => false, 'http2Option' => 'Open',
        'tlsMin' => 'TLSv1.2', 'tlsMax' => 'TLSv1.3', 'tlsCipherSuitesConfig' => ['cipherSuites' => ['TLS_AES_128_GCM_SHA256']],
    ]));
    $apig->shouldReceive('updateDomain')->twice()->andReturnUsing(function (string $domainId, UpdateDomainRequest $request) use (&$updated) {
        $updated[$domainId] = $request;

        return new UpdateDomainResponse;
    });
    $deployer = aliyunApigwDeployerWithDomainMatcher(
        fn (string $kind) => $kind === 'apig' ? $apig : new stdClass,
        fn (string $hostname) => $hostname === 'san.example.com',
    );
    $credentials = ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];

    $deployer->bind(['remote_cert_id' => '123-cn-hangzhou', 'cert' => 'LEAF', 'chain' => 'CHAIN'], $credentials, [
        'service_type' => 'cloudnative', 'gateway_id' => 'gw-1', 'domain_match_pattern' => 'wildcard', 'domain' => '*.example.com',
    ]);
    $deployer->bind(['remote_cert_id' => '456-cn-hangzhou', 'cert' => 'LEAF', 'chain' => 'CHAIN'], $credentials, [
        'service_type' => 'cloudnative', 'gateway_id' => 'gw-1', 'domain_match_pattern' => 'certsan',
    ]);

    expect(array_keys($updated))->toBe(['d-a', 'd-san']);
    expect($updated['d-a']->certIdentifier)->toBe('123-cn-hangzhou');
    expect($updated['d-san']->certIdentifier)->toBe('456-cn-hangzhou');
    expect($updated['d-a']->tlsCipherSuitesConfig)->toBe(['cipherSuites' => ['TLS_AES_128_GCM_SHA256']]);
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

test('traditional 缺少 group_id 抛业务错误', function () {
    $deployer = aliyunApigwDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'CERT', 'key' => 'KEY', 'chain' => 'CHAIN'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'service_type' => 'traditional',
        'domain' => 'a.com',
    ]))->toThrow(RuntimeException::class, '缺少配置 group_id');
});

test('traditional CloudAPI 按地域使用官方 endpoint 与统一超时', function () {
    $deployer = new class extends AliyunApigwDeployer
    {
        public ?Config $seenConfig = null;

        public function traditionalClient(array $credentials): object
        {
            return $this->makeClient('cloudapi', $credentials);
        }

        protected function aliyunConfig(array $credentials, string $endpoint): Config
        {
            return $this->seenConfig = new Config([
                'accessKeyId' => $credentials['access_key_id'] ?? '',
                'accessKeySecret' => $credentials['access_key_secret'] ?? '',
                'endpoint' => $endpoint,
                'readTimeout' => self::ALIYUN_READ_TIMEOUT_MS,
                'connectTimeout' => self::ALIYUN_CONNECT_TIMEOUT_MS,
            ]);
        }
    };
    $client = $deployer->traditionalClient([
        'access_key_id' => 'AK', 'access_key_secret' => 'SK', 'region' => 'ap-southeast-1',
    ]);

    expect($client)->toBeInstanceOf(CloudAPI::class);
    expect($deployer->seenConfig->endpoint)->toBe('apigateway.ap-southeast-1.aliyuncs.com');
    expect($deployer->seenConfig->readTimeout)->toBe(AliyunApigwDeployer::ALIYUN_READ_TIMEOUT_MS);
    expect($deployer->seenConfig->connectTimeout)->toBe(AliyunApigwDeployer::ALIYUN_CONNECT_TIMEOUT_MS);
    expect(apigClientOption($client, '_readTimeout'))->toBe(AliyunApigwDeployer::ALIYUN_READ_TIMEOUT_MS);
    expect(apigClientOption($client, '_connectTimeout'))->toBe(AliyunApigwDeployer::ALIYUN_CONNECT_TIMEOUT_MS);
});

test('traditional SDK 抛 TeaError 时同样净化 AK/SK 和签名 URI', function () {
    $traditional = Mockery::mock(CloudAPI::class);
    $traditional->shouldReceive('describeApiGroup')->once()->andThrow(new TeaError(
        [],
        'cURL https://apigateway.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK&Signature=SIG-LEAK',
        0,
    ));
    $deployer = aliyunApigwDeployerWith(fn (string $kind) => $kind === 'cloudapi' ? $traditional : new stdClass);

    try {
        $deployer->bind(['cert' => 'CERT', 'key' => 'KEY', 'chain' => 'CHAIN'], [
            'access_key_id' => 'AK-LEAK', 'access_key_secret' => 'SK-LEAK',
        ], ['service_type' => 'traditional', 'group_id' => 'group-1', 'domain' => 'api.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('SK-LEAK')->not->toContain('SIG-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
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

<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Ddoscoo;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\AssociateWebCertRequest;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\AssociateWebCertResponse;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\DescribeDomainsResponse;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\DescribeDomainsResponseBody;
use AlibabaCloud\Tea\Exception\TeaError;
use Darabonba\OpenApi\Exceptions\ClientException;
use Darabonba\OpenApi\Exceptions\ServerException;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunDdosproDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/ddoscoo）。
 * uploader 经 certUploader() 复用同一 makeClient('cas')，故 mock cas 即覆盖上传路径。
 * makeClient('ddoscoo', $cred) 的 $cred 含 region（用于 endpoint，由 bind 透传）。
 */
function aliyunDdosproDeployerWith(callable $clientFactory): AliyunDdosproDeployer
{
    return new class($clientFactory) extends AliyunDdosproDeployer
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

function ddosproCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function ddosproCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

test('阿里云 DDoS 高防走证书服务（CAS）+ 基本元信息 + configSchema 覆盖 bind 读取键', function () {
    $deployer = new AliyunDdosproDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('ddospro');
    expect($deployer->label())->toBe('阿里云 DDoS 高防');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('domain')->toContain('region')->toContain('domain_match_pattern');
});

test('wildcard 列举 DDoS 域名并批量关联匹配项', function () {
    $updated = [];
    $ddos = Mockery::mock(Ddoscoo::class);
    $ddos->shouldReceive('describeDomains')->once()->andReturn(new DescribeDomainsResponse([
        'body' => new DescribeDomainsResponseBody(['domains' => ['a.example.com', 'deep.a.example.com']]),
    ]));
    $ddos->shouldReceive('associateWebCert')->once()->andReturnUsing(function ($request) use (&$updated) {
        $updated[] = $request->domain;

        return new AssociateWebCertResponse;
    });
    aliyunDdosproDeployerWith(fn () => $ddos)->bind('1-cn-hangzhou', [
        'access_key_id' => 'AK', 'access_key_secret' => 'SK',
    ], ['domain_match_pattern' => 'wildcard', 'domain' => '*.example.com']);
    expect($updated)->toBe(['a.example.com']);
});

test('uploader.upload 复用 CAS：UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return ddosproCasUploadResponse(112233);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return ddosproCasDetailResponse('112233-cn-hangzhou');
        });

    $deployer = aliyunDdosproDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('112233-cn-hangzhou');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(112233);
    expect($detailReq->certFilter)->toBeTrue();
});

test('bind 用完整 CertIdentifier（不拆）调 ddoscoo.AssociateWebCert（Domain + CertIdentifier）', function () {
    $captured = null;
    $ddoscoo = Mockery::mock(Ddoscoo::class);
    $ddoscoo->shouldReceive('associateWebCert')
        ->once()
        ->andReturnUsing(function (AssociateWebCertRequest $req) use (&$captured) {
            $captured = $req;

            return new AssociateWebCertResponse;
        });

    $deployer = aliyunDdosproDeployerWith(fn (string $kind) => $kind === 'ddoscoo' ? $ddoscoo : new stdClass);
    $deployer->bind('987654-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'domain' => 'ddos.example.com',
        'region' => 'cn-hangzhou',
    ]);

    expect($captured->domain)->toBe('ddos.example.com');
    // 关键：传完整 CertIdentifier（含 region 段），不拆成 bare int（对齐 certimate ddospro）
    expect($captured->certIdentifier)->toBe('987654-cn-hangzhou');
});

test('bind 把 region 透传进 ddoscoo client（endpoint 按 region 实例化）', function () {
    $seenRegion = null;
    $ddoscoo = Mockery::mock(Ddoscoo::class);
    $ddoscoo->shouldReceive('associateWebCert')->once()->andReturn(new AssociateWebCertResponse);

    $deployer = aliyunDdosproDeployerWith(fn (string $kind) => $kind === 'ddoscoo' ? $ddoscoo : new stdClass);
    $deployer->seen = function (string $kind, array $cred) use (&$seenRegion) {
        if ($kind === 'ddoscoo') {
            $seenRegion = $cred['region'] ?? null;
        }
    };
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'domain' => 'd.example.com',
        'region' => 'ap-southeast-1',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunDdosproDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['region' => 'cn-hangzhou']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 openapi-core ClientException（API 错误）时脱敏重抛（含错误码+服务端 Message、无 AK/SK、不挂 previous）', function () {
    $ddoscoo = Mockery::mock(Ddoscoo::class);
    // 新一代 openapi-core 结构化 4xx：ClientException（code/message/data 取自服务端响应体）
    $ddoscoo->shouldReceive('associateWebCert')->andThrow(new ClientException([
        'statusCode' => 404,
        'code' => 'DomainNotExist',
        'message' => 'code: 404, the domain does not exist request id: REQ-DDOS-1',
        'description' => '',
        'data' => ['Code' => 'DomainNotExist', 'Message' => 'the domain does not exist', 'RequestId' => 'REQ-DDOS-1'],
        'accessDeniedDetail' => [],
        'requestId' => 'REQ-DDOS-1',
    ]));

    $deployer = aliyunDdosproDeployerWith(fn (string $kind) => $kind === 'ddoscoo' ? $ddoscoo : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'domain' => 'x.example.com', 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DomainNotExist')->toContain('the domain does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛 openapi-core ServerException（trace/message 塞含 AK/Signature 串）脱敏仍不泄露凭证', function () {
    $ddoscoo = Mockery::mock(Ddoscoo::class);
    // 攻击性构造：把含签名串塞进 message + data（模拟极端污染），断言绝不回传
    $ddoscoo->shouldReceive('associateWebCert')->andThrow(new ServerException([
        'statusCode' => 500,
        'code' => 'InternalError',
        'message' => 'leak AccessKeyId=AKLEAK999 Signature=SIGLEAK888 in message',
        'description' => '',
        'data' => ['Code' => 'InternalError', 'Message' => 'server boom', 'RequestId' => 'REQ-9'],
        'requestId' => 'REQ-9',
    ]));

    $deployer = aliyunDdosproDeployerWith(fn (string $kind) => $kind === 'ddoscoo' ? $ddoscoo : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AKLEAK999', 'access_key_secret' => 'SK'], [
            'domain' => 'x.example.com', 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        // 只取 code + 服务端 data.Message，不整段回传 getMessage()，故不含 AK/Signature 串
        expect($e->getMessage())->toBe('[InternalError] server boom');
        expect($e->getMessage())->not->toContain('AKLEAK999')->not->toContain('SIGLEAK888');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AKLEAK999')->not->toContain('SIGLEAK888');
    }
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $ddoscoo = Mockery::mock(Ddoscoo::class);
    $ddoscoo->shouldReceive('associateWebCert')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://ddoscoo.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunDdosproDeployerWith(fn (string $kind) => $kind === 'ddoscoo' ? $ddoscoo : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], [
            'domain' => 'x.example.com', 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

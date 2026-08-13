<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\SDK\Dcdn\V20180115\Dcdn;
use AlibabaCloud\SDK\Dcdn\V20180115\Models\DescribeDcdnUserDomainsRequest;
use AlibabaCloud\SDK\Dcdn\V20180115\Models\SetDcdnDomainSSLCertificateRequest;
use AlibabaCloud\SDK\Dcdn\V20180115\Models\SetDcdnDomainSSLCertificateResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunDcdnDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/dcdn）。
 * uploader 经 certUploader() 复用同一 makeClient('cas')，故 mock cas 即覆盖上传路径。
 */
function aliyunDcdnDeployerWith(callable $clientFactory): AliyunDcdnDeployer
{
    return new class($clientFactory) extends AliyunDcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function casUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function casDetailResponse(string $identifier, ?string $name = null): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
        'name' => $name,
    ])]);
}

test('阿里云 DCDN 走证书服务（CAS）', function () {
    $deployer = new AliyunDcdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('dcdn');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region')->toContain('domain_match_pattern')->toContain('domain');
});

test('bind wildcard：DescribeDcdnUserDomains 过滤状态并批量绑定单层子域', function () {
    $listReq = null;
    $domains = [];
    $dcdn = Mockery::mock(Dcdn::class);
    $dcdn->shouldReceive('describeDcdnUserDomains')->once()->andReturnUsing(function (DescribeDcdnUserDomainsRequest $req) use (&$listReq) {
        $listReq = $req;

        return (object) ['body' => (object) ['domains' => (object) ['pageData' => [
            (object) ['domainName' => 'a.example.com', 'domainStatus' => 'online'],
            (object) ['domainName' => 'b.example.com', 'domainStatus' => 'online'],
            (object) ['domainName' => 'deep.a.example.com', 'domainStatus' => 'online'],
            (object) ['domainName' => 'off.example.com', 'domainStatus' => 'offline'],
        ]]]];
    });
    $dcdn->shouldReceive('setDcdnDomainSSLCertificate')->twice()->andReturnUsing(function (SetDcdnDomainSSLCertificateRequest $req) use (&$domains) {
        $domains[] = $req->domainName;

        return new SetDcdnDomainSSLCertificateResponse;
    });

    $deployer = aliyunDcdnDeployerWith(fn (string $kind) => $kind === 'dcdn' ? $dcdn : new stdClass);
    $deployer->bind('123-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK', 'resource_group_id' => 'rg-1'], [
        'domain_match_pattern' => 'wildcard',
        'domain' => '*.example.com',
    ]);

    expect($listReq->resourceGroupId)->toBe('rg-1');
    expect($listReq->checkDomainShow)->toBeTrue();
    expect($listReq->pageNumber)->toBe(1);
    expect($listReq->pageSize)->toBe(500);
    expect($domains)->toBe(['a.example.com', 'b.example.com']);
});

test('uploader.upload 调 cas.UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return casUploadResponse(987654);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return casDetailResponse('987654-cn-hangzhou');
        });

    $deployer = aliyunDcdnDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('987654-cn-hangzhou');
    // 上传：完整链 + key + 唯一名
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($uploadReq->name)->toStartWith('clouddeploy_');
    // 详情：按 certId 查（int）、certFilter=true
    expect($detailReq->certId)->toBe(987654);
    expect($detailReq->certFilter)->toBeTrue();
});

test('upload 未返回 CertId 时抛明确异常（非 TypeError）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')->andReturn(new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody([])]));

    $deployer = aliyunDcdnDeployerWith(fn () => $cas);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']))
        ->toThrow(RuntimeException::class, 'CertId');
});

test('upload 未返回 CertIdentifier 时抛明确异常', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')->andReturn(casUploadResponse(1));
    $cas->shouldReceive('getUserCertificateDetail')->andReturn(casDetailResponse(''));

    $deployer = aliyunDcdnDeployerWith(fn () => $cas);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']))
        ->toThrow(RuntimeException::class, 'CertIdentifier');
});

test('bind 拆 CertIdentifier 调 dcdn.SetDcdnDomainSSLCertificate（CertType=cas、CertId int、CertRegion）', function () {
    $captured = null;
    $dcdn = Mockery::mock(Dcdn::class);
    $dcdn->shouldReceive('setDcdnDomainSSLCertificate')
        ->once()
        ->andReturnUsing(function (SetDcdnDomainSSLCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new SetDcdnDomainSSLCertificateResponse;
        });

    $deployer = aliyunDcdnDeployerWith(fn (string $kind) => $kind === 'dcdn' ? $dcdn : new stdClass);
    $deployer->bind('123456-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'dcdn.example.com']);

    expect($captured->domainName)->toBe('dcdn.example.com');
    expect($captured->certType)->toBe('cas');
    expect($captured->certId)->toBe(123456);
    expect($captured->certId)->toBeInt();
    expect($captured->certRegion)->toBe('cn-hangzhou');
    expect($captured->SSLProtocol)->toBe('on');
});

test('bind CertIdentifier 含多段 region（cn-hangzhou）按首个 - 拆分正确', function () {
    $captured = null;
    $dcdn = Mockery::mock(Dcdn::class);
    $dcdn->shouldReceive('setDcdnDomainSSLCertificate')->andReturnUsing(function (SetDcdnDomainSSLCertificateRequest $req) use (&$captured) {
        $captured = $req;

        return new SetDcdnDomainSSLCertificateResponse;
    });

    $deployer = aliyunDcdnDeployerWith(fn () => $dcdn);
    $deployer->bind('42-ap-southeast-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'd.example.com']);

    expect($captured->certId)->toBe(42);
    expect($captured->certRegion)->toBe('ap-southeast-1');
});

test('bind 收到无效 CertIdentifier 抛业务错误', function () {
    $deployer = aliyunDcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('not-a-valid-id', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, 'CertIdentifier');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunDcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 TeaError 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $dcdn = Mockery::mock(Dcdn::class);
    $dcdn->shouldReceive('setDcdnDomainSSLCertificate')->andThrow(new TeaError([
        'code' => 'InvalidDomain.NotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'InvalidDomain.NotFound', 'Message' => 'the domain does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunDcdnDeployerWith(fn () => $dcdn);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidDomain.NotFound')->toContain('the domain does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类异常（message 含签名 URI）时脱敏只暴露类名', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://cas.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunDcdnDeployerWith(fn () => $cas);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

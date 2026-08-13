<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentScfDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Scf\V20180416\Models\GetCustomDomainRequest;
use TencentCloud\Scf\V20180416\Models\GetCustomDomainResponse;
use TencentCloud\Scf\V20180416\Models\ListCustomDomainsResponse;
use TencentCloud\Scf\V20180416\Models\UpdateCustomDomainRequest;
use TencentCloud\Scf\V20180416\Models\UpdateCustomDomainResponse;
use TencentCloud\Scf\V20180416\ScfClient;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificateResponse;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/scf），第三参 region。 */
function tencentScfDeployerWith(callable $clientFactory): TencentScfDeployer
{
    return new class($clientFactory) extends TencentScfDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function scfUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

function scfGetDomainResponse(?string $protocol): GetCustomDomainResponse
{
    $resp = new GetCustomDomainResponse;
    $data = ['RequestId' => 'r'];
    if ($protocol !== null) {
        $data['Protocol'] = $protocol;
    }
    $resp->deserialize($data);

    return $resp;
}

test('腾讯云 SCF 走证书服务（storeKind=tencent_ssl）+ 基本元信息', function () {
    $deployer = new TencentScfDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('scf');
    expect($deployer->label())->toBe('腾讯云 SCF');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('domain')->toContain('region');
});

test('SCF uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => scfUploadCertResponse('cert-scf'));

    $deployer = tencentScfDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-scf');
});

test('SCF bind 先 GetCustomDomain 读 Protocol，再 UpdateCustomDomain 设 CertConfig.CertificateId（保留 HTTPS Protocol）', function () {
    $getReq = null;
    $updateReq = null;
    $scf = Mockery::mock(ScfClient::class);
    $scf->shouldReceive('GetCustomDomain')
        ->once()
        ->andReturnUsing(function (GetCustomDomainRequest $req) use (&$getReq) {
            $getReq = $req;

            return scfGetDomainResponse('HTTPS');
        });
    $scf->shouldReceive('UpdateCustomDomain')
        ->once()
        ->andReturnUsing(function (UpdateCustomDomainRequest $req) use (&$updateReq) {
            $updateReq = $req;

            return new UpdateCustomDomainResponse;
        });

    $deployer = tencentScfDeployerWith(fn (string $kind) => $kind === 'scf' ? $scf : new stdClass);
    $deployer->bind('cert-scf', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'domain' => 'scf.example.com',
        'region' => 'ap-guangzhou',
    ]);

    expect($getReq->Domain)->toBe('scf.example.com');
    expect($updateReq->Domain)->toBe('scf.example.com');
    // 现有 HTTPS 协议原样保留
    expect($updateReq->Protocol)->toBe('HTTPS');
    // CertConfig 键名（类型 CertConf）.CertificateId
    expect($updateReq->CertConfig->CertificateId)->toBe('cert-scf');
});

test('SCF bind 现有 Protocol 为 HTTP 时升级为 HTTP&HTTPS（否则证书不生效）', function () {
    $updateReq = null;
    $scf = Mockery::mock(ScfClient::class);
    $scf->shouldReceive('GetCustomDomain')->once()->andReturn(scfGetDomainResponse('HTTP'));
    $scf->shouldReceive('UpdateCustomDomain')
        ->once()
        ->andReturnUsing(function (UpdateCustomDomainRequest $req) use (&$updateReq) {
            $updateReq = $req;

            return new UpdateCustomDomainResponse;
        });

    $deployer = tencentScfDeployerWith(fn (string $kind) => $kind === 'scf' ? $scf : new stdClass);
    $deployer->bind('cert-scf', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'domain' => 'scf.example.com', 'region' => 'ap-guangzhou',
    ]);

    expect($updateReq->Protocol)->toBe('HTTP&HTTPS');
});

test('SCF bind 现有 Protocol 缺失时回落 HTTP&HTTPS', function () {
    $updateReq = null;
    $scf = Mockery::mock(ScfClient::class);
    $scf->shouldReceive('GetCustomDomain')->once()->andReturn(scfGetDomainResponse(null));
    $scf->shouldReceive('UpdateCustomDomain')
        ->once()
        ->andReturnUsing(function (UpdateCustomDomainRequest $req) use (&$updateReq) {
            $updateReq = $req;

            return new UpdateCustomDomainResponse;
        });

    $deployer = tencentScfDeployerWith(fn (string $kind) => $kind === 'scf' ? $scf : new stdClass);
    $deployer->bind('cert-scf', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'domain' => 'scf.example.com', 'region' => 'ap-guangzhou',
    ]);

    expect($updateReq->Protocol)->toBe('HTTP&HTTPS');
});

test('SCF certsan 列举自定义域名并按云证书 SAN 批量更新', function () {
    $scf = Mockery::mock(ScfClient::class);
    $listed = new ListCustomDomainsResponse;
    $listed->deserialize(['Domains' => [['Domain' => 'a.example.com'], ['Domain' => 'x.example.net']], 'RequestId' => 'r']);
    $scf->shouldReceive('ListCustomDomains')->once()->andReturn($listed);
    $scf->shouldReceive('GetCustomDomain')->once()->withArgs(fn ($request) => $request->Domain === 'a.example.com')->andReturn(scfGetDomainResponse('HTTPS'));
    $scf->shouldReceive('UpdateCustomDomain')->once()->withArgs(fn ($request) => $request->Domain === 'a.example.com')->andReturn(new UpdateCustomDomainResponse);
    $ssl = Mockery::mock(SslClient::class);
    $certificate = new DescribeCertificateResponse;
    $certificate->deserialize(['SubjectAltName' => ['a.example.com'], 'RequestId' => 'r']);
    $ssl->shouldReceive('DescribeCertificate')->once()->andReturn($certificate);

    tencentScfDeployerWith(fn (string $kind) => $kind === 'scf' ? $scf : $ssl)->bind(
        'cert-scf', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['region' => 'ap-guangzhou', 'domain_match_pattern' => 'certsan'],
    );
});

test('SCF bind 把 region 透传进 scf client（按 region 实例化）', function () {
    $seenRegion = null;
    $scf = Mockery::mock(ScfClient::class);
    $scf->shouldReceive('GetCustomDomain')->andReturn(scfGetDomainResponse('HTTPS'));
    $scf->shouldReceive('UpdateCustomDomain')->andReturn(new UpdateCustomDomainResponse);

    $deployer = tencentScfDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $scf) {
        if ($kind === 'scf') {
            $seenRegion = $region;

            return $scf;
        }

        return new stdClass;
    });
    $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'domain' => 'scf.example.com', 'region' => 'ap-shanghai',
    ]);

    expect($seenRegion)->toBe('ap-shanghai');
});

test('SCF 缺 domain 配置抛业务错误', function () {
    $deployer = tencentScfDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('SCF 缺 region 配置抛业务错误', function () {
    $deployer = tencentScfDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'domain' => 'scf.example.com',
    ]))->toThrow(RuntimeException::class, '缺少配置 region');
});

test('SCF bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $scf = Mockery::mock(ScfClient::class);
    $scf->shouldReceive('GetCustomDomain')->andReturn(scfGetDomainResponse('HTTPS'));
    $scf->shouldReceive('UpdateCustomDomain')->andThrow(new TencentCloudSDKException('FailedOperation', 'update domain failed', 'req-1'));

    $deployer = tencentScfDeployerWith(fn () => $scf);

    try {
        $deployer->bind('cert-scf', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'domain' => 'scf.example.com', 'region' => 'ap-guangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('update domain failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

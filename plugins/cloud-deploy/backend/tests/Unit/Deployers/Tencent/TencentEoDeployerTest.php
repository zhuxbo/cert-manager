<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentEoDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Teo\V20220901\Models\DescribeAccelerationDomainsResponse;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateResponse;
use TencentCloud\Teo\V20220901\TeoClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/teo）。 */
function tencentEoDeployerWith(callable $clientFactory): TencentEoDeployer
{
    return new class($clientFactory) extends TencentEoDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function eoUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

function tencentEoRsaCertificate(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $pem);

    return $pem;
}

test('腾讯云 EdgeOne 走证书服务（storeKind=tencent_ssl）', function () {
    $deployer = new TencentEoDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('eo');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('EO uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => eoUploadCertResponse('cert-eo'));

    $deployer = tencentEoDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-eo');
});

test('EO bind 用 certId 调 teo.ModifyHostsCertificate 设 ZoneId/Mode/Hosts/ServerCertInfo.CertId', function () {
    $captured = null;
    $teo = Mockery::mock(TeoClient::class);
    $listed = new DescribeAccelerationDomainsResponse;
    $listed->deserialize(['AccelerationDomains' => [['DomainName' => 'eo.example.com']], 'RequestId' => 'r']);
    $teo->shouldReceive('DescribeAccelerationDomains')->once()->andReturn($listed);
    $teo->shouldReceive('ModifyHostsCertificate')
        ->once()
        ->andReturnUsing(function (ModifyHostsCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new ModifyHostsCertificateResponse;
        });

    $deployer = tencentEoDeployerWith(fn (string $kind) => $kind === 'teo' ? $teo : new stdClass);
    $deployer->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['zone_id' => 'zone-1', 'domain' => 'eo.example.com']);

    expect($captured->ZoneId)->toBe('zone-1');
    expect($captured->Mode)->toBe('sslcert');
    expect($captured->Hosts)->toBe(['eo.example.com']);
    expect($captured->ServerCertInfo)->toHaveCount(1);
    expect($captured->ServerCertInfo[0]->CertId)->toBe('cert-eo');
});

test('EO wildcard 列举站点域名并跳过已绑定当前证书的域名', function () {
    $teo = Mockery::mock(TeoClient::class);
    $listed = new DescribeAccelerationDomainsResponse;
    $listed->deserialize(['AccelerationDomains' => [
        ['DomainName' => 'a.example.com', 'Certificate' => ['List' => [['CertId' => 'old']]]],
        ['DomainName' => 'b.example.com', 'Certificate' => ['List' => [['CertId' => 'cert-eo']]]],
        ['DomainName' => 'a.b.example.com'],
    ], 'RequestId' => 'r']);
    $teo->shouldReceive('DescribeAccelerationDomains')->once()->andReturn($listed);
    $teo->shouldReceive('ModifyHostsCertificate')->once()->withArgs(fn ($request) => $request->Hosts === ['a.example.com'])->andReturn(new ModifyHostsCertificateResponse);

    tencentEoDeployerWith(fn () => $teo)->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'zone_id' => 'zone-1', 'domain_match_pattern' => 'wildcard', 'domains' => ['*.example.com'],
    ]);
});

test('EO 多证书按当前证书算法保留未过期的另一算法证书', function () {
    $captured = null;
    $teo = Mockery::mock(TeoClient::class);
    $listed = new DescribeAccelerationDomainsResponse;
    $listed->deserialize(['AccelerationDomains' => [[
        'DomainName' => 'a.example.com',
        'Certificate' => ['List' => [
            ['CertId' => 'rsa-old', 'SignAlgo' => 'RSA SHA256', 'ExpireTime' => '2099-01-01T00:00:00Z'],
            ['CertId' => 'ecc-keep', 'SignAlgo' => 'ECC SHA256', 'ExpireTime' => '2099-01-01T00:00:00Z'],
            ['CertId' => 'ecc-expired', 'SignAlgo' => 'ECC SHA256', 'ExpireTime' => '2000-01-01T00:00:00Z'],
        ]],
    ]], 'RequestId' => 'r']);
    $teo->shouldReceive('DescribeAccelerationDomains')->once()->andReturn($listed);
    $teo->shouldReceive('ModifyHostsCertificate')->once()->andReturnUsing(function (ModifyHostsCertificateRequest $request) use (&$captured) {
        $captured = $request;

        return new ModifyHostsCertificateResponse;
    });

    tencentEoDeployerWith(fn () => $teo)->bind([
        'remote_cert_id' => 'rsa-new',
        'cert' => tencentEoRsaCertificate(),
        'chain' => '',
    ], ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'zone_id' => 'zone-1',
        'domain_match_pattern' => 'exact',
        'domains' => ['a.example.com'],
        'enable_multiple_ssl' => true,
    ]);

    expect($captured->Hosts)->toBe(['a.example.com']);
    expect(array_map(fn ($item) => $item->CertId, $captured->ServerCertInfo))->toBe(['rsa-new', 'ecc-keep']);
});

test('EO 缺 zone_id 配置抛业务错误', function () {
    $deployer = tencentEoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['domain' => 'eo.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 zone_id');
});

test('EO 缺 domain 配置抛业务错误', function () {
    $deployer = tencentEoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-eo', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['zone_id' => 'zone-1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('EO bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $teo = Mockery::mock(TeoClient::class);
    $listed = new DescribeAccelerationDomainsResponse;
    $listed->deserialize(['AccelerationDomains' => [['DomainName' => 'x.example.com']], 'RequestId' => 'r']);
    $teo->shouldReceive('DescribeAccelerationDomains')->once()->andReturn($listed);
    $teo->shouldReceive('ModifyHostsCertificate')->andThrow(new TencentCloudSDKException('ResourceNotFound.Zone', 'zone missing', 'req-1'));

    $deployer = tencentEoDeployerWith(fn () => $teo);

    try {
        $deployer->bind('cert-eo', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], ['zone_id' => 'zone-1', 'domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ResourceNotFound.Zone')->toContain('zone missing');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

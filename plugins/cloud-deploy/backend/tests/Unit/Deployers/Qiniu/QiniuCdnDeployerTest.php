<?php

use Plugins\CloudDeploy\Deployers\Qiniu\QiniuApiException;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuCdnDeployer;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('api')，故 mock 即覆盖上传 + 绑定路径。
 */
function qiniuCdnDeployerWith(callable $clientFactory): QiniuCdnDeployer
{
    return new class($clientFactory) extends QiniuCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function qiniuCdnCertificate(string $commonName): string
{
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $pem);

    return $pem;
}

test('七牛云 CDN 走证书服务（storeKind=qiniu）', function () {
    $deployer = new QiniuCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('qiniu');
    expect($deployer->provider())->toBe('qiniu');
    expect($deployer->product())->toBe('cdn');
});

test('uploader.upload 调 sslcert 上传返回复合 remote_cert_id "{certID}|{certName}"', function () {
    $args = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('uploadSslCert')
        ->once()
        ->andReturnUsing(function (string $name, string $cn, string $ca, string $pri) use (&$args) {
            $args = compact('name', 'cn', 'ca', 'pri');

            return 'qiniucert-001';
        });

    $deployer = qiniuCdnDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key' => 'AK', 'secret_key' => 'SK']);

    // 复合 remote_cert_id：certID|certName
    expect($ref)->toStartWith('qiniucert-001|clouddeploy_');
    // 上传：name（clouddeploy_前缀）、ca=完整链（cert+chain）、pri=key
    expect($args['name'])->toStartWith('clouddeploy_');
    expect($args['ca'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['pri'])->toBe('KEYPEM');
});

test('upload 未返回 certID 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('uploadSslCert')->andReturn('');

    $deployer = qiniuCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key' => 'AK', 'secret_key' => 'SK']))
        ->toThrow(RuntimeException::class, 'certID');
});

test('bind 未启用 HTTPS 的域名走 enableCdnDomainHttps（certId、forceHttps/http2=true）', function () {
    $captured = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('getCdnDomainInfo')->once()->with('cdn.example.com')->andReturn(['https' => null]);
    $client->shouldReceive('enableCdnDomainHttps')
        ->once()
        ->andReturnUsing(function (string $domain, string $certId, bool $force, bool $http2) use (&$captured) {
            $captured = compact('domain', 'certId', 'force', 'http2');
        });

    $deployer = qiniuCdnDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $deployer->bind('qiniucert-001|clouddeploy_123', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'cdn.example.com']);

    expect($captured)->toBe(['domain' => 'cdn.example.com', 'certId' => 'qiniucert-001', 'force' => true, 'http2' => true]);
});

test('bind 已启用但证书不同走 modifyCdnDomainHttpsConf（沿用原 forceHttps/http2Enable）', function () {
    $captured = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('getCdnDomainInfo')->once()->andReturn([
        'https' => ['certId' => 'old-cert', 'forceHttps' => true, 'http2Enable' => false],
    ]);
    $client->shouldReceive('modifyCdnDomainHttpsConf')
        ->once()
        ->andReturnUsing(function (string $domain, string $certId, bool $force, bool $http2) use (&$captured) {
            $captured = compact('domain', 'certId', 'force', 'http2');
        });

    $deployer = qiniuCdnDeployerWith(fn () => $client);
    $deployer->bind('new-cert|clouddeploy_123', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'cdn.example.com']);

    expect($captured)->toBe(['domain' => 'cdn.example.com', 'certId' => 'new-cert', 'force' => true, 'http2' => false]);
});

test('bind 已启用且证书相同则无操作（不调 enable/modify）', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('getCdnDomainInfo')->once()->andReturn([
        'https' => ['certId' => 'same-cert', 'forceHttps' => true, 'http2Enable' => true],
    ]);
    $client->shouldReceive('enableCdnDomainHttps')->never();
    $client->shouldReceive('modifyCdnDomainHttpsConf')->never();

    $deployer = qiniuCdnDeployerWith(fn () => $client);
    $deployer->bind('same-cert|clouddeploy_123', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'cdn.example.com']);

    expect(true)->toBeTrue();
});

test('bind exact 模式去掉域名前导 *（*.example.com → .example.com）', function () {
    $captured = null;
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('getCdnDomainInfo')
        ->once()
        ->andReturnUsing(function (string $domain) use (&$captured) {
            $captured = $domain;

            return ['https' => null];
        });
    $client->shouldReceive('enableCdnDomainHttps')->once();

    $deployer = qiniuCdnDeployerWith(fn () => $client);
    $deployer->bind('c|clouddeploy_1', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => '*.example.com']);

    expect($captured)->toBe('.example.com');
});

test('certsan 按 marker 列举可用域名并仅更新证书匹配项', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('listCdnDomains')->once()->andReturn(['a.example.com', 'b.example.com']);
    $client->shouldReceive('getCdnDomainInfo')->once()->with('a.example.com')->andReturn(['https' => null]);
    $client->shouldReceive('enableCdnDomainHttps')->once()->with('a.example.com', 'cert-1', true, true);

    $deployer = qiniuCdnDeployerWith(fn () => $client);
    $deployer->bind(['remote_cert_id' => 'cert-1|name-1', 'cert' => qiniuCdnCertificate('a.example.com'), 'chain' => ''], ['access_key' => 'AK', 'secret_key' => 'SK'], [
        'domain_match_pattern' => 'certsan',
    ]);

    expect(true)->toBeTrue();
});

test('bind 收到无效 remote_cert_id 抛业务错误', function () {
    $deployer = qiniuCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('no-separator', ['access_key' => 'AK', 'secret_key' => 'SK'], ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '七牛 remote_cert_id');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = qiniuCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c|n', ['access_key' => 'AK', 'secret_key' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 QiniuApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('getCdnDomainInfo')->andThrow(new QiniuApiException('400', 'domain not found'));

    $deployer = qiniuCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('c|n', ['access_key' => 'AK-SECRET-XYZ', 'secret_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类异常时脱敏只暴露类名（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(QiniuRestClient::class);
    $client->shouldReceive('uploadSslCert')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect https://api.qiniu.com/sslcert with AK-LEAK-9999',
    ));

    $deployer = qiniuCdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key' => 'AK-LEAK-9999', 'secret_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999');
        expect($e->getMessage())->toContain('七牛云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunApiException;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunCdnDeployer;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('api')，故 mock 即覆盖上传 + 绑定路径。
 */
function upyunCdnDeployerWith(callable $clientFactory): UpyunCdnDeployer
{
    return new class($clientFactory) extends UpyunCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function upyunCdnCreds(): array
{
    return ['username' => 'USER', 'password' => 'PASS'];
}

test('又拍云 CDN 走证书服务（storeKind=upyun_ssl）+ 元信息', function () {
    $deployer = new UpyunCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('upyun_ssl');
    expect($deployer->provider())->toBe('upyun');
    expect($deployer->product())->toBe('cdn');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('domain')->toContain('domain_match_pattern');
});

test('bind wildcard 先枚举可见 NORMAL 域名再逐个绑定', function () {
    $domains = [];
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getDomains')->once()->andReturn(['a.example.com', 'deep.a.example.com', '.example.com']);
    $client->shouldReceive('getHttpsServiceManager')->twice()->andReturn([]);
    $client->shouldReceive('updateHttpsCertificateManager')->twice()->andReturnUsing(function (string $id, string $domain) use (&$domains) {
        $domains[] = $domain;
    });
    $client->shouldReceive('migrateHttpsDomain')->never();
    upyunCdnDeployerWith(fn () => $client)->bind('cert-1', upyunCdnCreds(), [
        'domain_match_pattern' => 'wildcard', 'domain' => '*.example.com',
    ]);
    expect($domains)->toBe(['a.example.com', '.example.com']);
});

test('bind certsan 用 opt-in leaf 证书枚举匹配域名', function () {
    $conf = tempnam(sys_get_temp_dir(), 'upyun_san_');
    file_put_contents($conf, "[v3]\nsubjectAltName=DNS:a.example.com,DNS:*.wild.example.com\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3']);
    openssl_x509_export($cert, $pem);
    @unlink($conf);

    $domains = [];
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getDomains')->once()->andReturn(['a.example.com', 'x.wild.example.com', 'deep.x.wild.example.com', 'other.example.com']);
    $client->shouldReceive('getHttpsServiceManager')->twice()->andReturn([]);
    $client->shouldReceive('updateHttpsCertificateManager')->twice()->andReturnUsing(function (string $id, string $domain) use (&$domains) {
        $domains[] = $domain;
    });
    $client->shouldReceive('migrateHttpsDomain')->never();
    upyunCdnDeployerWith(fn () => $client)->bind([
        'remote_cert_id' => 'cert-1', 'cert' => $pem, 'chain' => '',
    ], upyunCdnCreds(), ['domain_match_pattern' => 'certsan']);
    expect($domains)->toBe(['a.example.com', 'x.wild.example.com']);
});

test('uploader.upload 调 uploadHttpsCertificate（完整链 + key）返回 certificate_id', function () {
    $args = null;
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('uploadHttpsCertificate')
        ->once()
        ->andReturnUsing(function (string $certificate, string $privateKey) use (&$args) {
            $args = compact('certificate', 'privateKey');

            return 'upyun-cert-001';
        });

    $deployer = upyunCdnDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', upyunCdnCreds());

    expect($ref)->toBe('upyun-cert-001');
    // 上传：certificate=完整链（cert+chain）、private_key=key
    expect($args['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['privateKey'])->toBe('KEYPEM');
});

test('upload 未返回 certificate_id 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('uploadHttpsCertificate')->andReturn('');

    $deployer = upyunCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', upyunCdnCreds()))
        ->toThrow(RuntimeException::class, 'certificate_id');
});

test('bind 未启用 HTTPS 的域名走 updateHttpsCertificateManager（启用 + 绑证书 https/force=true）', function () {
    $captured = null;
    $client = Mockery::mock(UpyunRestClient::class);
    // 列表无 https==true 条目（含一条未启用的，验证「找任一已启用」而非「列表非空即视为已启用」）
    $client->shouldReceive('getHttpsServiceManager')->once()->with('cdn.example.com')
        ->andReturn([['certificate_id' => 'old', 'https' => false]]);
    $client->shouldReceive('updateHttpsCertificateManager')
        ->once()
        ->andReturnUsing(function (string $certId, string $domain, bool $https, bool $force) use (&$captured) {
            $captured = compact('certId', 'domain', 'https', 'force');
        });
    $client->shouldReceive('migrateHttpsDomain')->never();

    $deployer = upyunCdnDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $deployer->bind('upyun-cert-001', upyunCdnCreds(), ['domain' => 'cdn.example.com']);

    expect($captured)->toBe(['certId' => 'upyun-cert-001', 'domain' => 'cdn.example.com', 'https' => true, 'force' => true]);
});

test('bind 已启用但证书不同走 migrateHttpsDomain（crt_id/domain_name）', function () {
    $captured = null;
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->once()
        ->andReturn([['certificate_id' => 'old-cert', 'https' => true, 'force_https' => true]]);
    $client->shouldReceive('migrateHttpsDomain')
        ->once()
        ->andReturnUsing(function (string $certId, string $domain) use (&$captured) {
            $captured = compact('certId', 'domain');
        });
    $client->shouldReceive('updateHttpsCertificateManager')->never();

    $deployer = upyunCdnDeployerWith(fn () => $client);
    $deployer->bind('new-cert', upyunCdnCreds(), ['domain' => 'cdn.example.com']);

    expect($captured)->toBe(['certId' => 'new-cert', 'domain' => 'cdn.example.com']);
});

test('bind 已启用且证书相同则无操作（不调 update/migrate）', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->once()
        ->andReturn([['certificate_id' => 'same-cert', 'https' => true]]);
    $client->shouldReceive('updateHttpsCertificateManager')->never();
    $client->shouldReceive('migrateHttpsDomain')->never();

    $deployer = upyunCdnDeployerWith(fn () => $client);
    $deployer->bind('same-cert', upyunCdnCreds(), ['domain' => 'cdn.example.com']);

    expect(true)->toBeTrue();
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = upyunCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', upyunCdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 UpyunApiException 时脱敏重抛（含错误码、无账号密码、不挂 previous）', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->andThrow(new UpyunApiException('40001', 'domain not found'));

    $deployer = upyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('cert-1', ['username' => 'USER-LEAK', 'password' => 'PASS-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('40001')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
    }
});

test('upload 遇网络类异常（Guzzle）时脱敏只暴露类名（无账号密码、不挂 previous）', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    // Guzzle 网络异常 message 含请求 URL + 登录 body 可能带凭证 → sanitizer 只暴露类名
    $client->shouldReceive('uploadHttpsCertificate')->andThrow(new ConnectException(
        'cURL error 7: Failed to connect to console.upyun.com (username=USER-LEAK&password=PASS-LEAK)',
        new Request('POST', 'https://console.upyun.com/accounts/signin/'),
    ));

    $deployer = upyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['username' => 'USER-LEAK', 'password' => 'PASS-LEAK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
        expect($e->getMessage())->toContain('又拍云调用失败');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
    }
});

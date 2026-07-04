<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunApiException;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunFileDeployer;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('api')，故 mock 即覆盖上传 + 绑定路径。
 */
function upyunFileDeployerWith(callable $clientFactory): UpyunFileDeployer
{
    return new class($clientFactory) extends UpyunFileDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function upyunFileCreds(): array
{
    return ['username' => 'USER', 'password' => 'PASS'];
}

test('又拍云云存储走证书服务（storeKind=upyun_ssl）+ 元信息', function () {
    $deployer = new UpyunFileDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('upyun_ssl');
    expect($deployer->provider())->toBe('upyun');
    expect($deployer->product())->toBe('file');
    // domain 必填；bucket 选填（对齐 certimate「暂时无用」，仅展示，bind 不读）
    $schema = collect($deployer->configSchema())->keyBy('key');
    expect($schema->keys()->all())->toContain('domain')->toContain('bucket');
    expect($schema['domain']['required'])->toBeTrue();
    expect($schema['bucket']['required'])->toBeFalse();
});

test('uploader.upload 调 uploadHttpsCertificate（完整链 + key）返回 certificate_id', function () {
    $args = null;
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('uploadHttpsCertificate')
        ->once()
        ->andReturnUsing(function (string $certificate, string $privateKey) use (&$args) {
            $args = compact('certificate', 'privateKey');

            return 'upyun-file-cert-9';
        });

    $deployer = upyunFileDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', upyunFileCreds());

    expect($ref)->toBe('upyun-file-cert-9');
    expect($args['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['privateKey'])->toBe('KEYPEM');
});

test('bind 未启用 HTTPS（空列表）走 updateHttpsCertificateManager（启用 + 绑证书）', function () {
    $captured = null;
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->once()->with('file.example.com')->andReturn([]);
    $client->shouldReceive('updateHttpsCertificateManager')
        ->once()
        ->andReturnUsing(function (string $certId, string $domain, bool $https, bool $force) use (&$captured) {
            $captured = compact('certId', 'domain', 'https', 'force');
        });
    $client->shouldReceive('migrateHttpsDomain')->never();

    $deployer = upyunFileDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $deployer->bind('upyun-file-cert-9', upyunFileCreds(), ['domain' => 'file.example.com', 'bucket' => 'mybucket']);

    expect($captured)->toBe(['certId' => 'upyun-file-cert-9', 'domain' => 'file.example.com', 'https' => true, 'force' => true]);
});

test('bind 已启用但证书不同走 migrateHttpsDomain', function () {
    $captured = null;
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->once()
        ->andReturn([['certificate_id' => 'old-cert', 'https' => true]]);
    $client->shouldReceive('migrateHttpsDomain')
        ->once()
        ->andReturnUsing(function (string $certId, string $domain) use (&$captured) {
            $captured = compact('certId', 'domain');
        });
    $client->shouldReceive('updateHttpsCertificateManager')->never();

    $deployer = upyunFileDeployerWith(fn () => $client);
    $deployer->bind('new-cert', upyunFileCreds(), ['domain' => 'file.example.com']);

    expect($captured)->toBe(['certId' => 'new-cert', 'domain' => 'file.example.com']);
});

test('bind 已启用且证书相同则无操作', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->once()
        ->andReturn([['certificate_id' => 'same-cert', 'https' => true]]);
    $client->shouldReceive('updateHttpsCertificateManager')->never();
    $client->shouldReceive('migrateHttpsDomain')->never();

    $deployer = upyunFileDeployerWith(fn () => $client);
    $deployer->bind('same-cert', upyunFileCreds(), ['domain' => 'file.example.com']);

    expect(true)->toBeTrue();
});

test('缺 domain 配置抛业务错误（bucket 选填不触发）', function () {
    $deployer = upyunFileDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', upyunFileCreds(), ['bucket' => 'mybucket']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 UpyunApiException 时脱敏重抛（含错误码、无账号密码、不挂 previous）', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('getHttpsServiceManager')->andThrow(new UpyunApiException('40400', 'bucket domain not found'));

    $deployer = upyunFileDeployerWith(fn () => $client);

    try {
        $deployer->bind('cert-1', ['username' => 'USER-LEAK', 'password' => 'PASS-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('40400')->toContain('bucket domain not found');
        expect($e->getMessage())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
    }
});

test('upload 遇网络类异常（Guzzle）时脱敏只暴露类名（无账号密码、不挂 previous）', function () {
    $client = Mockery::mock(UpyunRestClient::class);
    $client->shouldReceive('uploadHttpsCertificate')->andThrow(new ConnectException(
        'cURL error 7: Failed to connect to console.upyun.com (username=USER-LEAK&password=PASS-LEAK)',
        new Request('POST', 'https://console.upyun.com/accounts/signin/'),
    ));

    $deployer = upyunFileDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['username' => 'USER-LEAK', 'password' => 'PASS-LEAK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('USER-LEAK')->not->toContain('PASS-LEAK');
        expect($e->getMessage())->toContain('又拍云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

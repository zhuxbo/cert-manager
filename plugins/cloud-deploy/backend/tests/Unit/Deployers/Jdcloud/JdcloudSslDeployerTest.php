<?php

use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudSslDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('ssl')，故 mock 即覆盖上传路径。
 */
function jdcloudSslDeployerWith(callable $clientFactory): JdcloudSslDeployer
{
    return new class($clientFactory) extends JdcloudSslDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

if (! function_exists('jdCreds')) {
    function jdCreds(): array
    {
        return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
    }
}

test('京东云 SSL：证书服务型（usesRemoteCertStore + storeKind jdcloud_ssl）', function () {
    $deployer = new JdcloudSslDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('jdcloud_ssl');
    expect($deployer->provider())->toBe('jdcloud');
    expect($deployer->product())->toBe('ssl');
    expect($deployer->configSchema())->toBe([]);
});

test('uploader.upload 调 sslCert:upload 上传完整链 + CRLF 私钥，返回 certId', function () {
    $args = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('uploadCert')
        ->once()
        ->andReturnUsing(function (string $name, string $certFile, string $keyFile) use (&$args) {
            $args = compact('name', 'certFile', 'keyFile');

            return 'jdcert-001';
        });

    $deployer = jdcloudSslDeployerWith(fn (string $kind) => $kind === 'ssl' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', "KEY\nPEM", 'CHAINPEM', jdCreds());

    expect($ref)->toBe('jdcert-001');
    // certName clouddeploy- 前缀（连字符规则）
    expect($args['name'])->toStartWith('clouddeploy-');
    // certFile = 完整链（cert + chain）
    expect($args['certFile'])->toContain('CERTPEM')->toContain('CHAINPEM');
    // keyFile 规范化为 CRLF + 末尾 CRLF（certimate 私钥摘要前处理）
    expect($args['keyFile'])->toContain("\r\n")->toEndWith("\r\n");
    expect($args['keyFile'])->not->toContain("\n\n"); // 已统一 CRLF，无裸 LF 连续
});

test('upload 未返回 certId 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('uploadCert')->andReturn('');

    $deployer = jdcloudSslDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', jdCreds()))
        ->toThrow(RuntimeException::class, 'certId');
});

test('bind 为 no-op（纯上传，不调任何资源 API）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive()->never();

    $deployer = jdcloudSslDeployerWith(fn () => $client);
    $deployer->bind('jdcert-001', jdCreds(), []);
    expect(true)->toBeTrue();
});

test('upload SDK 抛 JdcloudApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('uploadCert')->andThrow(new JdcloudApiException('CERT_LIMIT', 'too many certs'));

    $deployer = jdcloudSslDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-XYZ', 'access_key_secret' => 'SK-LEAK-ABC']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('CERT_LIMIT')->toContain('too many certs');
        expect($e->getMessage())->not->toContain('AK-LEAK-XYZ')->not->toContain('SK-LEAK-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SK-LEAK-ABC');
    }
});

test('upload SDK 抛网络类异常时脱敏只暴露类名（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('uploadCert')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect ssl.jdcloud-api.com with SK-LEAK-9999',
    ));

    $deployer = jdcloudSslDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK-LEAK-9999']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('SK-LEAK-9999');
        expect($e->getMessage())->toContain('京东云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

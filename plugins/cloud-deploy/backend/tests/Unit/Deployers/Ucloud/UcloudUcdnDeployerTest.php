<?php

use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUcdnDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('api')，故 mock 即覆盖上传 + 绑定路径。
 */
function ucloudUcdnDeployerWith(callable $clientFactory): UcloudUcdnDeployer
{
    return new class($clientFactory) extends UcloudUcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ucloudCreds(): array
{
    return ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => ''];
}

test('UCDN 走证书服务（storeKind=ucloud_ussl）', function () {
    $deployer = new UcloudUcdnDeployer;
    expect($deployer->provider())->toBe('ucloud');
    expect($deployer->product())->toBe('ucdn');
    expect($deployer->label())->toBe('优刻得 CDN');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('ucloud_ussl');
});

test('uploader.upload 调 UploadNormalCertificate 返回复合 "{certId}|{certName}"（base64 + md5 入参）', function () {
    $args = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('uploadNormalCertificate')
        ->once()
        ->andReturnUsing(function (string $name, string $pub, string $priv, string $md5, string $ca = '') use (&$args) {
            $args = compact('name', 'pub', 'priv', 'md5');

            return 98765;
        });

    $deployer = ucloudUcdnDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ucloudCreds());

    // 复合 remote_cert_id：certId|certName
    expect($ref)->toStartWith('98765|clouddeploy_');
    expect($args['name'])->toStartWith('clouddeploy_');
    // SslPublicKey = base64(cert+chain)；解码后含两段
    $decodedPub = base64_decode($args['pub']);
    expect($decodedPub)->toContain('CERTPEM')->toContain('CHAINPEM');
    // SslPrivateKey = base64(key)
    expect(base64_decode($args['priv']))->toContain('KEYPEM');
    // SslMD5 = md5(base64cert + base64key)，hex 32 位
    expect($args['md5'])->toBe(md5($args['pub'].$args['priv']));
    expect($args['md5'])->toMatch('/^[0-9a-f]{32}$/');
});

test('自定义 endpoint 透传到 uploader client 且私网地址被出站策略拒绝', function () {
    $seenEndpoint = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('uploadNormalCertificate')->once()->andReturn(123);
    $deployer = ucloudUcdnDeployerWith(function (string $kind, array $credentials) use (&$seenEndpoint, $client) {
        $seenEndpoint = $credentials['endpoint'] ?? null;

        return $client;
    });
    $deployer->certUploader(['endpoint' => 'https://api.example.com'])->upload('C', 'K', 'CH', ucloudCreds());
    expect($seenEndpoint)->toBe('https://api.example.com');

    $realFactory = new class extends UcloudUcdnDeployer
    {
        public function exposeClient(array $credentials): object
        {
            return $this->makeClient('api', $credentials);
        }
    };
    try {
        $realFactory->exposeClient(ucloudCreds() + ['endpoint' => 'http://127.0.0.1']);
        expect(false)->toBeTrue('私网 endpoint 应被拒绝');
    } catch (OutboundDestinationException $e) {
        expect($e->reasonCode())->toBe('forbidden_address');
    }
});

test('upload 未返回 CertificateID 抛明确异常', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('uploadNormalCertificate')->andReturn(0);

    $deployer = ucloudUcdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ucloudCreds()))
        ->toThrow(RuntimeException::class, 'CertificateID');
});

test('bind 读域名配置后调 UpdateUcdnDomainHttpsConfigV2（沿用原 HTTPS 状态 + 数字 certId + certName + CertType=ussl）', function () {
    $captured = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('getUcdnDomainConfig')->once()->with('domain-123')->andReturn([
        'DomainList' => [['HttpsStatusCn' => 'enable', 'HttpsStatusAbroad' => 'disable']],
    ]);
    $client->shouldReceive('updateUcdnDomainHttpsConfigV2')
        ->once()
        ->andReturnUsing(function (string $domainId, string $cn, string $abroad, int $certId, string $certName) use (&$captured) {
            $captured = compact('domainId', 'cn', 'abroad', 'certId', 'certName');
        });

    $deployer = ucloudUcdnDeployerWith(fn () => $client);
    $deployer->bind('98765|clouddeploy_123', ucloudCreds(), ['domain_id' => 'domain-123']);

    expect($captured)->toBe([
        'domainId' => 'domain-123',
        'cn' => 'enable',
        'abroad' => 'disable',
        'certId' => 98765,
        'certName' => 'clouddeploy_123',
    ]);
});

test('bind 域名不存在抛业务错误', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('getUcdnDomainConfig')->once()->andReturn(['DomainList' => []]);

    $deployer = ucloudUcdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind('1|n', ucloudCreds(), ['domain_id' => 'nope']))
        ->toThrow(RuntimeException::class, '未找到加速域名');
});

test('bind 收到无效 remote_cert_id 抛业务错误', function () {
    $deployer = ucloudUcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('no-separator', ucloudCreds(), ['domain_id' => 'd1']))
        ->toThrow(RuntimeException::class, '无效的优刻得 remote_cert_id');
});

test('缺 domain_id 配置抛业务错误', function () {
    $deployer = ucloudUcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1|n', ucloudCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain_id');
});

test('bind SDK 抛 UcloudApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('getUcdnDomainConfig')->andThrow(new UcloudApiException('171', 'invalid signature'));

    $deployer = ucloudUcdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('1|n', ['public_key' => 'PUB-LEAK-XYZ', 'private_key' => 'PRIV-LEAK-ABC'], ['domain_id' => 'd1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('171')->toContain('invalid signature');
        expect($e->getMessage())->not->toContain('PUB-LEAK-XYZ')->not->toContain('PRIV-LEAK-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('PRIV-LEAK-ABC');
    }
});

test('upload SDK 抛网络类异常时脱敏只暴露类名（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('uploadNormalCertificate')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect https://api.ucloud.cn with PRIV-LEAK-9999',
    ));

    $deployer = ucloudUcdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['public_key' => 'PUB', 'private_key' => 'PRIV-LEAK-9999']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('PRIV-LEAK-9999');
        expect($e->getMessage())->toContain('优刻得调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

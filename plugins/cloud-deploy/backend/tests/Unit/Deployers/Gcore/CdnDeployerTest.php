<?php

use Plugins\CloudDeploy\Deployers\Gcore\CdnDeployer;
use Plugins\CloudDeploy\Deployers\Gcore\GcoreApiException;
use Plugins\CloudDeploy\Deployers\Gcore\GcoreClient;
use Plugins\CloudDeploy\Deployers\Gcore\GcoreSslUploader;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock GcoreClient。 */
function gcoreCdnDeployerWith(callable $clientFactory): CdnDeployer
{
    return new class($clientFactory) extends CdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('Gcore CDN 为证书服务型（usesRemoteCertStore=true）+ 元信息', function () {
    $deployer = new CdnDeployer;
    expect($deployer->provider())->toBe('gcore');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(GcoreSslUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('gcore');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('resource_id');
});

test('uploader.upload 调 createSslData（sslCertificate=完整链、sslPrivateKey=私钥）返回 id', function () {
    $captured = null;
    $client = Mockery::mock(GcoreClient::class);
    $client->shouldReceive('createSslData')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;

        return 98765;
    });

    $deployer = gcoreCdnDeployerWith(fn () => $client);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['api_token' => 'TOKEN']);

    expect($id)->toBe('98765');
    // 字段名严格对齐 SDK（写错大小写会被静默丢成 null）
    expect($captured['sslCertificate'])->toBe("CERTPEM\nCHAINPEM");
    expect($captured['sslPrivateKey'])->toBe('KEYPEM');
    expect($captured['automated'])->toBeFalse();
    expect($captured['validate_root_ca'])->toBeFalse();
    expect($captured['name'])->toStartWith('clouddeploy_');
});

test('bind：GET 资源详情 → PUT 回写 sslEnabled=true + sslData=证书 id，保留其余字段', function () {
    $captured = null;
    $client = Mockery::mock(GcoreClient::class);
    $client->shouldReceive('getResource')->once()->with(111)->andReturn([
        'description' => 'my cdn',
        'active' => true,
        'originGroup' => 42,
        'originProtocol' => 'HTTPS',
        'secondaryHostnames' => ['a.example.com'],
        'proxy_ssl_enabled' => true,
        'proxy_ssl_ca' => 7,
        'proxy_ssl_data' => 0,
    ]);
    $client->shouldReceive('updateResource')->once()->andReturnUsing(function (int $resourceId, array $body) use (&$captured) {
        $captured = [$resourceId, $body];
    });

    $deployer = gcoreCdnDeployerWith(fn () => $client);
    $deployer->bind('98765', ['api_token' => 'TOKEN'], ['resource_id' => '111']);

    [$resourceId, $body] = $captured;
    expect($resourceId)->toBe(111);
    expect($body['sslEnabled'])->toBeTrue();
    expect($body['sslData'])->toBe(98765);
    expect($body['description'])->toBe('my cdn');
    expect($body['originGroup'])->toBe(42);
    expect($body['originProtocol'])->toBe('HTTPS');
    expect($body['secondaryHostnames'])->toBe(['a.example.com']);
    expect($body['proxy_ssl_enabled'])->toBeTrue();
    // 非零保留、零回写 null（对齐 certimate lo.Ternary）
    expect($body['proxy_ssl_ca'])->toBe(7);
    expect($body['proxy_ssl_data'])->toBeNull();
});

test('缺 resource_id 抛业务错误', function () {
    $deployer = gcoreCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('98765', ['api_token' => 'TOKEN'], []))
        ->toThrow(RuntimeException::class, '缺少配置 resource_id');
});

test('upload 未返回 id 抛明确异常', function () {
    $client = Mockery::mock(GcoreClient::class);
    $client->shouldReceive('createSslData')->andReturn(0);

    $deployer = gcoreCdnDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['api_token' => 'TOKEN']))
        ->toThrow(RuntimeException::class, '未返回证书 id');
});

test('bind 遇 GcoreApiException 时脱敏重抛（含状态码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(GcoreClient::class);
    $client->shouldReceive('getResource')->andThrow(new GcoreApiException('404', 'Resource not found'));

    $deployer = gcoreCdnDeployerWith(fn () => $client);
    try {
        $deployer->bind('98765', ['api_token' => 'TOKEN-LEAK-123'], ['resource_id' => '111']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('404')->toContain('Resource not found');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

test('upload 遇 GcoreApiException 脱敏重抛（无 token、不挂 previous）', function () {
    $client = Mockery::mock(GcoreClient::class);
    $client->shouldReceive('createSslData')->andThrow(new GcoreApiException('400', 'invalid certificate'));

    $deployer = gcoreCdnDeployerWith(fn () => $client);
    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['api_token' => 'TOKEN-LEAK-9']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('invalid certificate');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-9');
        expect($e->getPrevious())->toBeNull();
    }
});

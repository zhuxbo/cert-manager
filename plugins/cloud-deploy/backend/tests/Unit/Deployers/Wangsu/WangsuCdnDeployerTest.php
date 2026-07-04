<?php

use Plugins\CloudDeploy\Deployers\Wangsu\WangsuApiException;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCdnDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 * uploader 经 certUploader() 复用同一 makeClient('api')，故 mock 即覆盖上传 + 绑定路径。
 */
function wangsuCdnDeployerWith(callable $clientFactory): WangsuCdnDeployer
{
    return new class($clientFactory) extends WangsuCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('网宿云 CDN 走证书服务（storeKind=wangsu_certificate）+ 元信息', function () {
    $deployer = new WangsuCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('wangsu_certificate');
    expect($deployer->provider())->toBe('wangsu');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('网宿云 CDN');
});

test('uploader.upload 调 createCertificate（name 前缀、完整链、私钥）返回 certId', function () {
    $args = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')
        ->once()
        ->andReturnUsing(function (string $name, string $cert, string $key, string $comment) use (&$args) {
            $args = compact('name', 'cert', 'key', 'comment');

            return '100001';
        });

    $deployer = wangsuCdnDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $certId = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($certId)->toBe('100001');
    expect($args['name'])->toStartWith('clouddeploy_');
    // certificate 字段是完整链（证书 + 中间证书）
    expect($args['cert'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['key'])->toBe('KEYPEM');
});

test('upload 未返回 certId 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')->andReturn('');

    $deployer = wangsuCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']))
        ->toThrow(RuntimeException::class, 'certId');
});

test('bind 调 batchUpdateCertificateConfig（certId 转 int + 单域名数组）', function () {
    $captured = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('batchUpdateCertificateConfig')
        ->once()
        ->andReturnUsing(function (int $certId, array $domains) use (&$captured) {
            $captured = compact('certId', 'domains');
        });

    $deployer = wangsuCdnDeployerWith(fn () => $client);
    $deployer->bind('100001', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'cdn.example.com']);

    expect($captured)->toBe(['certId' => 100001, 'domains' => ['cdn.example.com']]);
});

test('bind exact 模式去掉域名前导 *（*.example.com → .example.com）', function () {
    $captured = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('batchUpdateCertificateConfig')
        ->once()
        ->andReturnUsing(function (int $certId, array $domains) use (&$captured) {
            $captured = $domains;
        });

    $deployer = wangsuCdnDeployerWith(fn () => $client);
    $deployer->bind('100001', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => '*.example.com']);

    expect($captured)->toBe(['.example.com']);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = wangsuCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('100001', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 WangsuApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('batchUpdateCertificateConfig')->andThrow(new WangsuApiException('400', 'domain not found'));

    $deployer = wangsuCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('100001', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类异常时脱敏只暴露类名（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect open.chinanetcenter.com with AK-LEAK-9999',
    ));

    $deployer = wangsuCdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999');
        expect($e->getMessage())->toContain('网宿云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

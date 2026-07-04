<?php

use Plugins\CloudDeploy\Deployers\Baishan\BaishanApiException;
use Plugins\CloudDeploy\Deployers\Baishan\BaishanCdnDeployer;
use Plugins\CloudDeploy\Deployers\Baishan\BaishanRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cdn）。
 * uploader 经 certUploader() 复用同一 makeClient('cdn')，故 mock cdn 即覆盖上传 + 配置路径。
 */
function baishanCdnDeployerWith(callable $clientFactory): BaishanCdnDeployer
{
    return new class($clientFactory) extends BaishanCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baishanCreds(): array
{
    return ['api_token' => 'TK'];
}

test('白山云 CDN：证书服务型（usesRemoteCertStore + storeKind baishan + 元信息）', function () {
    $deployer = new BaishanCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('baishan');
    expect($deployer->provider())->toBe('baishan');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('白山云 CDN');
});

test('uploader.upload 调 POST /v2/domain/certificate 返回 cert_id（certificate=完整链, key=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $body) use (&$captured) {
            $captured = compact('path', 'body');

            return ['code' => 0, 'data' => ['cert_id' => 90021]];
        });

    $deployer = baishanCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', baishanCreds());

    expect($id)->toBe('90021');
    expect($captured['path'])->toBe('/v2/domain/certificate');
    expect($captured['body']['name'])->toStartWith('clouddeploy_');
    expect($captured['body']['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['key'])->toBe('KEYPEM');
});

test('uploader：证书已存在（code 400699）→ 从 message 提取已有 cert_id 复用（幂等）', function () {
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('post')->andThrow(new BaishanApiException('400699', 'this certificate is exists, id: 54321'));

    $deployer = baishanCdnDeployerWith(fn () => $client);
    $id = $deployer->certUploader()->upload('C', 'K', 'CH', baishanCreds());

    expect($id)->toBe('54321');
});

test('upload 未返回 cert_id 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('post')->andReturn(['code' => 0, 'data' => []]);

    $deployer = baishanCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', baishanCreds()))
        ->toThrow(RuntimeException::class, 'cert_id');
});

test('bind：GetDomainConfig 取既有 https 配置 → SetDomainConfig 设 cert_id 并保留 force_https/http2/ocsp', function () {
    $calls = [];
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('get')
        ->once()
        ->andReturnUsing(function (string $path, array $query, array $arrayQuery) use (&$calls) {
            $calls[] = ['method' => 'get', 'path' => $path, 'query' => $query, 'arrayQuery' => $arrayQuery];

            return ['code' => 0, 'data' => [
                ['domain' => 'cdn.example.com', 'config' => ['https' => [
                    'cert_id' => '111', 'force_https' => '1', 'http2' => '1', 'ocsp' => '0',
                ]]],
            ]];
        });
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $body) use (&$calls) {
            $calls[] = ['method' => 'post', 'path' => $path, 'body' => $body];

            return ['code' => 0, 'data' => ['config' => []]];
        });

    $deployer = baishanCdnDeployerWith(fn () => $client);
    $deployer->bind('99999', baishanCreds(), ['domain' => 'cdn.example.com']);

    // GET 查询既有配置
    expect($calls[0]['path'])->toBe('/v2/domain/config');
    expect($calls[0]['query'])->toBe(['domains' => 'cdn.example.com']);
    expect($calls[0]['arrayQuery'])->toBe(['config' => ['https']]);

    // POST 设置新证书 + 保留既有 https 字段
    expect($calls[1]['path'])->toBe('/v2/domain/config');
    expect($calls[1]['body']['domains'])->toBe('cdn.example.com');
    expect($calls[1]['body']['config']['https']['cert_id'])->toBe('99999');
    expect($calls[1]['body']['config']['https']['force_https'])->toBe('1');
    expect($calls[1]['body']['config']['https']['http2'])->toBe('1');
    expect($calls[1]['body']['config']['https']['ocsp'])->toBe('0');
});

test('bind：域名不存在（GetDomainConfig data 空）→ 业务失败，不调 SetDomainConfig', function () {
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['code' => 0, 'data' => []]);
    $client->shouldReceive('post')->never();

    $deployer = baishanCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind('99999', baishanCreds(), ['domain' => 'cdn.example.com']))
        ->toThrow(RuntimeException::class, '未找到白山云域名');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = baishanCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1', baishanCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 BaishanApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('get')->andThrow(new BaishanApiException('InvalidParam', 'bad domain'));

    $deployer = baishanCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('1', ['api_token' => 'TK-SECRET-XYZ'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidParam')->toContain('bad domain');
        expect($e->getMessage())->not->toContain('TK-SECRET-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

test('bind SDK 抛网络类异常（含带 token 的 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(BaishanRestClient::class);
    $client->shouldReceive('get')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect https://cdn.api.baishan.com/v2/domain/config?token=TK-LEAK',
    ));

    $deployer = baishanCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('1', ['api_token' => 'TK-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('白山云调用失败');
        expect($e->getMessage())->not->toContain('TK-LEAK');
        expect($e->getMessage())->not->toContain('cdn.api.baishan.com');
        expect($e->getPrevious())->toBeNull();
    }
});

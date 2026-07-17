<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Rainyun\RainyunApiException;
use Plugins\CloudDeploy\Deployers\Rainyun\RainyunClient;
use Plugins\CloudDeploy\Deployers\Rainyun\RainyunSslcenterUploader;
use Plugins\CloudDeploy\Deployers\Rainyun\RcdnDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 真实自签证书（CN=example.com，SAN=example.com,www.example.com），供上传器的 list-match 比对：
 *   NotBefore(unix)=1782591813 / NotAfter(unix)=2097951813 / Domain(", " 连接)="example.com, www.example.com"
 *   SHA256 指纹=1154224f9f600ea120a0e2ca7c648c440401bc121fa8a114fd8c2d5bfa15d48e
 */
function rainyunTestCertPem(): string
{
    return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIC2jCCAcKgAwIBAgIJAICFCzbg+04DMA0GCSqGSIb3DQEBCwUAMBYxFDASBgNV
BAMMC2V4YW1wbGUuY29tMB4XDTI2MDYyNzIwMjMzM1oXDTM2MDYyNDIwMjMzM1ow
FjEUMBIGA1UEAwwLZXhhbXBsZS5jb20wggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQDb8DaixMgiFGsBU2OW/lttN3kagGC3zc40/S2dtcno16oo9AH65ZFT
tJQSd4hwtquU5dIe6RjK0oVpqB2APN5Lh4G+k6bjTB0QnscMN4oxBOnMK8Hxnh+2
2TxzsAvMDl7AUxjxAZUwhupDHMkWT8kzU8Et5Q+UaVdkEs6b3QSJOixovfBOpoIz
qD3L+6KK6WiBM8h//or0jq7Fw1cI+U0FaLVjNExG0jv+WzMQlu/O1C55NVezT+9d
WEl3uVHz8ZCgwNVENW+UXeaHLHrqAIjMeGx2oW0Tlfe/p9wyuFbO2zDPt+Gpjpom
KuWgF5EqY3CxSEsdVTociEgLf9vsF81XAgMBAAGjKzApMCcGA1UdEQQgMB6CC2V4
YW1wbGUuY29tgg93d3cuZXhhbXBsZS5jb20wDQYJKoZIhvcNAQELBQADggEBAAXe
SQDxXIkXCl1ND9uPUdH3uNMEJ2r3OT9ot/K0NwzBavTdy6cxv10pHhj9/1hEC54q
bA4fE+/zEa4OQk3Ls+rrgzYzTVDlKhzowb3dvZ0egEhwiiOp17PJ81cbouvYCNRk
aIWJAbQ/AwIbw90+eJGaY8EAkbqIcy3aBf/+B9sk/pPspjgYyDU6TW8WJ0eCyANk
XwRyWz1eCBeTv3HjL+aKfv2iLMHaqDEKzXzsiGylHGm42mOyLwvjGXELqfBATjVa
Qh0qLOaYs1swueHRM8AS6RmLPOS4i4aZHnampD1dHXy4PezDcPpEZeiZAjmiUL+I
VBAGVM8dCYMIo0QsvCQ=
-----END CERTIFICATE-----
PEM;
}

/** 测试子类：override makeClient（api kind）注入 mock RainyunClient。 */
function rainyunRcdnDeployerWith(callable $clientFactory): RcdnDeployer
{
    return new class($clientFactory) extends RcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

/** 构造注入 MockHandler 的真实 RainyunClient（用于上传器 list-match 线协议）。 */
function rainyunRcdnClientWithMock(array $responses, ArrayObject $history): RainyunClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://api.v2.rainyun.com/', 'headers' => ['X-API-Key' => 'KEY-X']]);

    return new RainyunClient($http);
}

test('雨云 RCDN 为证书服务型（usesRemoteCertStore=true）+ uploader storeKind + 元信息', function () {
    $deployer = new RcdnDeployer;
    expect($deployer->provider())->toBe('rainyun');
    expect($deployer->product())->toBe('rcdn');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    $uploader = $deployer->certUploader();
    expect($uploader)->toBeInstanceOf(RainyunSslcenterUploader::class);
    expect($uploader->storeKind())->toBe('rainyun_sslcenter');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('instance_id')->toContain('domain');
});

test('bind 调 rcdnInstanceSslBind（instanceId(int) + certId(int) + [domain]）', function () {
    $args = null;
    $client = Mockery::mock(RainyunClient::class);
    $client->shouldReceive('rcdnInstanceSslBind')->once()->andReturnUsing(function (int $instanceId, int $certId, array $domains) use (&$args) {
        $args = [$instanceId, $certId, $domains];
    });

    $deployer = rainyunRcdnDeployerWith(fn () => $client);
    $deployer->bind('456', ['api_key' => 'k'], ['instance_id' => '789', 'domain' => 'cdn.example.com']);

    [$instanceId, $certId, $domains] = $args;
    expect($instanceId)->toBe(789);
    expect($certId)->toBe(456);
    expect($domains)->toBe(['cdn.example.com']);
    expect($deployer->touchedConfigKeys())->toContain('instance_id')->toContain('domain');
});

test('缺 instance_id 抛业务错误', function () {
    $deployer = rainyunRcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1', ['api_key' => 'k'], ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 instance_id');
});

test('缺 domain 抛业务错误', function () {
    $deployer = rainyunRcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1', ['api_key' => 'k'], ['instance_id' => '1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 遇 RainyunApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(RainyunClient::class);
    $client->shouldReceive('rcdnInstanceSslBind')->andThrow(new RainyunApiException('404', '实例不存在'));

    $deployer = rainyunRcdnDeployerWith(fn () => $client);
    try {
        $deployer->bind('1', ['api_key' => 'KEY-LEAK-123'], ['instance_id' => '1', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('404')->toContain('实例不存在');
        expect($e->getMessage())->not->toContain('KEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
    }
});

// ============ 上传器（create + list-match 反查 id）============

test('上传器：已存在则直接返回 id（list-match 命中，不 create）', function () {
    $history = new ArrayObject;
    // 第 1 次 list（含匹配记录）→ get 详情（cert 内容匹配）→ 返回 id，不 create
    $client = rainyunRcdnClientWithMock([
        new Response(200, [], json_encode(['code' => 200, 'data' => ['TotalRecords' => 1, 'Records' => [
            ['ID' => 321, 'Domain' => 'example.com, www.example.com', 'StartDate' => 1782591813, 'ExpDate' => 2097951813],
        ]]])),
        new Response(200, [], json_encode(['code' => 200, 'data' => ['Cert' => rainyunTestCertPem()]])),
    ], $history);

    $uploader = new RainyunSslcenterUploader(fn (array $cred): object => $client);
    $id = $uploader->upload(rainyunTestCertPem(), 'KEYPEM', '', ['api_key' => 'k']);

    expect($id)->toBe('321');
    // 仅 2 次请求（list + get），无 create
    expect($history)->toHaveCount(2);
    /** @var RequestInterface $listReq */
    $listReq = $history[0]['request'];
    expect($listReq->getMethod())->toBe('GET');
    expect($listReq->getUri()->getPath())->toBe('/product/sslcenter');
    parse_str($listReq->getUri()->getQuery(), $q);
    expect($q)->toHaveKey('options');
    expect($q['options'])->toContain('example.com');
});

test('上传器：不存在则 create 后再 list-match 反查 id', function () {
    $history = new ArrayObject;
    $client = rainyunRcdnClientWithMock([
        // 1) 上传前 list：空
        new Response(200, [], json_encode(['code' => 200, 'data' => ['TotalRecords' => 0, 'Records' => []]])),
        // 2) create：无 id
        new Response(200, [], json_encode(['code' => 200, 'message' => 'ok'])),
        // 3) 反查 list：命中
        new Response(200, [], json_encode(['code' => 200, 'data' => ['TotalRecords' => 1, 'Records' => [
            ['ID' => 999, 'Domain' => 'example.com, www.example.com', 'StartDate' => 1782591813, 'ExpDate' => 2097951813],
        ]]])),
        // 4) get 详情：cert 匹配
        new Response(200, [], json_encode(['code' => 200, 'data' => ['Cert' => rainyunTestCertPem()]])),
    ], $history);

    $uploader = new RainyunSslcenterUploader(fn (array $cred): object => $client);
    $id = $uploader->upload(rainyunTestCertPem(), 'KEYPEM', '', ['api_key' => 'k']);

    expect($id)->toBe('999');
    expect($history)->toHaveCount(4);
    /** @var RequestInterface $createReq */
    $createReq = $history[1]['request'];
    expect($createReq->getMethod())->toBe('POST');
    expect($createReq->getUri()->getPath())->toBe('/product/sslcenter/');
});

test('上传器：create 后反查不到 id → 抛异常', function () {
    $history = new ArrayObject;
    $client = rainyunRcdnClientWithMock([
        new Response(200, [], json_encode(['code' => 200, 'data' => ['TotalRecords' => 0, 'Records' => []]])),
        new Response(200, [], json_encode(['code' => 200])),
        new Response(200, [], json_encode(['code' => 200, 'data' => ['TotalRecords' => 0, 'Records' => []]])),
    ], $history);

    $uploader = new RainyunSslcenterUploader(fn (array $cred): object => $client);
    expect(fn () => $uploader->upload(rainyunTestCertPem(), 'KEYPEM', '', ['api_key' => 'k']))
        ->toThrow(RuntimeException::class, '未能反查到证书 id');
});

test('上传器：SDK 抛 RainyunApiException 经脱敏（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(RainyunClient::class);
    $client->shouldReceive('sslCenterList')->andThrow(new RainyunApiException('401', '鉴权失败'));

    $uploader = new RainyunSslcenterUploader(fn (array $cred): object => $client);
    try {
        $uploader->upload(rainyunTestCertPem(), 'KEYPEM', '', ['api_key' => 'KEY-LEAK-123']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('401');
        expect($e->getMessage())->not->toContain('KEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Rainyun\RainyunApiException;
use Plugins\CloudDeploy\Deployers\Rainyun\RainyunClient;
use Plugins\CloudDeploy\Deployers\Rainyun\SslcenterDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock RainyunClient。 */
function rainyunSslcenterDeployerWith(callable $clientFactory): SslcenterDeployer
{
    return new class($clientFactory) extends SslcenterDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function rainyunSslcenterCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

/** 构造注入 MockHandler 的真实 RainyunClient，外发请求写入 $history。 */
function rainyunClientWithMock(array $responses, ArrayObject $history): RainyunClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://api.v2.rainyun.com/', 'headers' => ['X-API-Key' => 'KEY-X']]);

    return new RainyunClient($http);
}

test('雨云证书中心为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new SslcenterDeployer;
    expect($deployer->provider())->toBe('rainyun');
    expect($deployer->product())->toBe('sslcenter');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('未填 certificate_id → sslCenterCreate（cert+chain、key）', function () {
    $args = null;
    $client = Mockery::mock(RainyunClient::class);
    $client->shouldReceive('sslCenterCreate')->once()->andReturnUsing(function (string $cert, string $key) use (&$args) {
        $args = [$cert, $key];
    });
    $client->shouldNotReceive('sslCenterUpdate');

    $deployer = rainyunSslcenterDeployerWith(fn () => $client);
    $deployer->bind(rainyunSslcenterCertRef(), ['api_key' => 'k'], []);

    [$cert, $key] = $args;
    expect($cert)->toBe("CERTPEM\nCHAINPEM");
    expect($key)->toBe('KEYPEM');
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('填了 certificate_id → sslCenterUpdate（id 转 int）', function () {
    $args = null;
    $client = Mockery::mock(RainyunClient::class);
    $client->shouldReceive('sslCenterUpdate')->once()->andReturnUsing(function (int $id, string $cert, string $key) use (&$args) {
        $args = [$id, $cert, $key];
    });
    $client->shouldNotReceive('sslCenterCreate');

    $deployer = rainyunSslcenterDeployerWith(fn () => $client);
    $deployer->bind(rainyunSslcenterCertRef(), ['api_key' => 'k'], ['certificate_id' => '123']);

    [$id, $cert, $key] = $args;
    expect($id)->toBe(123);
    expect($cert)->toBe("CERTPEM\nCHAINPEM");
    expect($key)->toBe('KEYPEM');
});

test('bind 遇 RainyunApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(RainyunClient::class);
    $client->shouldReceive('sslCenterCreate')->andThrow(new RainyunApiException('400', '证书无效'));

    $deployer = rainyunSslcenterDeployerWith(fn () => $client);
    try {
        $deployer->bind(rainyunSslcenterCertRef(), ['api_key' => 'KEY-LEAK-123'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('证书无效');
        expect($e->getMessage())->not->toContain('KEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('KEY-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：sslCenterCreate POST /product/sslcenter/（带 X-API-Key 头，body cert/key）', function () {
    $history = new ArrayObject;
    $client = rainyunClientWithMock([new Response(200, [], json_encode(['code' => 200, 'message' => 'ok']))], $history);

    $client->sslCenterCreate('CERT', 'KEY');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/product/sslcenter/');
    expect($req->getHeaderLine('X-API-Key'))->toBe('KEY-X');
    $body = json_decode((string) $req->getBody(), true);
    expect($body['cert'])->toBe('CERT');
    expect($body['key'])->toBe('KEY');
});

test('client：sslCenterUpdate PUT /product/sslcenter/{id}', function () {
    $history = new ArrayObject;
    $client = rainyunClientWithMock([new Response(200, [], json_encode(['code' => 200]))], $history);

    $client->sslCenterUpdate(55, 'CERT', 'KEY');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect($req->getUri()->getPath())->toBe('/product/sslcenter/55');
});

test('client：code/100 != 2 → RainyunApiException（code + message）', function () {
    $client = rainyunClientWithMock([new Response(200, [], json_encode(['code' => 400, 'message' => '参数错误']))], new ArrayObject);

    try {
        $client->sslCenterCreate('C', 'K');
        expect(false)->toBeTrue('应抛异常');
    } catch (RainyunApiException $e) {
        expect($e->getErrorCode())->toBe('400');
        expect($e->getErrorMessage())->toBe('参数错误');
    }
});

test('client：HTTP 非 2xx → RainyunApiException（HTTP 状态码）', function () {
    $client = rainyunClientWithMock([new Response(500, [], 'err')], new ArrayObject);
    expect(fn () => $client->sslCenterCreate('C', 'K'))
        ->toThrow(RainyunApiException::class, '500');
});

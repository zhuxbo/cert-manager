<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Mohua\MohuaApiException;
use Plugins\CloudDeploy\Deployers\Mohua\MohuaClient;
use Plugins\CloudDeploy\Deployers\Mohua\MvhDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock MohuaClient。 */
function mohuaMvhDeployerWith(callable $clientFactory): MvhDeployer
{
    return new class($clientFactory) extends MvhDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function mohuaCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

/** 构造注入 MockHandler 的真实 MohuaClient，外发请求写入 $history。 */
function mohuaClientWithMock(array $responses, ArrayObject $history): MohuaClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    // base_uri 与真实 deployer 一致，使相对路径解析（v1/login_api → /v1/login_api）也被覆盖
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://cloud.mhjz1.cn/']);

    return new MohuaClient($http, 'user@example.com', 'secret-pass');
}

test('嘿华云虚拟主机为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new MvhDeployer;
    expect($deployer->provider())->toBe('mohua');
    expect($deployer->product())->toBe('mvh');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('host_id')->toContain('domain_id');
});

test('bind 调 setVirtualHostSsl（hostId + domainId(int) + cert+chain + key）', function () {
    $captured = null;
    $client = Mockery::mock(MohuaClient::class);
    $client->shouldReceive('setVirtualHostSsl')->once()->andReturnUsing(function (string $hostId, int $domainId, string $cert, string $key) use (&$captured) {
        $captured = [$hostId, $domainId, $cert, $key];
    });

    $deployer = mohuaMvhDeployerWith(fn () => $client);
    $deployer->bind(mohuaCertRef(), ['username' => 'u', 'api_password' => 'p'], ['host_id' => 'host-1', 'domain_id' => '42']);

    [$hostId, $domainId, $cert, $key] = $captured;
    expect($hostId)->toBe('host-1');
    expect($domainId)->toBe(42);
    expect($cert)->toBe("CERTPEM\nCHAINPEM");
    expect($key)->toBe('KEYPEM');
    expect($deployer->touchedConfigKeys())->toContain('host_id')->toContain('domain_id');
});

test('缺 host_id 抛业务错误', function () {
    $deployer = mohuaMvhDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(mohuaCertRef(), ['username' => 'u', 'api_password' => 'p'], ['domain_id' => '1']))
        ->toThrow(RuntimeException::class, '缺少配置 host_id');
});

test('缺 domain_id 抛业务错误', function () {
    $deployer = mohuaMvhDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(mohuaCertRef(), ['username' => 'u', 'api_password' => 'p'], ['host_id' => 'host-1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain_id');
});

test('bind 遇 MohuaApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(MohuaClient::class);
    $client->shouldReceive('setVirtualHostSsl')->andThrow(new MohuaApiException('403', '无权限'));

    $deployer = mohuaMvhDeployerWith(fn () => $client);
    try {
        $deployer->bind(mohuaCertRef(), ['username' => 'USER-LEAK', 'api_password' => 'PASS-LEAK-123'], ['host_id' => 'host-1', 'domain_id' => '1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('403')->toContain('无权限');
        expect($e->getMessage())->not->toContain('PASS-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('PASS-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：先 POST /v1/login_api 拿 jwt，再 POST SetSSL 带 JWT 头 + cert/key url-encode', function () {
    $history = new ArrayObject;
    $client = mohuaClientWithMock([
        new Response(200, [], json_encode(['status' => 200, 'jwt' => 'JWT-TOKEN-XYZ'])),
        new Response(200, [], json_encode(['status' => 200, 'data' => []])),
    ], $history);

    $client->setVirtualHostSsl('host-99', 7, "CERT\nLINE", 'KEY+VAL');

    // 第一次请求：登录
    /** @var RequestInterface $loginReq */
    $loginReq = $history[0]['request'];
    expect($loginReq->getMethod())->toBe('POST');
    expect($loginReq->getUri()->getPath())->toBe('/v1/login_api');
    expect($loginReq->getHeaderLine('JWT'))->toBe(''); // 登录请求不带 JWT
    $loginBody = json_decode((string) $loginReq->getBody(), true);
    expect($loginBody['account'])->toBe('user@example.com');
    expect($loginBody['password'])->toBe('secret-pass');

    // 第二次请求：SetSSL
    /** @var RequestInterface $sslReq */
    $sslReq = $history[1]['request'];
    expect($sslReq->getMethod())->toBe('POST');
    expect($sslReq->getUri()->getPath())->toBe('/provision/custom/host-99/domains');
    expect($sslReq->getHeaderLine('JWT'))->toBe('Bearer JWT-TOKEN-XYZ');
    $sslBody = json_decode((string) $sslReq->getBody(), true);
    expect($sslBody['func'])->toBe('SetSSL');
    expect($sslBody['id'])->toBe(7);
    expect($sslBody['ssl_force'])->toBe('');
    // cert/key 经 rawurlencode（换行 → %0A，"+" → %2B）
    expect($sslBody['sslCert'])->toBe(rawurlencode("CERT\nLINE"));
    expect($sslBody['sslKey'])->toBe(rawurlencode('KEY+VAL'));
});

test('client：登录响应 status!=200 → MohuaApiException（status + msg）', function () {
    $client = mohuaClientWithMock([
        new Response(200, [], json_encode(['status' => 401, 'msg' => '账号或密码错误'])),
    ], new ArrayObject);

    try {
        $client->setVirtualHostSsl('host-1', 1, 'C', 'K');
        expect(false)->toBeTrue('应抛异常');
    } catch (MohuaApiException $e) {
        expect($e->getErrorCode())->toBe('401');
        expect($e->getErrorMessage())->toBe('账号或密码错误');
    }
});

test('client：登录返回空 jwt → MohuaApiException', function () {
    $client = mohuaClientWithMock([
        new Response(200, [], json_encode(['status' => 200, 'jwt' => ''])),
    ], new ArrayObject);

    expect(fn () => $client->setVirtualHostSsl('host-1', 1, 'C', 'K'))
        ->toThrow(MohuaApiException::class, '未返回 token');
});

test('client：SetSSL 业务 status!=200 → MohuaApiException', function () {
    $client = mohuaClientWithMock([
        new Response(200, [], json_encode(['status' => 200, 'jwt' => 'T'])),
        new Response(200, [], json_encode(['status' => 500, 'msg' => '设置失败'])),
    ], new ArrayObject);

    expect(fn () => $client->setVirtualHostSsl('host-1', 1, 'C', 'K'))
        ->toThrow(MohuaApiException::class, '设置失败');
});

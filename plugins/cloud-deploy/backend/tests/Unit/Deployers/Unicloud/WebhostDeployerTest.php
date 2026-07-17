<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Unicloud\UnicloudApiException;
use Plugins\CloudDeploy\Deployers\Unicloud\UnicloudClient;
use Plugins\CloudDeploy\Deployers\Unicloud\WebhostDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock UnicloudClient。 */
function unicloudWebhostDeployerWith(callable $clientFactory): WebhostDeployer
{
    return new class($clientFactory) extends WebhostDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function unicloudCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function unicloudConfig(): array
{
    return ['space_provider' => 'aliyun', 'space_id' => 'space-1', 'domain' => 'web.example.com'];
}

/** 构造注入 MockHandler 的真实 UnicloudClient，外发请求写入 $history。 */
function unicloudClientWithMock(array $responses, ArrayObject $history, string $username = 'user@example.com'): UnicloudClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new UnicloudClient($http, $username, 'secret-pass');
}

/** 参考实现：uniCloud 签名（HMAC-MD5，键升序拼 k=v&...）——与被测代码独立的第二份实现。 */
function unicloudReferenceSign(array $payload, string $secret): string
{
    ksort($payload, SORT_STRING);
    $parts = [];
    foreach ($payload as $k => $v) {
        $sv = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        $parts[] = "$k=$sv";
    }

    return hash_hmac('md5', implode('&', $parts), $secret);
}

test('uniCloud 托管网站为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new WebhostDeployer;
    expect($deployer->provider())->toBe('unicloud');
    expect($deployer->product())->toBe('webhost');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('space_provider')->toContain('space_id')->toContain('domain');
});

test('bind 调 createDomainWithCert（provider/spaceId/domain + cert/key url-encode）', function () {
    $captured = null;
    $client = Mockery::mock(UnicloudClient::class);
    $client->shouldReceive('createDomainWithCert')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;
    });

    $deployer = unicloudWebhostDeployerWith(fn () => $client);
    $deployer->bind(unicloudCertRef(), ['username' => 'u', 'password' => 'p'], unicloudConfig());

    expect($captured['provider'])->toBe('aliyun');
    expect($captured['spaceId'])->toBe('space-1');
    expect($captured['domain'])->toBe('web.example.com');
    // cert = (cert+chain) url-encode，key = key url-encode
    expect($captured['cert'])->toBe(rawurlencode("CERTPEM\nCHAINPEM"));
    expect($captured['key'])->toBe(rawurlencode('KEYPEM'));
    expect($deployer->touchedConfigKeys())->toContain('space_provider')->toContain('space_id')->toContain('domain');
});

test('缺 space_provider 抛业务错误', function () {
    $deployer = unicloudWebhostDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(unicloudCertRef(), ['username' => 'u', 'password' => 'p'], ['space_id' => 's', 'domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 space_provider');
});

test('缺 space_id 抛业务错误', function () {
    $deployer = unicloudWebhostDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(unicloudCertRef(), ['username' => 'u', 'password' => 'p'], ['space_provider' => 'aliyun', 'domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 space_id');
});

test('缺 domain 抛业务错误', function () {
    $deployer = unicloudWebhostDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(unicloudCertRef(), ['username' => 'u', 'password' => 'p'], ['space_provider' => 'aliyun', 'space_id' => 's']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 遇 UnicloudApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UnicloudClient::class);
    $client->shouldReceive('createDomainWithCert')->andThrow(new UnicloudApiException('PERMISSION', '无权限'));

    $deployer = unicloudWebhostDeployerWith(fn () => $client);
    try {
        $deployer->bind(unicloudCertRef(), ['username' => 'USER-LEAK', 'password' => 'PASS-LEAK-123'], unicloudConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('PERMISSION')->toContain('无权限');
        expect($e->getMessage())->not->toContain('PASS-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('PASS-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：三步鉴权 + 业务请求（login → getUserToken → create-domain-with-cert，含签名/头）', function () {
    $history = new ArrayObject;
    $client = unicloudClientWithMock([
        // 1) serverless login → newToken
        new Response(200, [], json_encode(['success' => true, 'data' => ['newToken' => ['token' => 'SLT-123', 'tokenExpired' => (int) (microtime(true) * 1000) + 3600000]]])),
        // 2) serverless getUserToken → 嵌套 token
        new Response(200, [], json_encode(['success' => true, 'data' => ['data' => ['data' => ['token' => 'AUT-456']]]])),
        // 3) apiUser create-domain-with-cert
        new Response(200, [], json_encode(['ret' => 0, 'desc' => 'ok'])),
    ], $history);

    $client->createDomainWithCert(['provider' => 'aliyun', 'spaceId' => 's-1', 'domain' => 'd.example.com', 'cert' => 'C', 'key' => 'K']);

    expect($history)->toHaveCount(3);

    // 第 1 步：登录到 identity endpoint，HMAC-MD5 签名（参考实现重算一致）
    /** @var RequestInterface $loginReq */
    $loginReq = $history[0]['request'];
    expect($loginReq->getMethod())->toBe('POST');
    expect((string) $loginReq->getUri())->toBe('https://account.dcloud.net.cn/client');
    expect($loginReq->getHeaderLine('Origin'))->toBe('https://unicloud.dcloud.net.cn');
    expect($loginReq->getHeaderLine('X-Client-Info'))->not->toBe('');
    $loginPayload = json_decode((string) $loginReq->getBody(), true);
    expect($loginPayload['method'])->toBe('serverless.function.runtime.invoke');
    expect($loginPayload['spaceId'])->toBe('uni-id-server');
    expect($loginPayload)->toHaveKey('timestamp');
    // 用捕获 payload + identity client secret 重算签名，断言与请求头 X-Serverless-Sign 一致
    expect($loginReq->getHeaderLine('X-Serverless-Sign'))
        ->toBe(unicloudReferenceSign($loginPayload, 'ba461799-fde8-429f-8cc4-4b6d306e2339'));
    // 登录 params 含真实 password + email（账号是邮箱）
    $loginInner = json_decode($loginPayload['params'], true);
    expect($loginInner['functionTarget'])->toBe('uni-id-co');
    expect($loginInner['functionArgs']['method'])->toBe('login');
    expect($loginInner['functionArgs']['params'][0]['password'])->toBe('secret-pass');
    expect($loginInner['functionArgs']['params'][0]['email'])->toBe('user@example.com');

    // 第 2 步：getUserToken 到 console endpoint，带上一步拿到的 serverless token
    /** @var RequestInterface $tokenReq */
    $tokenReq = $history[1]['request'];
    expect((string) $tokenReq->getUri())->toBe('https://unicloud.dcloud.net.cn/client');
    expect($tokenReq->getHeaderLine('X-Client-Token'))->toBe('SLT-123');
    $tokenPayload = json_decode((string) $tokenReq->getBody(), true);
    expect($tokenPayload['spaceId'])->toBe('dc-6nfabcn6ada8d3dd');
    expect($tokenReq->getHeaderLine('X-Serverless-Sign'))
        ->toBe(unicloudReferenceSign($tokenPayload, '4c1f7fbf-c732-42b0-ab10-4634a8bbe834'));
    $tokenInner = json_decode($tokenPayload['params'], true);
    expect($tokenInner['functionTarget'])->toBe('uni-cloud-kernel');
    expect($tokenInner['functionArgs']['action'])->toBe('user/getUserToken');
    expect($tokenInner['functionArgs']['data']['isLogin'])->toBeTrue();

    // 第 3 步：业务请求带 apiUser Token 头
    /** @var RequestInterface $bizReq */
    $bizReq = $history[2]['request'];
    expect((string) $bizReq->getUri())->toBe('https://unicloud-api.dcloud.net.cn/unicloud/api/host/create-domain-with-cert');
    expect($bizReq->getHeaderLine('Token'))->toBe('AUT-456');
    $bizBody = json_decode((string) $bizReq->getBody(), true);
    expect($bizBody['provider'])->toBe('aliyun');
    expect($bizBody['domain'])->toBe('d.example.com');
});

test('client：账号是手机号时登录 params 用 mobile', function () {
    $history = new ArrayObject;
    $client = unicloudClientWithMock([
        new Response(200, [], json_encode(['success' => true, 'data' => ['newToken' => ['token' => 'T', 'tokenExpired' => (int) (microtime(true) * 1000) + 3600000]]])),
        new Response(200, [], json_encode(['success' => true, 'data' => ['data' => ['data' => ['token' => 'AUT']]]])),
        new Response(200, [], json_encode(['ret' => 0])),
    ], $history, '13800138000');

    $client->createDomainWithCert(['provider' => 'aliyun', 'spaceId' => 's', 'domain' => 'd.example.com']);

    $loginInner = json_decode(json_decode((string) $history[0]['request']->getBody(), true)['params'], true);
    expect($loginInner['functionArgs']['params'][0]['mobile'])->toBe('13800138000');
    expect($loginInner['functionArgs']['params'][0])->not->toHaveKey('email');
});

test('client：登录 success=false → UnicloudApiException（error.code + message）', function () {
    $client = unicloudClientWithMock([
        new Response(200, [], json_encode(['success' => false, 'error' => ['code' => 'uni-id-account-not-exists', 'message' => '账号不存在']])),
    ], new ArrayObject);

    try {
        $client->createDomainWithCert(['provider' => 'aliyun', 'spaceId' => 's', 'domain' => 'd']);
        expect(false)->toBeTrue('应抛异常');
    } catch (UnicloudApiException $e) {
        expect($e->getErrorCode())->toBe('uni-id-account-not-exists');
        expect($e->getErrorMessage())->toBe('账号不存在');
    }
});

test('client：业务请求 ret!=0 → UnicloudApiException（ret + desc）', function () {
    $client = unicloudClientWithMock([
        new Response(200, [], json_encode(['success' => true, 'data' => ['newToken' => ['token' => 'T', 'tokenExpired' => (int) (microtime(true) * 1000) + 3600000]]])),
        new Response(200, [], json_encode(['success' => true, 'data' => ['data' => ['data' => ['token' => 'AUT']]]])),
        new Response(200, [], json_encode(['ret' => 500, 'desc' => '域名不存在'])),
    ], new ArrayObject);

    try {
        $client->createDomainWithCert(['provider' => 'aliyun', 'spaceId' => 's', 'domain' => 'd']);
        expect(false)->toBeTrue('应抛异常');
    } catch (UnicloudApiException $e) {
        expect($e->getErrorCode())->toBe('500');
        expect($e->getErrorMessage())->toBe('域名不存在');
    }
});

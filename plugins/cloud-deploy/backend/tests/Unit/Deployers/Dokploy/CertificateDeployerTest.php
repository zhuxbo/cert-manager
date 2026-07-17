<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Dokploy\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Dokploy\DokployApiException;
use Plugins\CloudDeploy\Deployers\Dokploy\DokployClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock DokployClient。 */
function dokployDeployerWith(callable $clientFactory): CertificateDeployer
{
    return new class($clientFactory) extends CertificateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function dokployCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function dokployCreds(): array
{
    return ['server_url' => 'https://dokploy.example.com', 'api_key' => 'APIKEY'];
}

/** 构造注入 MockHandler 的真实 DokployClient，外发请求写入 $history。 */
function dokployClientWithMock(array $responses, ArrayObject $history): DokployClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://dokploy.example.com/api/', 'headers' => ['X-Api-Key' => 'APIKEY']]);

    return new DokployClient($http);
}

test('Dokploy 为内联型（usesRemoteCertStore=false）+ 元信息 + 无 config 字段', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('dokploy');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->configSchema())->toBe([]);
});

test('bind：去重未命中 → user.get 取 orgId → certificates.create（完整链 + key + organizationId）', function () {
    $createBody = null;
    $client = Mockery::mock(DokployClient::class);
    $client->shouldReceive('certificatesAll')->once()->andReturn([
        ['certificateData' => 'other', 'privateKey' => 'other-key'],
    ]);
    $client->shouldReceive('userGetOrganizationId')->once()->andReturn('org-1');
    $client->shouldReceive('certificatesCreate')->once()->andReturnUsing(function (array $body) use (&$createBody) {
        $createBody = $body;

        return ['certificateId' => 'cert-new', 'name' => $body['name']];
    });

    $deployer = dokployDeployerWith(fn () => $client);
    $deployer->bind(dokployCertRef(), dokployCreds(), []);

    expect($createBody['certificateData'])->toBe("CERTPEM\nCHAINPEM");
    expect($createBody['privateKey'])->toBe('KEYPEM');
    expect($createBody['organizationId'])->toBe('org-1');
    expect($createBody['name'])->toStartWith('clouddeploy-');
});

test('bind：去重命中（相同 certificateData + privateKey）则跳过，不创建', function () {
    $client = Mockery::mock(DokployClient::class);
    $client->shouldReceive('certificatesAll')->once()->andReturn([
        ['certificateData' => "CERTPEM\nCHAINPEM", 'privateKey' => 'KEYPEM'],
    ]);
    $client->shouldReceive('userGetOrganizationId')->never();
    $client->shouldReceive('certificatesCreate')->never();

    $deployer = dokployDeployerWith(fn () => $client);
    $deployer->bind(dokployCertRef(), dokployCreds(), []);

    expect(true)->toBeTrue();
});

test('bind 遇 DokployApiException 时脱敏重抛（含错误码、无 api key、不挂 previous）', function () {
    $client = Mockery::mock(DokployClient::class);
    $client->shouldReceive('certificatesAll')->andThrow(new DokployApiException('401', 'Unauthorized'));

    $deployer = dokployDeployerWith(fn () => $client);
    try {
        $deployer->bind(dokployCertRef(), ['server_url' => 'https://dokploy.example.com', 'api_key' => 'APIKEY-LEAK-123'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('401')->toContain('Unauthorized');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：certificatesAll GET /api/certificates.all（带 X-Api-Key 头），返回数组', function () {
    $history = new ArrayObject;
    $client = dokployClientWithMock([
        new Response(200, [], json_encode([['certificateId' => 'a', 'name' => 'A'], ['certificateId' => 'b', 'name' => 'B']])),
    ], $history);

    $list = $client->certificatesAll();

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getPath())->toBe('/api/certificates.all');
    expect($req->getHeaderLine('X-Api-Key'))->toBe('APIKEY');
    expect($list)->toHaveCount(2);
    expect($list[0]['certificateId'])->toBe('a');
});

test('client：userGetOrganizationId GET /api/user.get → organizationId', function () {
    $history = new ArrayObject;
    $client = dokployClientWithMock([new Response(200, [], json_encode(['organizationId' => 'org-xyz', 'id' => 'm1']))], $history);

    $orgId = $client->userGetOrganizationId();

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getPath())->toBe('/api/user.get');
    expect($orgId)->toBe('org-xyz');
});

test('client：certificatesCreate POST /api/certificates.create → {certificateId, name}', function () {
    $history = new ArrayObject;
    $client = dokployClientWithMock([new Response(200, [], json_encode(['certificateId' => 'cid', 'name' => 'nm']))], $history);

    $res = $client->certificatesCreate(['name' => 'nm', 'certificateData' => 'C', 'privateKey' => 'K', 'organizationId' => 'o']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/api/certificates.create');
    expect($res)->toBe(['certificateId' => 'cid', 'name' => 'nm']);
});

test('client：HTTP 非 2xx → DokployApiException（HTTP 状态码 + 响应体 message）', function () {
    $client = dokployClientWithMock([new Response(403, [], json_encode(['message' => 'Forbidden resource']))], new ArrayObject);
    try {
        $client->certificatesAll();
        expect(false)->toBeTrue('应抛异常');
    } catch (DokployApiException $e) {
        expect($e->getErrorCode())->toBe('403');
        expect($e->getErrorMessage())->toBe('Forbidden resource');
    }
});

test('makeClient：allow_insecure_connections=true 时不抛、能造出 DokployClient', function () {
    $deployer = new CertificateDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', ['server_url' => 'https://x', 'api_key' => 'k', 'allow_insecure_connections' => true]);
    expect($client)->toBeInstanceOf(DokployClient::class);
});

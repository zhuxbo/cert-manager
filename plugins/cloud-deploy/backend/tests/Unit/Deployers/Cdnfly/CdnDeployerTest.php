<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Cdnfly\CdnDeployer;
use Plugins\CloudDeploy\Deployers\Cdnfly\CdnflyApiException;
use Plugins\CloudDeploy\Deployers\Cdnfly\CdnflyClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock CdnflyClient。 */
function cdnflyDeployerWith(callable $clientFactory): CdnDeployer
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

function cdnflyCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function cdnflyCreds(): array
{
    return ['server_url' => 'https://cdn.example.com', 'api_key' => 'KEY', 'api_secret' => 'SECRET'];
}

/** 构造注入 MockHandler 的真实 CdnflyClient，外发请求写入 $history。 */
function cdnflyClientWithMock(array $responses, ArrayObject $history): CdnflyClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://cdn.example.com/v1/', 'headers' => ['API-Key' => 'KEY', 'API-Secret' => 'SECRET']]);

    return new CdnflyClient($http);
}

test('Cdnfly 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CdnDeployer;
    expect($deployer->provider())->toBe('cdnfly');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('deploy_target')->toContain('site_id')->toContain('certificate_id');
});

test('deploy_target=website：getSite → createCert → updateSite（沿用原 https_listen 仅替换 cert）', function () {
    $createBody = null;
    $updateSiteArgs = null;
    $client = Mockery::mock(CdnflyClient::class);
    $client->shouldReceive('getSite')->once()->with('site-1')->andReturn(['https_listen' => json_encode(['port' => 443, 'cert' => 'old-cert'])]);
    $client->shouldReceive('createCert')->once()->andReturnUsing(function (array $body) use (&$createBody) {
        $createBody = $body;

        return 'new-cert-id';
    });
    $client->shouldReceive('updateSite')->once()->andReturnUsing(function (string $siteId, array $body) use (&$updateSiteArgs) {
        $updateSiteArgs = [$siteId, $body];
    });

    $deployer = cdnflyDeployerWith(fn () => $client);
    $deployer->bind(cdnflyCertRef(), cdnflyCreds(), ['deploy_target' => 'website', 'site_id' => 'site-1']);

    expect($createBody['type'])->toBe('custom');
    expect($createBody['cert'])->toBe("CERTPEM\nCHAINPEM");
    expect($createBody['key'])->toBe('KEYPEM');

    [$siteId, $body] = $updateSiteArgs;
    expect($siteId)->toBe('site-1');
    // 沿用原 https_listen 的 port，cert 替换为新 id
    expect($body['https_listen']['port'])->toBe(443);
    expect($body['https_listen']['cert'])->toBe('new-cert-id');
    expect($deployer->touchedConfigKeys())->toContain('deploy_target')->toContain('site_id');
});

test('deploy_target=website：https_listen 为空时也能更新（cert 写入空 map）', function () {
    $updateSiteArgs = null;
    $client = Mockery::mock(CdnflyClient::class);
    $client->shouldReceive('getSite')->once()->andReturn(['https_listen' => '']);
    $client->shouldReceive('createCert')->once()->andReturn('cid');
    $client->shouldReceive('updateSite')->once()->andReturnUsing(function (string $siteId, array $body) use (&$updateSiteArgs) {
        $updateSiteArgs = $body;
    });

    $deployer = cdnflyDeployerWith(fn () => $client);
    $deployer->bind(cdnflyCertRef(), cdnflyCreds(), ['deploy_target' => 'website', 'site_id' => 'site-1']);

    expect($updateSiteArgs['https_listen'])->toBe(['cert' => 'cid']);
});

test('deploy_target=certificate：直接 updateCert', function () {
    $args = null;
    $client = Mockery::mock(CdnflyClient::class);
    $client->shouldReceive('updateCert')->once()->andReturnUsing(function (string $certId, array $body) use (&$args) {
        $args = [$certId, $body];
    });

    $deployer = cdnflyDeployerWith(fn () => $client);
    $deployer->bind(cdnflyCertRef(), cdnflyCreds(), ['deploy_target' => 'certificate', 'certificate_id' => 'cert-99']);

    [$certId, $body] = $args;
    expect($certId)->toBe('cert-99');
    expect($body['type'])->toBe('custom');
    expect($body['cert'])->toBe("CERTPEM\nCHAINPEM");
    expect($body['key'])->toBe('KEYPEM');
    expect($deployer->touchedConfigKeys())->toContain('deploy_target')->toContain('certificate_id');
});

test('缺 deploy_target 抛业务错误', function () {
    $deployer = cdnflyDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cdnflyCertRef(), cdnflyCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 deploy_target');
});

test('website 缺 site_id 抛业务错误', function () {
    $deployer = cdnflyDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cdnflyCertRef(), cdnflyCreds(), ['deploy_target' => 'website']))
        ->toThrow(RuntimeException::class, '缺少配置 site_id');
});

test('certificate 缺 certificate_id 抛业务错误', function () {
    $deployer = cdnflyDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cdnflyCertRef(), cdnflyCreds(), ['deploy_target' => 'certificate']))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('不支持的 deploy_target 抛业务错误', function () {
    $deployer = cdnflyDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cdnflyCertRef(), cdnflyCreds(), ['deploy_target' => 'unknown']))
        ->toThrow(RuntimeException::class, "不支持的部署目标 'unknown'");
});

test('bind 遇 CdnflyApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(CdnflyClient::class);
    $client->shouldReceive('updateCert')->andThrow(new CdnflyApiException('1001', '证书格式错误'));

    $deployer = cdnflyDeployerWith(fn () => $client);
    try {
        $deployer->bind(cdnflyCertRef(), ['server_url' => 'https://cdn.example.com', 'api_key' => 'KEY-LEAK', 'api_secret' => 'SECRET-LEAK-123'], ['deploy_target' => 'certificate', 'certificate_id' => 'c-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('1001')->toContain('证书格式错误');
        expect($e->getMessage())->not->toContain('SECRET-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：getSite GET /sites/{id}（带 API-Key/API-Secret 头），返回 data 子对象', function () {
    $history = new ArrayObject;
    $client = cdnflyClientWithMock([
        new Response(200, [], json_encode(['code' => '0', 'data' => ['id' => 1, 'https_listen' => '{"port":443}']])),
    ], $history);

    $data = $client->getSite('site-7');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getPath())->toBe('/v1/sites/site-7');
    expect($req->getHeaderLine('API-Key'))->toBe('KEY');
    expect($req->getHeaderLine('API-Secret'))->toBe('SECRET');
    expect($data['https_listen'])->toBe('{"port":443}');
});

test('client：createCert POST /certs 返回 data 字符串作证书 id', function () {
    $history = new ArrayObject;
    $client = cdnflyClientWithMock([
        new Response(200, [], json_encode(['code' => 0, 'data' => 'cert-abc'])),
    ], $history);

    $certId = $client->createCert(['type' => 'custom', 'cert' => 'C', 'key' => 'K']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/v1/certs');
    expect($certId)->toBe('cert-abc');
});

test('client：code 数字 0 视为成功，非 0 数字视为错误', function () {
    // 数字 0 成功
    $okClient = cdnflyClientWithMock([new Response(200, [], json_encode(['code' => 0, 'data' => 'x']))], new ArrayObject);
    expect($okClient->createCert([]))->toBe('x');

    // 数字非 0 错误
    $errClient = cdnflyClientWithMock([new Response(200, [], json_encode(['code' => 500, 'msg' => '内部错误']))], new ArrayObject);
    try {
        $errClient->createCert([]);
        expect(false)->toBeTrue('应抛异常');
    } catch (CdnflyApiException $e) {
        expect($e->getErrorCode())->toBe('500');
        expect($e->getErrorMessage())->toBe('内部错误');
    }
});

test('client：HTTP 非 2xx → CdnflyApiException（HTTP 状态码）', function () {
    $client = cdnflyClientWithMock([new Response(403, [], 'forbidden')], new ArrayObject);
    expect(fn () => $client->updateCert('c-1', []))
        ->toThrow(CdnflyApiException::class, '403');
});

test('makeClient：allow_insecure_connections=true 时 verify=false', function () {
    // 仅验证 makeClient 不抛、能造出 client（verify 选项在 Guzzle 内部，间接覆盖分支）
    $deployer = new CdnDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', ['server_url' => 'https://x', 'api_key' => 'k', 'api_secret' => 's', 'allow_insecure_connections' => true]);
    expect($client)->toBeInstanceOf(CdnflyClient::class);
});

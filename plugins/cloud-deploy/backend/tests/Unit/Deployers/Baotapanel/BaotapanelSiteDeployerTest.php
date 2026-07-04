<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelApiException;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelClient;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelSiteDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BaotapanelClient。 */
function baotapanelSiteDeployerWith(callable $clientFactory): BaotapanelSiteDeployer
{
    return new class($clientFactory) extends BaotapanelSiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baotaSiteCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function baotaSiteCreds(): array
{
    return ['server_url' => 'https://panel.example.com:8888', 'api_key' => 'KEY'];
}

test('宝塔面板网站为内联型 + 元信息', function () {
    $deployer = new BaotapanelSiteDeployer;
    expect($deployer->provider())->toBe('baotapanel');
    expect($deployer->product())->toBe('site');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_type')->toContain('site_names');
});

test('普通站点（空类型）：逐站点 siteSetSSL（完整链）', function () {
    $calls = [];
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('siteSetSSL')->twice()->andReturnUsing(function (string $site, string $cert, string $key) use (&$calls) {
        $calls[] = [$site, $cert, $key];
    });
    $client->shouldReceive('modProxyComSetSSL')->never();

    $deployer = baotapanelSiteDeployerWith(fn () => $client);
    $deployer->bind(baotaSiteCertRef(), baotaSiteCreds(), ['site_names' => 'a.com, b.com']);

    expect($calls)->toHaveCount(2);
    expect($calls[0][0])->toBe('a.com');
    expect($calls[0][1])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($calls[1][0])->toBe('b.com');
});

test('代理站点（proxy）：逐站点 modProxyComSetSSL', function () {
    $calls = [];
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('modProxyComSetSSL')->once()->andReturnUsing(function (string $site) use (&$calls) {
        $calls[] = $site;
    });
    $client->shouldReceive('siteSetSSL')->never();

    $deployer = baotapanelSiteDeployerWith(fn () => $client);
    $deployer->bind(baotaSiteCertRef(), baotaSiteCreds(), ['site_type' => 'proxy', 'site_names' => 'p.com']);

    expect($calls)->toBe(['p.com']);
});

test('any 批量：v1 成功（SaveCert→SetBatchCertToSite），不走 v2', function () {
    $hash = null;
    $batch = null;
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('sslCertSaveCert')->once()->andReturn('HASH-V1');
    $client->shouldReceive('sslSetBatchCertToSite')->once()->andReturnUsing(function (string $h, array $sites) use (&$hash, &$batch) {
        $hash = $h;
        $batch = $sites;
    });
    $client->shouldReceive('sslDomainUploadCertV2')->never();
    $client->shouldReceive('sslDomainCertDeploySitesV2')->never();

    $deployer = baotapanelSiteDeployerWith(fn () => $client);
    $deployer->bind(baotaSiteCertRef(), baotaSiteCreds(), ['site_type' => 'any', 'site_names' => "a.com\nb.com"]);

    expect($hash)->toBe('HASH-V1');
    expect($batch)->toBe(['a.com', 'b.com']);
});

test('any 批量：v1 失败回退 v2（UploadCert→CertDeploySites）', function () {
    $hash = null;
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('sslCertSaveCert')->once()->andThrow(new BaotapanelApiException('BaotaError', 'v1 unavailable'));
    $client->shouldReceive('sslSetBatchCertToSite')->never();
    $client->shouldReceive('sslDomainUploadCertV2')->once()->andReturn('HASH-V2');
    $client->shouldReceive('sslDomainCertDeploySitesV2')->once()->andReturnUsing(function (string $h, array $sites) use (&$hash) {
        $hash = [$h, $sites];
    });

    $deployer = baotapanelSiteDeployerWith(fn () => $client);
    $deployer->bind(baotaSiteCertRef(), baotaSiteCreds(), ['site_type' => 'any', 'site_names' => 'a.com']);

    expect($hash)->toBe(['HASH-V2', ['a.com']]);
});

test('不支持的站点类型抛业务错误', function () {
    $deployer = baotapanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(baotaSiteCertRef(), baotaSiteCreds(), ['site_type' => 'weird', 'site_names' => 'a.com']))
        ->toThrow(RuntimeException::class, '不支持的网站类型');
});

test('缺 site_names 抛业务错误', function () {
    $deployer = baotapanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(baotaSiteCertRef(), baotaSiteCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 site_names');
});

test('bind 遇 BaotapanelApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('siteSetSSL')->andThrow(new BaotapanelApiException('BaotaError', '站点不存在'));

    $deployer = baotapanelSiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(baotaSiteCertRef(), ['server_url' => 'https://p', 'api_key' => 'APIKEY-LEAK-3'], ['site_names' => 'x.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('站点不存在');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-3');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-3');
    }
});

// ============ 线协议 / 签名（真实 BaotapanelClient + MockHandler）============

function baotapanelClientWithMock(array $responses, ArrayObject $history): BaotapanelClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://panel.example.com:8888/', 'http_errors' => false]);

    return new BaotapanelClient($http, 'SK-SECRET');
}

test('线协议：siteSetSSL 发表单 + 签名 request_token=md5(request_time + md5(apiKey))', function () {
    $history = new ArrayObject;
    $client = baotapanelClientWithMock([new Response(200, [], json_encode(['status' => true, 'msg' => 'ok']))], $history);

    $client->siteSetSSL('a.com', 'CERT', 'KEY');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/site');
    expect($req->getUri()->getQuery())->toBe('action=SetSSL');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/x-www-form-urlencoded');

    parse_str((string) $req->getBody(), $form);
    expect($form['type'])->toBe('0');
    expect($form['siteName'])->toBe('a.com');
    expect($form['csr'])->toBe('CERT');
    expect($form['key'])->toBe('KEY');
    // 签名：request_token = md5(request_time + md5(apiKey))
    expect($form)->toHaveKey('request_time')->toHaveKey('request_token');
    $expected = md5($form['request_time'].md5('SK-SECRET'));
    expect($form['request_token'])->toBe($expected);
});

test('线协议：v1 status=false → BaotapanelApiException（带 msg）', function () {
    $history = new ArrayObject;
    $client = baotapanelClientWithMock([new Response(200, [], json_encode(['status' => false, 'msg' => '证书错误']))], $history);

    expect(fn () => $client->siteSetSSL('a.com', 'C', 'K'))
        ->toThrow(BaotapanelApiException::class, '证书错误');
});

test('线协议：v2 upload_cert 解析 message.hash；status=0 视为成功', function () {
    $history = new ArrayObject;
    $client = baotapanelClientWithMock([
        new Response(200, [], json_encode(['status' => 0, 'message' => ['hash' => 'HASH-XYZ']])),
    ], $history);

    $hash = $client->sslDomainUploadCertV2('CERT', 'KEY');
    expect($hash)->toBe('HASH-XYZ');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getPath())->toBe('/v2/ssl_domain');
    expect($req->getUri()->getQuery())->toBe('action=upload_cert');
    parse_str((string) $req->getBody(), $form);
    expect($form['cert'])->toBe('CERT');
    expect($form['key'])->toBe('KEY');
});

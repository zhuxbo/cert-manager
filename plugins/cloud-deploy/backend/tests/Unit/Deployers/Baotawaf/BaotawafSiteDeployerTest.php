<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafApiException;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafClient;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafSiteDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BaotawafClient。 */
function baotawafSiteDeployerWith(callable $clientFactory): BaotawafSiteDeployer
{
    return new class($clientFactory) extends BaotawafSiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function btwafSiteCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function btwafSiteCreds(): array
{
    return ['server_url' => 'https://waf.example.com', 'api_key' => 'KEY'];
}

test('堡塔云 WAF 网站为内联型 + 元信息', function () {
    $deployer = new BaotawafSiteDeployer;
    expect($deployer->provider())->toBe('baotawaf');
    expect($deployer->product())->toBe('site');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_names')->toContain('site_port');
});

test('查站点 → modifySiteCertificate（site_id, 端口默认 443, full_chain 完整链）', function () {
    $captured = null;
    $client = Mockery::mock(BaotawafClient::class);
    $client->shouldReceive('getSiteList')->once()->with('a.com', 1, 100)->andReturn([
        ['site_id' => 'sid-1', 'site_name' => 'a.com'],
    ]);
    $client->shouldReceive('modifySiteCertificate')->once()->andReturnUsing(function (string $id, int $port, string $cert, string $key) use (&$captured) {
        $captured = compact('id', 'port', 'cert', 'key');
    });

    $deployer = baotawafSiteDeployerWith(fn () => $client);
    $deployer->bind(btwafSiteCertRef(), btwafSiteCreds(), ['site_names' => 'a.com']);

    expect($captured['id'])->toBe('sid-1');
    expect($captured['port'])->toBe(443);
    expect($captured['cert'])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('自定义端口 + 多站点', function () {
    $ports = [];
    $client = Mockery::mock(BaotawafClient::class);
    $client->shouldReceive('getSiteList')->andReturn(
        [['site_id' => 's1', 'site_name' => 'a.com']],
        [['site_id' => 's2', 'site_name' => 'b.com']],
    );
    $client->shouldReceive('modifySiteCertificate')->twice()->andReturnUsing(function (string $id, int $port) use (&$ports) {
        $ports[] = [$id, $port];
    });

    $deployer = baotawafSiteDeployerWith(fn () => $client);
    $deployer->bind(btwafSiteCertRef(), btwafSiteCreds(), ['site_names' => 'a.com,b.com', 'site_port' => '8443']);

    expect($ports)->toBe([['s1', 8443], ['s2', 8443]]);
});

test('站点未找到抛错', function () {
    $client = Mockery::mock(BaotawafClient::class);
    $client->shouldReceive('getSiteList')->andReturn([['site_id' => 's9', 'site_name' => 'other.com']]);
    $client->shouldReceive('modifySiteCertificate')->never();

    $deployer = baotawafSiteDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind(btwafSiteCertRef(), btwafSiteCreds(), ['site_names' => 'a.com']))
        ->toThrow(RuntimeException::class, '未找到站点');
});

test('缺 site_names 抛业务错误', function () {
    $deployer = baotawafSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(btwafSiteCertRef(), btwafSiteCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 site_names');
});

test('bind 遇 BaotawafApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(BaotawafClient::class);
    $client->shouldReceive('getSiteList')->andThrow(new BaotawafApiException('-1', '鉴权失败'));

    $deployer = baotawafSiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(btwafSiteCertRef(), ['server_url' => 'https://w', 'api_key' => 'APIKEY-LEAK-8'], ['site_names' => 'x.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('鉴权失败');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-8');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-8');
    }
});

// ============ 线协议 / 签名（真实 BaotawafClient + MockHandler）============

function baotawafClientWithMock(array $responses, ArrayObject $history): BaotawafClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://waf.example.com/api/', 'http_errors' => false]);

    return new BaotawafClient($http, 'SK-SECRET');
}

test('线协议：modifySiteCertificate 发 JSON + 签名头 waf_request_token=md5(time + md5(apiKey))', function () {
    $history = new ArrayObject;
    $client = baotawafClientWithMock([new Response(200, [], json_encode(['code' => 0]))], $history);

    $client->modifySiteCertificate('sid-1', 443, 'CERT', 'KEY');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/api/wafmastersite/modify_site');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');

    $time = $req->getHeaderLine('waf_request_time');
    $token = $req->getHeaderLine('waf_request_token');
    expect($time)->not->toBe('');
    expect($token)->toBe(md5($time.md5('SK-SECRET')));

    $body = json_decode((string) $req->getBody(), true);
    expect($body['site_id'])->toBe('sid-1');
    expect($body['types'])->toBe('openCert');
    expect($body['server']['listen_ssl_port'])->toBe(['443']);
    expect($body['server']['ssl']['is_ssl'])->toBe(1);
    expect($body['server']['ssl']['full_chain'])->toBe('CERT');
    expect($body['server']['ssl']['private_key'])->toBe('KEY');
});

test('线协议：code != 0 → BaotawafApiException', function () {
    $history = new ArrayObject;
    $client = baotawafClientWithMock([new Response(200, [], json_encode(['code' => -1, 'msg' => '失败']))], $history);

    expect(fn () => $client->configSetCert('C', 'K'))
        ->toThrow(BaotawafApiException::class, '失败');
});

test('线协议：getSiteList 抽取 res.list', function () {
    $history = new ArrayObject;
    $client = baotawafClientWithMock([
        new Response(200, [], json_encode(['code' => 0, 'res' => ['list' => [['site_id' => 's1', 'site_name' => 'a.com'], 'junk'], 'total' => 1]])),
    ], $history);

    $list = $client->getSiteList('a.com', 1, 100);
    expect($list)->toBe([['site_id' => 's1', 'site_name' => 'a.com']]);
});

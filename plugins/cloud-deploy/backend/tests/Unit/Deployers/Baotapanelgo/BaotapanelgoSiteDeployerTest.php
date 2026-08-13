<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoApiException;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoClient;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoSiteDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BaotapanelgoClient。 */
function baotapanelgoSiteDeployerWith(callable $clientFactory): BaotapanelgoSiteDeployer
{
    return new class($clientFactory) extends BaotapanelgoSiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baotagoSiteCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function baotagoSiteCreds(): array
{
    return ['server_url' => 'https://panel.example.com:8888', 'api_key' => 'KEY'];
}

test('宝塔（Windows）网站为内联型 + 元信息', function () {
    $deployer = new BaotapanelgoSiteDeployer;
    expect($deployer->provider())->toBe('baotapanelgo');
    expect($deployer->product())->toBe('site');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_type')->toContain('site_names');
});

test('非 IIS 服务器 + 非 IIS 类型站点（java）：get_project_list 查 ID → siteSetSiteSSL（完整链）', function () {
    $captured = null;
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('panelGetConfig')->once()->andReturn(['site' => ['webserver' => 'nginx']]);
    // java 非 IIS 类型 → 走 get_project_list（php/asp/aspx 才走 datalist，对齐 certimate）
    $client->shouldReceive('siteGetProjectList')->once()->with('java', 'a.com', 1, 10)->andReturn([
        ['id' => 12, 'name' => 'a.com'],
    ]);
    $client->shouldReceive('datalistGetDataList')->never();
    $client->shouldReceive('siteSetSiteSSL')->once()->andReturnUsing(function (int $id, bool $status, string $cert, string $key) use (&$captured) {
        $captured = compact('id', 'status', 'cert', 'key');
    });

    $deployer = baotapanelgoSiteDeployerWith(fn () => $client);
    $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), ['site_type' => 'java', 'site_names' => 'a.com']);

    expect($captured['id'])->toBe(12);
    expect($captured['status'])->toBeTrue();
    expect($captured['cert'])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('php 类型走 datalist（php 同时属 IIS 类型集，与 certimate findSiteByName 一致）', function () {
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('panelGetConfig')->once()->andReturn(['site' => ['webserver' => 'nginx']]);
    $client->shouldReceive('datalistGetDataList')->once()->with('sites', 'a.com', 1, 10)->andReturn([['id' => 9, 'name' => 'a.com']]);
    $client->shouldReceive('siteGetProjectList')->never();
    $client->shouldReceive('siteSetSiteSSL')->once();

    $deployer = baotapanelgoSiteDeployerWith(fn () => $client);
    $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), ['site_type' => 'php', 'site_names' => 'a.com']);
    expect(true)->toBeTrue();
});

test('空类型站点：走 datalist.GetDataList(table=sites) 查 ID', function () {
    $captured = null;
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('panelGetConfig')->once()->andReturn(['site' => ['webserver' => 'nginx']]);
    $client->shouldReceive('datalistGetDataList')->once()->with('sites', 'b.com', 1, 10)->andReturn([
        ['id' => 30, 'name' => 'b.com'],
    ]);
    $client->shouldReceive('siteGetProjectList')->never();
    $client->shouldReceive('siteSetSiteSSL')->once()->andReturnUsing(function (int $id) use (&$captured) {
        $captured = $id;
    });

    $deployer = baotapanelgoSiteDeployerWith(fn () => $client);
    $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), ['site_names' => 'b.com']);

    expect($captured)->toBe(30);
});

test('IIS 服务器：PEM 转 PFX 后上传并设置站点 PFX SSL', function () {
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('panelGetConfig')->once()->andReturn(['site' => ['webserver' => 'iis'], 'paths' => ['soft' => 'C:/BtSoft']]);
    $client->shouldReceive('datalistGetDataList')->once()->andReturn([['id' => 9, 'name' => 'a.com']]);
    $client->shouldReceive('siteSetSiteSSL')->never();
    $client->shouldReceive('filesUpload')->once()->with('C:/BtSoft/temp/ssl/certimate', hash('sha256', 'PFXDATA').'.pfx', 'PFXDATA', true);
    $client->shouldReceive('siteSetSitePfxSsl')->once()->with(9, Mockery::pattern('#^C:/BtSoft/temp/ssl/certimate/.+\.pfx$#'), 'certimate');

    $deployer = new class(fn () => $client) extends BaotapanelgoSiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)();
        }

        protected function buildPfx(string $cert, string $key, string $password): string
        {
            return 'PFXDATA';
        }
    };
    $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), ['site_names' => 'a.com']);
});

test('站点未找到抛错', function () {
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('panelGetConfig')->andReturn(['site' => ['webserver' => 'nginx']]);
    $client->shouldReceive('siteGetProjectList')->andReturn([['id' => 1, 'name' => 'other.com']]);
    $client->shouldReceive('siteSetSiteSSL')->never();

    $deployer = baotapanelgoSiteDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), ['site_type' => 'java', 'site_names' => 'a.com']))
        ->toThrow(RuntimeException::class, '未找到站点');
});

test('不支持的站点类型抛业务错误', function () {
    $deployer = baotapanelgoSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), ['site_type' => 'weird', 'site_names' => 'a.com']))
        ->toThrow(RuntimeException::class, '不支持的网站类型');
});

test('缺 site_names 抛业务错误', function () {
    $deployer = baotapanelgoSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(baotagoSiteCertRef(), baotagoSiteCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 site_names');
});

test('bind 遇 BaotapanelgoApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('panelGetConfig')->andThrow(new BaotapanelgoApiException('BaotaError', '配置读取失败'));

    $deployer = baotapanelgoSiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(baotagoSiteCertRef(), ['server_url' => 'https://p', 'api_key' => 'APIKEY-LEAK-2'], ['site_names' => 'x.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('配置读取失败');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-2');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-2');
    }
});

// ============ 线协议 / 签名（真实 BaotapanelgoClient + MockHandler）============

function baotapanelgoClientWithMock(array $responses, ArrayObject $history): BaotapanelgoClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://panel.example.com:8888/', 'http_errors' => false]);

    return new BaotapanelgoClient($http, 'SK-SECRET');
}

test('线协议：siteSetSiteSSL 发表单 + 签名 request_token=md5(request_time + md5(apiKey))', function () {
    $history = new ArrayObject;
    $client = baotapanelgoClientWithMock([new Response(200, [], json_encode(['status' => true]))], $history);

    $client->siteSetSiteSSL(7, true, 'CERT', 'KEY');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/site/set_site_ssl');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/x-www-form-urlencoded');

    parse_str((string) $req->getBody(), $form);
    expect($form['siteid'])->toBe('7');
    expect($form['status'])->toBe('1');
    expect($form['cert'])->toBe('CERT');
    expect($form['key'])->toBe('KEY');
    $expected = md5($form['request_time'].md5('SK-SECRET'));
    expect($form['request_token'])->toBe($expected);
});

test('线协议：filesUpload multipart 与 siteSetSitePfxSsl 表单路径对齐', function () {
    $history = new ArrayObject;
    $client = baotapanelgoClientWithMock([
        new Response(200, [], json_encode(['status' => true])),
        new Response(200, [], json_encode(['status' => true])),
    ], $history);
    $client->filesUpload('C:/BtSoft/temp/ssl/certimate', 'x.pfx', 'PFXDATA', true);
    $client->siteSetSitePfxSsl(7, 'C:/BtSoft/temp/ssl/certimate/x.pfx', 'certimate');
    expect($history[0]['request']->getUri()->getPath())->toBe('/files/upload');
    expect($history[0]['request']->getHeaderLine('Content-Type'))->toContain('multipart/form-data');
    expect((string) $history[0]['request']->getBody())->toContain('PFXDATA')->toContain('name="blob"');
    parse_str((string) $history[1]['request']->getBody(), $form);
    expect($history[1]['request']->getUri()->getPath())->toBe('/site/set_site_pfx_ssl');
    expect($form['siteid'])->toBe('7')->and($form['password'])->toBe('certimate');
});

test('线协议：status=int 非 0 → BaotapanelgoApiException（带 msg）', function () {
    $history = new ArrayObject;
    $client = baotapanelgoClientWithMock([new Response(200, [], json_encode(['status' => -1, 'msg' => '签名错误']))], $history);

    expect(fn () => $client->configSetPanelSSL(1, 'C', 'K'))
        ->toThrow(BaotapanelgoApiException::class, '签名错误');
});

test('线协议：datalistGetDataList 抽取 data 列表', function () {
    $history = new ArrayObject;
    $client = baotapanelgoClientWithMock([
        new Response(200, [], json_encode(['status' => true, 'data' => [['id' => 1, 'name' => 'a.com'], 'junk']])),
    ], $history);

    $items = $client->datalistGetDataList('sites', 'a.com', 1, 10);
    expect($items)->toBe([['id' => 1, 'name' => 'a.com']]); // 非数组项被过滤
});

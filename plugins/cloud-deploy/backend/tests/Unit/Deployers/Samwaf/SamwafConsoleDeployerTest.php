<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafApiException;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafClient;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafConsoleDeployer;
use Tests\TestCase;

uses(TestCase::class);

function samwafConsoleDeployerWith(callable $clientFactory): SamwafConsoleDeployer
{
    return new class($clientFactory) extends SamwafConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function samwafConsoleRef(): array
{
    return ['cert' => "LEAF\n", 'key' => 'PRIVATE-KEY', 'chain' => "CHAIN\n"];
}

test('SamWaf Console 是内联端点且 auto_restart 默认开启', function () {
    $deployer = new SamwafConsoleDeployer;
    $schema = collect($deployer->configSchema())->keyBy('key');

    expect($deployer->provider())->toBe('samwaf')
        ->and($deployer->product())->toBe('console')
        ->and($deployer->usesRemoteCertStore())->toBeFalse()
        ->and($schema['auto_restart']['default'])->toBeTrue();
});

test('SamWaf Console 依次上传完整链、启用 SSL 并重启管理端', function () {
    $calls = [];
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('uploadConsoleCertificate')->once()
        ->with("LEAF\nCHAIN", 'PRIVATE-KEY')
        ->andReturnUsing(function () use (&$calls) {
            $calls[] = 'upload';
        });
    $client->shouldReceive('enableConsoleSsl')->once()->andReturnUsing(function () use (&$calls) {
        $calls[] = 'enable';
    });
    $client->shouldReceive('restartConsoleManager')->once()->andReturnUsing(function () use (&$calls) {
        $calls[] = 'restart';
    });

    samwafConsoleDeployerWith(fn () => $client)->bind(
        samwafConsoleRef(),
        ['server_url' => 'https://waf.example', 'api_key' => 'secret'],
        ['auto_restart' => true],
    );

    expect($calls)->toBe(['upload', 'enable', 'restart']);
});

test('SamWaf Console 可关闭自动重启', function () {
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('uploadConsoleCertificate')->once();
    $client->shouldReceive('enableConsoleSsl')->once();
    $client->shouldReceive('restartConsoleManager')->never();

    samwafConsoleDeployerWith(fn () => $client)->bind(
        samwafConsoleRef(),
        ['server_url' => 'https://waf.example', 'api_key' => 'secret'],
        ['auto_restart' => false],
    );
});

test('SamWaf Console 直接 API 传字符串 false 时也关闭自动重启', function () {
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('uploadConsoleCertificate')->once();
    $client->shouldReceive('enableConsoleSsl')->once();
    $client->shouldNotReceive('restartManager');

    samwafConsoleDeployerWith(fn () => $client)->bind([
        'cert' => 'CERT', 'chain' => 'CHAIN', 'key' => 'KEY',
    ], [], ['auto_restart' => 'false']);
});

test('SamWaf 客户端使用 Certimate 的三个 vipconfig 路径和请求体', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"code":0}'),
        new Response(200, [], '{"code":0}'),
        new Response(200, [], '{"code":0}'),
    ]));
    $stack->push(Middleware::history($history));
    $client = new SamwafClient(new Client([
        'base_uri' => 'https://waf.example/api/v1/',
        'handler' => $stack,
        'headers' => ['X-API-Key' => 'token'],
    ]));

    $client->uploadConsoleCertificate('FULLCHAIN', 'PRIVATE-KEY');
    $client->enableConsoleSsl();
    $client->restartConsoleManager();

    expect(array_map(fn (array $item): string => $item['request']->getUri()->getPath(), $history))->toBe([
        '/api/v1/vipconfig/uploadSslCert',
        '/api/v1/vipconfig/updateSslEnable',
        '/api/v1/vipconfig/restartManager',
    ])->and(json_decode((string) $history[0]['request']->getBody(), true))->toBe([
        'cert_content' => 'FULLCHAIN',
        'key_content' => 'PRIVATE-KEY',
    ])->and(json_decode((string) $history[1]['request']->getBody(), true))->toBe(['ssl_enable' => true]);
});

test('SamWaf 客户端不采信上游错误字段且不会回显 API Key', function () {
    $apiKey = 'opaque-api-key-LEAK-ME';
    $client = new SamwafClient(new Client([
        'base_uri' => 'https://waf.example/api/v1/',
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['code' => 403, 'msg' => "denied $apiKey"])),
        ])),
    ]));

    try {
        $client->enableConsoleSsl();
        expect(false)->toBeTrue('应拒绝业务错误响应');
    } catch (SamwafApiException $e) {
        expect($e->getErrorCode())->toBe('SamWafApiError')
            ->and($e->getErrorMessage())->toBe('SamWaf 接口返回业务错误')
            ->and($e->getMessage())->not->toContain($apiKey)->not->toContain('403');
    }
});

test('SamWaf 客户端拒绝非空畸形 JSON 和缺基础响应结构', function (string $body) {
    $client = new SamwafClient(new Client([
        'base_uri' => 'https://waf.example/api/v1/',
        'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)])),
    ]));

    expect(fn () => $client->enableConsoleSsl())
        ->toThrow(SamwafApiException::class, 'MalformedResponse');
})->with([
    'invalid JSON' => ['not-json'],
    'JSON list' => ['[]'],
    'missing code' => ['{}'],
]);

test('SamWaf 客户端按 Certimate 语义接受空成功响应', function () {
    $client = new SamwafClient(new Client([
        'base_uri' => 'https://waf.example/api/v1/',
        'handler' => HandlerStack::create(new MockHandler([new Response(204, [], '')])),
    ]));

    $client->enableConsoleSsl();
    expect(true)->toBeTrue();
});

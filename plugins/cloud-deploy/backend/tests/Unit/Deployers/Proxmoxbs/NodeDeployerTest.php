<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Proxmoxbs\NodeDeployer;
use Plugins\CloudDeploy\Deployers\Proxmoxbs\ProxmoxbsApiException;
use Plugins\CloudDeploy\Deployers\Proxmoxbs\ProxmoxbsClient;
use Plugins\CloudDeploy\Deployers\Proxmoxbs\ProxmoxbsProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

function proxmoxbsDeployerWith(callable $factory): NodeDeployer
{
    return new class($factory) extends NodeDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function proxmoxbsClientWithMock(array $responses, ArrayObject $history): ProxmoxbsClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new ProxmoxbsClient(new Client([
        'handler' => $stack,
        'http_errors' => false,
        'base_uri' => 'https://pbs.example.com:8007/api2/json/',
        'headers' => ['Authorization' => 'PBSAPIToken=root@pam!certimate:SECRET-UUID'],
    ]));
}

test('Proxmox BS provider、目标地址与内联部署器元信息对齐', function () {
    $provider = new ProxmoxbsProvider;
    $schema = collect($provider->credentialSchema())->keyBy('key');
    $deployer = new NodeDeployer;
    $autoRestart = collect($deployer->configSchema())->firstWhere('key', 'auto_restart');

    expect($provider->key())->toBe('proxmoxbs')
        ->and($schema['server_url']['destination'])->toBeTrue()
        ->and($schema['api_token']['secret'])->toBeTrue()
        ->and($schema['api_token_secret']['secret'])->toBeTrue()
        ->and($deployer->provider())->toBe('proxmoxbs')
        ->and($deployer->product())->toBe('node')
        ->and(array_column($deployer->configSchema(), 'key'))->toBe(['node_name', 'auto_restart'])
        ->and($autoRestart['default'] ?? null)->toBeTrue()
        ->and($deployer->usesRemoteCertStore())->toBeFalse();
});

test('bind 上传完整链、私钥、force=true 并透传 restart=true', function () {
    $captured = null;
    $client = Mockery::mock(ProxmoxbsClient::class);
    $client->shouldReceive('nodeUploadCustomCertificate')->once()->andReturnUsing(function (string $node, array $body) use (&$captured) {
        $captured = [$node, $body];
    });
    $deployer = proxmoxbsDeployerWith(fn () => $client);

    $deployer->bind(['cert' => 'CERT', 'key' => 'KEY', 'chain' => 'CHAIN'], [
        'server_url' => 'https://pbs.example.com:8007', 'api_token' => 'root@pam!certimate', 'api_token_secret' => 'secret',
    ], ['node_name' => 'pbs/一号']);

    expect($captured)->toBe(['pbs/一号', [
        'certificates' => "CERT\nCHAIN", 'key' => 'KEY', 'force' => true, 'restart' => true,
    ]]);
});

test('bind 显式 auto_restart=false 时省略 restart', function () {
    $body = null;
    $client = Mockery::mock(ProxmoxbsClient::class);
    $client->shouldReceive('nodeUploadCustomCertificate')->once()->andReturnUsing(function (string $node, array $request) use (&$body) {
        $body = $request;
    });
    $deployer = proxmoxbsDeployerWith(fn () => $client);
    $deployer->bind(['cert' => 'CERT', 'key' => 'KEY', 'chain' => ''], [], ['node_name' => 'pbs1', 'auto_restart' => false]);

    expect($body)->not->toHaveKey('restart');
});

test('client 精确使用 PBSAPIToken 冒号鉴权并编码 node 路径', function () {
    $history = new ArrayObject;
    $client = proxmoxbsClientWithMock([new Response(200, [], json_encode(['data' => []]))], $history);
    $client->nodeUploadCustomCertificate('pbs/一号', [
        'certificates' => 'CERT', 'key' => 'KEY', 'force' => true, 'restart' => true,
    ]);

    /** @var RequestInterface $request */
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/api2/json/nodes/pbs%2F%E4%B8%80%E5%8F%B7/certificates/custom')
        ->and($request->getHeaderLine('Authorization'))->toBe('PBSAPIToken=root@pam!certimate:SECRET-UUID')
        ->and(json_decode((string) $request->getBody(), true))->toMatchArray([
            'certificates' => 'CERT', 'key' => 'KEY', 'force' => true, 'restart' => true,
        ]);
});

test('client 非 2xx 不采用恶意 reason phrase 且不回显错误正文', function () {
    $client = proxmoxbsClientWithMock([
        new Response(400, [], json_encode(['errors' => ['certificates' => 'PRIVATE-KEY-LEAK']]), '1.1', 'TOKEN-LEAK'),
    ], new ArrayObject);

    try {
        $client->nodeUploadCustomCertificate('pbs1', []);
        expect(false)->toBeTrue('应抛异常');
    } catch (ProxmoxbsApiException $e) {
        expect($e->getErrorCode())->toBe('ProxmoxbsRequestFailed')
            ->and($e->getMessage())->not->toContain('PRIVATE-KEY-LEAK')
            ->not->toContain('TOKEN-LEAK');
    }
});

test('client 非空 2xx 响应必须是合法 JSON 对象', function (string $body) {
    $client = proxmoxbsClientWithMock([new Response(200, [], $body)], new ArrayObject);

    expect(fn () => $client->nodeUploadCustomCertificate('pbs1', []))
        ->toThrow(ProxmoxbsApiException::class, 'ProxmoxbsInvalidResponse');
})->with([
    '畸形 JSON' => ['{"data":'],
    'JSON 数组' => ['[]'],
    'JSON 标量' => ['null'],
]);

test('client 空 2xx 响应与 Certimate SDK 空 body 语义一致', function () {
    $client = proxmoxbsClientWithMock([new Response(204)], new ArrayObject);

    $client->nodeUploadCustomCertificate('pbs1', []);

    expect(true)->toBeTrue();
});

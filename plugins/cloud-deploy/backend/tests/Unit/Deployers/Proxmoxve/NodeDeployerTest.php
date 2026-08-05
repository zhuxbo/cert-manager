<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Proxmoxve\NodeDeployer;
use Plugins\CloudDeploy\Deployers\Proxmoxve\ProxmoxveApiException;
use Plugins\CloudDeploy\Deployers\Proxmoxve\ProxmoxveClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock ProxmoxveClient。 */
function proxmoxveDeployerWith(callable $clientFactory): NodeDeployer
{
    return new class($clientFactory) extends NodeDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function proxmoxveCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function proxmoxveCreds(): array
{
    return [
        'server_url' => 'https://pve.example.com:8006',
        'api_token' => 'root@pam!certimate',
        'api_token_secret' => 'SECRET-UUID',
    ];
}

/** 构造注入 MockHandler 的真实 ProxmoxveClient，外发请求写入 $history。 */
function proxmoxveClientWithMock(array $responses, ArrayObject $history): ProxmoxveClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client([
        'handler' => $stack,
        'http_errors' => false,
        'base_uri' => 'https://pve.example.com:8006/api2/json/',
        'headers' => ['Authorization' => 'PVEAPIToken=root@pam!certimate=SECRET-UUID'],
    ]);

    return new ProxmoxveClient($http);
}

test('Proxmox VE 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new NodeDeployer;
    expect($deployer->provider())->toBe('proxmoxve');
    expect($deployer->product())->toBe('node');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('node_name')->toContain('auto_restart');
});

test('bind：nodeUploadCustomCertificate(node, {certificates=完整链, key, force:true, restart})', function () {
    $args = null;
    $client = Mockery::mock(ProxmoxveClient::class);
    $client->shouldReceive('nodeUploadCustomCertificate')->once()->andReturnUsing(function (string $node, array $body) use (&$args) {
        $args = [$node, $body];
    });

    $deployer = proxmoxveDeployerWith(fn () => $client);
    $deployer->bind(proxmoxveCertRef(), proxmoxveCreds(), ['node_name' => 'pve1', 'auto_restart' => true]);

    [$node, $body] = $args;
    expect($node)->toBe('pve1');
    expect($body['certificates'])->toBe("CERTPEM\nCHAINPEM");
    expect($body['key'])->toBe('KEYPEM');
    expect($body['force'])->toBeTrue();
    expect($body['restart'])->toBeTrue();
    expect($deployer->touchedConfigKeys())->toContain('node_name');
});

test('bind：auto_restart 缺省时 restart=false', function () {
    $args = null;
    $client = Mockery::mock(ProxmoxveClient::class);
    $client->shouldReceive('nodeUploadCustomCertificate')->once()->andReturnUsing(function (string $node, array $body) use (&$args) {
        $args = $body;
    });

    $deployer = proxmoxveDeployerWith(fn () => $client);
    $deployer->bind(proxmoxveCertRef(), proxmoxveCreds(), ['node_name' => 'pve1']);

    expect($args['restart'])->toBeFalse();
});

test('缺 node_name 抛业务错误', function () {
    $deployer = proxmoxveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(proxmoxveCertRef(), proxmoxveCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 node_name');
});

test('bind 遇 ProxmoxveApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(ProxmoxveClient::class);
    $client->shouldReceive('nodeUploadCustomCertificate')->andThrow(new ProxmoxveApiException('400', 'Parameter verification failed'));

    $deployer = proxmoxveDeployerWith(fn () => $client);
    try {
        $deployer->bind(proxmoxveCertRef(), [
            'server_url' => 'https://pve.example.com:8006',
            'api_token' => 'root@pam!certimate',
            'api_token_secret' => 'SECRET-LEAK-123',
        ], ['node_name' => 'pve1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('Parameter verification failed');
        expect($e->getMessage())->not->toContain('SECRET-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：nodeUploadCustomCertificate POST /api2/json/nodes/{node}/certificates/custom（带 PVEAPIToken 头）', function () {
    $history = new ArrayObject;
    $client = proxmoxveClientWithMock([new Response(200, [], json_encode(['data' => ['fingerprint' => 'AA:BB']]))], $history);

    $client->nodeUploadCustomCertificate('pve1', ['certificates' => 'C', 'key' => 'K', 'force' => true, 'restart' => false]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/api2/json/nodes/pve1/certificates/custom');
    expect($req->getHeaderLine('Authorization'))->toBe('PVEAPIToken=root@pam!certimate=SECRET-UUID');
    expect(json_decode((string) $req->getBody(), true))->toMatchArray(['certificates' => 'C', 'key' => 'K', 'force' => true]);
});

test('client：HTTP 非 2xx → ProxmoxveApiException（HTTP 状态码 + reason phrase，无响应体回显）', function () {
    // 错误体含可能回显入参的 errors，sanitizer 不取其内容；client 用 reason phrase
    $client = proxmoxveClientWithMock([new Response(401, [], json_encode(['data' => null, 'errors' => ['certificates' => 'invalid pem']]))], new ArrayObject);
    try {
        $client->nodeUploadCustomCertificate('pve1', []);
        expect(false)->toBeTrue('应抛异常');
    } catch (ProxmoxveApiException $e) {
        expect($e->getErrorCode())->toBe('401');
        expect($e->getErrorMessage())->toBe('Unauthorized');
        // 不回显错误体内容（防回显入参）
        expect($e->getMessage())->not->toContain('invalid pem');
    }
});

test('makeClient：allow_insecure_connections=true 时不抛、能造出 ProxmoxveClient', function () {
    $deployer = new NodeDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', [
        'server_url' => 'https://1.1.1.1', 'api_token' => 't', 'api_token_secret' => 's', 'allow_insecure_connections' => true,
    ]);
    expect($client)->toBeInstanceOf(ProxmoxveClient::class);
});

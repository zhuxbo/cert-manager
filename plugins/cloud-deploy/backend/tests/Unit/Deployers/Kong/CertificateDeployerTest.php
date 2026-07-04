<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Kong\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Kong\KongApiException;
use Plugins\CloudDeploy\Deployers\Kong\KongClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind，3 参带 workspace）注入 mock KongClient。 */
function kongDeployerWith(callable $clientFactory): CertificateDeployer
{
    return new class($clientFactory) extends CertificateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $workspace = ''): object
        {
            return ($this->factory)($kind, $credentials, $workspace);
        }
    };
}

/** 叶证书带 SAN（DNS:a.example.com, DNS:b.example.com），用于断言 snis 解析。 */
function kongCertRef(): array
{
    return ['cert' => kongLeafCertWithSan(), 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function kongCreds(): array
{
    return ['server_url' => 'https://kong.example.com:8001', 'api_token' => 'TOKEN'];
}

/** 生成一张含 SAN a.example.com / b.example.com 的自签证书 PEM（供 SAN 解析断言）。 */
function kongLeafCertWithSan(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $conf = tempnam(sys_get_temp_dir(), 'kongssl');
    file_put_contents($conf, "[v3]\nsubjectAltName=DNS:a.example.com,DNS:b.example.com\n");
    $crt = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3']);
    openssl_x509_export($crt, $pem);
    @unlink($conf);

    return $pem;
}

/** 构造注入 MockHandler 的真实 KongClient，外发请求写入 $history。 */
function kongClientWithMock(array $responses, ArrayObject $history, string $workspace = ''): KongClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://kong.example.com:8001/', 'headers' => ['Kong-Admin-Token' => 'TOKEN']]);

    return new KongClient($http, $workspace);
}

test('Kong 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('kong');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id')->toContain('workspace');
});

test('bind：upsertCertificate(id, {id, cert=完整链, key, snis 取证书 SAN})', function () {
    $args = null;
    $client = Mockery::mock(KongClient::class);
    $client->shouldReceive('upsertCertificate')->once()->andReturnUsing(function (string $certId, array $body) use (&$args) {
        $args = [$certId, $body];
    });

    $deployer = kongDeployerWith(fn () => $client);
    $deployer->bind(kongCertRef(), kongCreds(), ['certificate_id' => 'cert-9']);

    [$certId, $body] = $args;
    expect($certId)->toBe('cert-9');
    expect($body['id'])->toBe('cert-9');
    expect($body['cert'])->toContain('BEGIN CERTIFICATE')->toContain('CHAINPEM');
    expect($body['key'])->toBe('KEYPEM');
    expect($body['snis'])->toBe(['a.example.com', 'b.example.com']);
    expect($deployer->touchedConfigKeys())->toContain('certificate_id');
});

test('bind：workspace 透传给 makeClient（第 3 参）', function () {
    $capturedWorkspace = null;
    $client = Mockery::mock(KongClient::class);
    $client->shouldReceive('upsertCertificate')->once();

    $deployer = kongDeployerWith(function (string $kind, array $cred, string $workspace) use (&$capturedWorkspace, $client) {
        $capturedWorkspace = $workspace;

        return $client;
    });
    $deployer->bind(kongCertRef(), kongCreds(), ['certificate_id' => 'c-1', 'workspace' => 'team-a']);

    expect($capturedWorkspace)->toBe('team-a');
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = kongDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(kongCertRef(), kongCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 KongApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(KongClient::class);
    $client->shouldReceive('upsertCertificate')->andThrow(new KongApiException('400', 'schema violation: invalid certificate'));

    $deployer = kongDeployerWith(fn () => $client);
    try {
        $deployer->bind(kongCertRef(), ['server_url' => 'https://kong.example.com:8001', 'api_token' => 'TOKEN-LEAK-123'], ['certificate_id' => 'c-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('schema violation');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：upsertCertificate PUT /certificates/{id}（带 Kong-Admin-Token 头 + JSON body）', function () {
    $history = new ArrayObject;
    $client = kongClientWithMock([new Response(200, [], json_encode(['id' => 'cert-7']))], $history);

    $client->upsertCertificate('cert-7', ['id' => 'cert-7', 'cert' => 'C', 'key' => 'K', 'snis' => ['x.example.com']]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect($req->getUri()->getPath())->toBe('/certificates/cert-7');
    expect($req->getHeaderLine('Kong-Admin-Token'))->toBe('TOKEN');
    expect(json_decode((string) $req->getBody(), true))->toMatchArray(['id' => 'cert-7', 'cert' => 'C', 'key' => 'K']);
});

test('client：workspace 非空时 PUT /{workspace}/certificates/{id}', function () {
    $history = new ArrayObject;
    $client = kongClientWithMock([new Response(200, [], '{}')], $history, 'team-a');

    $client->upsertCertificate('cert-7', ['id' => 'cert-7']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getPath())->toBe('/team-a/certificates/cert-7');
});

test('client：HTTP 非 2xx → KongApiException（HTTP 状态码 + 响应体 message）', function () {
    $client = kongClientWithMock([new Response(401, [], json_encode(['message' => 'Invalid credentials']))], new ArrayObject);
    try {
        $client->upsertCertificate('c-1', []);
        expect(false)->toBeTrue('应抛异常');
    } catch (KongApiException $e) {
        expect($e->getErrorCode())->toBe('401');
        expect($e->getErrorMessage())->toBe('Invalid credentials');
    }
});

test('makeClient：allow_insecure_connections=true 时不抛、能造出 KongClient', function () {
    $deployer = new CertificateDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', ['server_url' => 'https://x', 'api_token' => 't', 'allow_insecure_connections' => true], '');
    expect($client)->toBeInstanceOf(KongClient::class);
});

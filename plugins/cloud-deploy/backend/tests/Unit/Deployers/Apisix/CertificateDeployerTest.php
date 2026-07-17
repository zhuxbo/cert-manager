<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Apisix\ApisixApiException;
use Plugins\CloudDeploy\Deployers\Apisix\ApisixClient;
use Plugins\CloudDeploy\Deployers\Apisix\CertificateDeployer;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock ApisixClient。 */
function apisixDeployerWith(callable $clientFactory): CertificateDeployer
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

/** 叶证书带 SAN（DNS:a.example.com, DNS:b.example.com），用于断言 snis 解析。 */
function apisixCertRef(): array
{
    return ['cert' => apisixLeafCertWithSan(), 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function apisixCreds(): array
{
    return ['server_url' => 'https://apisix.example.com:9180', 'api_key' => 'APIKEY'];
}

/** 生成一张含 SAN a.example.com / b.example.com 的自签证书 PEM（供 SAN 解析断言）。 */
function apisixLeafCertWithSan(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $conf = tempnam(sys_get_temp_dir(), 'apisixssl');
    file_put_contents($conf, "[v3]\nsubjectAltName=DNS:a.example.com,DNS:b.example.com\n");
    $crt = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3']);
    openssl_x509_export($crt, $pem);
    @unlink($conf);

    return $pem;
}

/** 构造注入 MockHandler 的真实 ApisixClient，外发请求写入 $history。 */
function apisixClientWithMock(array $responses, ArrayObject $history): ApisixClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://apisix.example.com:9180/apisix/admin/', 'headers' => ['X-API-KEY' => 'APIKEY']]);

    return new ApisixClient($http);
}

test('APISIX 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('apisix');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('bind：updateSsl(id, {id, cert=完整链, key, snis 取证书 SAN, type=server, status=1})', function () {
    $args = null;
    $client = Mockery::mock(ApisixClient::class);
    $client->shouldReceive('updateSsl')->once()->andReturnUsing(function (string $sslId, array $body) use (&$args) {
        $args = [$sslId, $body];
    });

    $deployer = apisixDeployerWith(fn () => $client);
    $deployer->bind(apisixCertRef(), apisixCreds(), ['certificate_id' => 'ssl-9']);

    [$sslId, $body] = $args;
    expect($sslId)->toBe('ssl-9');
    expect($body['id'])->toBe('ssl-9');
    expect($body['cert'])->toContain('BEGIN CERTIFICATE')->toContain('CHAINPEM');
    expect($body['key'])->toBe('KEYPEM');
    expect($body['snis'])->toBe(['a.example.com', 'b.example.com']);
    expect($body['type'])->toBe('server');
    expect($body['status'])->toBe(1);
    expect($deployer->touchedConfigKeys())->toContain('certificate_id');
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = apisixDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(apisixCertRef(), apisixCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 ApisixApiException 时脱敏重抛（含错误码、无 api key、不挂 previous）', function () {
    $client = Mockery::mock(ApisixClient::class);
    $client->shouldReceive('updateSsl')->andThrow(new ApisixApiException('400', 'invalid configuration'));

    $deployer = apisixDeployerWith(fn () => $client);
    try {
        $deployer->bind(apisixCertRef(), ['server_url' => 'https://apisix.example.com:9180', 'api_key' => 'APIKEY-LEAK-123'], ['certificate_id' => 'ssl-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('invalid configuration');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：updateSsl PUT /apisix/admin/ssls/{id}（带 X-API-KEY 头 + JSON body）', function () {
    $history = new ArrayObject;
    $client = apisixClientWithMock([new Response(200, [], json_encode(['value' => ['id' => 'ssl-7']]))], $history);

    $client->updateSsl('ssl-7', ['id' => 'ssl-7', 'cert' => 'C', 'key' => 'K', 'snis' => ['x.example.com'], 'type' => 'server', 'status' => 1]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect($req->getUri()->getPath())->toBe('/apisix/admin/ssls/ssl-7');
    expect($req->getHeaderLine('X-API-KEY'))->toBe('APIKEY');
    expect(json_decode((string) $req->getBody(), true))->toMatchArray(['id' => 'ssl-7', 'type' => 'server', 'status' => 1]);
});

test('client：HTTP 非 2xx → ApisixApiException（HTTP 状态码 + 响应体 error_msg）', function () {
    $client = apisixClientWithMock([new Response(401, [], json_encode(['error_msg' => 'Missing API key found in request']))], new ArrayObject);
    try {
        $client->updateSsl('ssl-1', []);
        expect(false)->toBeTrue('应抛异常');
    } catch (ApisixApiException $e) {
        expect($e->getErrorCode())->toBe('401');
        expect($e->getErrorMessage())->toBe('Missing API key found in request');
    }
});

test('makeClient：allow_insecure_connections=true 时不抛、能造出 ApisixClient', function () {
    $deployer = new CertificateDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', ['server_url' => 'https://x', 'api_key' => 'k', 'allow_insecure_connections' => true]);
    expect($client)->toBeInstanceOf(ApisixClient::class);
});

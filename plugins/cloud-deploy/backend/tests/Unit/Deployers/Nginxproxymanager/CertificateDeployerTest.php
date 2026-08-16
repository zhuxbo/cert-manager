<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Nginxproxymanager\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Nginxproxymanager\NginxproxymanagerApiException;
use Plugins\CloudDeploy\Deployers\Nginxproxymanager\NginxproxymanagerClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock NginxproxymanagerClient。 */
function npmDeployerWith(callable $clientFactory): CertificateDeployer
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

function npmCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function npmCreds(): array
{
    return ['server_url' => 'https://npm.example.com:81', 'auth_method' => 'token', 'api_token' => 'JWT-TOKEN'];
}

function npmCertificateWithSan(): string
{
    $conf = tempnam(sys_get_temp_dir(), 'npm_san_');
    file_put_contents($conf, "[v3]\nsubjectAltName=DNS:a.example.com,DNS:*.wild.example.com\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, [
        'digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3',
    ]);
    openssl_x509_export($cert, $pem);
    @unlink($conf);

    return $pem;
}

/** 构造注入 MockHandler 的真实 NginxproxymanagerClient，外发请求写入 $history。 */
function npmClientWithMock(array $responses, ArrayObject $history, string $username = '', string $password = '', string $apiToken = ''): NginxproxymanagerClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://npm.example.com:81/api/']);

    return new NginxproxymanagerClient($http, $username, $password, $apiToken);
}

test('NPM 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('nginxproxymanager');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('deploy_target')->toContain('host_type')->toContain('host_id')->toContain('certificate_id');
});

test('bind：host specified 创建证书并更新指定 proxy host', function () {
    $client = Mockery::mock(NginxproxymanagerClient::class);
    $client->shouldReceive('ensureCertificate')->once()->with(Mockery::type('string'), 'CERTPEM', 'KEYPEM', 'CHAINPEM')->andReturn(77);
    $client->shouldReceive('listHosts')->once()->with('proxy')->andReturn([['id' => 5, 'domain_names' => ['a.example.com'], 'certificate_id' => 1]]);
    $client->shouldReceive('updateHostCertificate')->once()->with('proxy', 5, 77);

    npmDeployerWith(fn () => $client)->bind(npmCertRef(), npmCreds(), [
        'deploy_target' => 'host', 'host_type' => 'proxy', 'host_match_pattern' => 'specified', 'host_id' => 5,
    ]);
});

test('bind：host certsan 仅更新所有域名均被证书覆盖的主机', function () {
    $certRef = npmCertRef();
    $certRef['cert'] = npmCertificateWithSan();
    $client = Mockery::mock(NginxproxymanagerClient::class);
    $client->shouldReceive('ensureCertificate')->once()->andReturn(77);
    $client->shouldReceive('listHosts')->once()->with('proxy')->andReturn([
        ['id' => 5, 'domain_names' => ['a.example.com', 'x.wild.example.com'], 'certificate_id' => 1],
        ['id' => 6, 'domain_names' => ['a.example.com', 'other.example.com'], 'certificate_id' => 1],
    ]);
    $client->shouldReceive('updateHostCertificate')->once()->with('proxy', 5, 77);

    npmDeployerWith(fn () => $client)->bind($certRef, npmCreds(), [
        'deploy_target' => 'host', 'host_type' => 'proxy', 'host_match_pattern' => 'certsan',
    ]);
});

test('bind：uploadCertificate(certId, 叶证书/key/中间证书) → 默认站点 get+set 触发重启', function () {
    $uploadArgs = null;
    $setValue = null;
    $client = Mockery::mock(NginxproxymanagerClient::class);
    $client->shouldReceive('uploadCertificate')->once()->andReturnUsing(function (int $certId, string $cert, string $key, string $inter) use (&$uploadArgs) {
        $uploadArgs = compact('certId', 'cert', 'key', 'inter');
    });
    $client->shouldReceive('getDefaultSiteValue')->once()->andReturn('congratulations');
    $client->shouldReceive('setDefaultSite')->once()->andReturnUsing(function (string $value) use (&$setValue) {
        $setValue = $value;
    });

    $deployer = npmDeployerWith(fn () => $client);
    $deployer->bind(npmCertRef(), npmCreds(), ['certificate_id' => '5']);

    expect($uploadArgs)->toBe(['certId' => 5, 'cert' => 'CERTPEM', 'key' => 'KEYPEM', 'inter' => 'CHAINPEM']);
    expect($setValue)->toBe('congratulations'); // 原样写回
    expect($deployer->touchedConfigKeys())->toContain('certificate_id');
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = npmDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(npmCertRef(), npmCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('makeClient：auth_method=token 用 api_token（不登录）', function () {
    // 仅 token 鉴权：getDefaultSiteValue 直接带 Bearer，不打 /tokens
    $history = new ArrayObject;
    $client = npmClientWithMock([new Response(200, [], json_encode(['value' => 'congratulations']))], $history, '', '', 'JWT-TOKEN');

    $client->getDefaultSiteValue();

    expect($history)->toHaveCount(1); // 无登录请求
    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getPath())->toBe('/api/settings/default-site');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer JWT-TOKEN');
});

test('bind 遇 NginxproxymanagerApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(NginxproxymanagerClient::class);
    $client->shouldReceive('uploadCertificate')->andThrow(new NginxproxymanagerApiException('400', 'Certificate is not valid'));

    $deployer = npmDeployerWith(fn () => $client);
    try {
        $deployer->bind(npmCertRef(), ['server_url' => 'https://npm.example.com:81', 'auth_method' => 'token', 'api_token' => 'JWT-LEAK-123'], ['certificate_id' => '5']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('Certificate is not valid');
        expect($e->getMessage())->not->toContain('JWT-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('JWT-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：token 鉴权下 uploadCertificate POST multipart（带 Bearer 头，三段文件字段）', function () {
    $history = new ArrayObject;
    $client = npmClientWithMock([new Response(200, [], json_encode(['id' => 5]))], $history, '', '', 'JWT-TOKEN');

    $client->uploadCertificate(5, 'CERT', 'KEY', 'INTER');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/api/nginx/certificates/5/upload');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer JWT-TOKEN');
    expect($req->getHeaderLine('Content-Type'))->toContain('multipart/form-data');
    $body = (string) $req->getBody();
    expect($body)->toContain('name="certificate"')->toContain('name="certificate_key"')->toContain('name="intermediate_certificate"');
    expect($body)->toContain('CERT')->toContain('KEY')->toContain('INTER');
});

test('client：主机证书目标使用 NPM 对应 list/create/update 路径', function () {
    $history = new ArrayObject;
    $client = npmClientWithMock([
        new Response(200, [], json_encode([])),
        new Response(201, [], json_encode(['id' => 77, 'nice_name' => 'new'])),
        new Response(200, [], json_encode(['id' => 77])),
        new Response(200, [], json_encode([['id' => 5, 'domain_names' => ['a.example.com'], 'certificate_id' => 1]])),
        new Response(200, [], json_encode(['id' => 5])),
    ], $history, '', '', 'JWT-TOKEN');
    expect($client->ensureCertificate('new', 'CERT', 'KEY', 'CHAIN'))->toBe(77);
    expect($client->listHosts('proxy'))->toHaveCount(1);
    $client->updateHostCertificate('proxy', 5, 77);
    expect(array_map(fn ($entry) => [$entry['request']->getMethod(), $entry['request']->getUri()->getPath()], $history->getArrayCopy()))
        ->toBe([
            ['GET', '/api/nginx/certificates'],
            ['POST', '/api/nginx/certificates'],
            ['POST', '/api/nginx/certificates/77/upload'],
            ['GET', '/api/nginx/proxy-hosts'],
            ['PUT', '/api/nginx/proxy-hosts/5'],
        ]);
});

test('client：ensureCertificate 复用 PEM 三元组完全相同的既有证书', function () {
    $history = new ArrayObject;
    $client = npmClientWithMock([new Response(200, [], json_encode([[
        'id' => 8, 'meta' => ['certificate' => 'CERT', 'certificate_key' => 'KEY', 'intermediate_certificate' => 'CHAIN'],
    ]]))], $history, '', '', 'JWT-TOKEN');
    expect($client->ensureCertificate('ignored', 'CERT', 'KEY', 'CHAIN'))->toBe(8);
    expect($history)->toHaveCount(1);
});

test('client：password 鉴权下首调先 POST /tokens 登录拿 token，再带 Bearer 调业务', function () {
    $history = new ArrayObject;
    $client = npmClientWithMock([
        new Response(200, [], json_encode(['token' => 'NEW-JWT', 'expires' => '2099-01-01'])), // /tokens
        new Response(200, [], json_encode(['value' => 'congratulations'])),                     // default-site
    ], $history, 'admin@example.com', 'PASSWORD');

    $value = $client->getDefaultSiteValue();

    expect($value)->toBe('congratulations');
    expect($history)->toHaveCount(2);
    /** @var RequestInterface $loginReq */
    $loginReq = $history[0]['request'];
    expect($loginReq->getMethod())->toBe('POST');
    expect($loginReq->getUri()->getPath())->toBe('/api/tokens');
    expect(json_decode((string) $loginReq->getBody(), true))->toBe(['identity' => 'admin@example.com', 'secret' => 'PASSWORD']);
    expect($loginReq->getHeaderLine('Authorization'))->toBe(''); // 登录请求自身不带 Bearer
    /** @var RequestInterface $bizReq */
    $bizReq = $history[1]['request'];
    expect($bizReq->getHeaderLine('Authorization'))->toBe('Bearer NEW-JWT'); // 业务请求带登录所得 token
});

test('client：2xx 但响应体 error 非空 → NginxproxymanagerApiException（对齐 certimate GetError）', function () {
    $client = npmClientWithMock([new Response(200, [], json_encode(['error' => ['code' => 404, 'message' => 'Certificate could not be found']]))], new ArrayObject, '', '', 'JWT-TOKEN');
    try {
        $client->getDefaultSiteValue();
        expect(false)->toBeTrue('应抛异常');
    } catch (NginxproxymanagerApiException $e) {
        expect($e->getErrorMessage())->toContain('Certificate could not be found');
    }
});

test('client：HTTP 非 2xx → NginxproxymanagerApiException（HTTP 状态码）', function () {
    $client = npmClientWithMock([new Response(403, [], json_encode(['error' => 'Forbidden']))], new ArrayObject, '', '', 'JWT-TOKEN');
    try {
        $client->getDefaultSiteValue();
        expect(false)->toBeTrue('应抛异常');
    } catch (NginxproxymanagerApiException $e) {
        expect($e->getErrorCode())->toBe('403');
        expect($e->getErrorMessage())->toBe('Forbidden');
    }
});

test('client：登录返回空 token 抛 AuthError', function () {
    $client = npmClientWithMock([new Response(200, [], json_encode(['token' => '']))], new ArrayObject, 'u', 'p');
    expect(fn () => $client->getDefaultSiteValue())
        ->toThrow(NginxproxymanagerApiException::class, '登录失败');
});

test('makeClient：allow_insecure_connections=true 时不抛、能造出 NginxproxymanagerClient', function () {
    $deployer = new CertificateDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', ['server_url' => 'https://1.1.1.1', 'auth_method' => 'token', 'api_token' => 't', 'allow_insecure_connections' => true]);
    expect($client)->toBeInstanceOf(NginxproxymanagerClient::class);
});

<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Synologydsm\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Synologydsm\SynologydsmApiException;
use Plugins\CloudDeploy\Deployers\Synologydsm\SynologydsmClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock SynologydsmClient。 */
function synologyDeployerWith(callable $clientFactory): CertificateDeployer
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

function synologyCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function synologyCreds(): array
{
    return ['server_url' => 'https://nas.example.com:5001', 'username' => 'admin', 'password' => 'PASS'];
}

/** 构造注入 MockHandler 的真实 SynologydsmClient，外发请求写入 $history。 */
function synologyClientWithMock(array $responses, ArrayObject $history, string $username = 'admin', string $password = 'PASS', string $totpSecret = ''): SynologydsmClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://nas.example.com:5001']);

    return new SynologydsmClient($http, $username, $password, $totpSecret);
}

/** query.cgi（API info） + auth.cgi（login）两步成功响应，供业务调用前置。 */
function synologyLoginResponses(): array
{
    return [
        new Response(200, [], json_encode(['success' => true, 'data' => ['SYNO.API.Auth' => ['path' => 'auth.cgi', 'maxVersion' => 7, 'minVersion' => 1]]])),
        new Response(200, [], json_encode(['success' => true, 'data' => ['sid' => 'SID-123', 'synotoken' => 'SYNO-TOK-456']])),
    ];
}

test('群晖 DSM 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('synologydsm');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id_or_desc')->toContain('is_default');
});

test('bind：certificate_id_or_desc 为空 → 新建证书（id 空、desc 自动、叶证书/key/中间证书）', function () {
    $args = null;
    $client = Mockery::mock(SynologydsmClient::class);
    $client->shouldReceive('login')->once();
    $client->shouldReceive('listCertificates')->once()->andReturn([
        ['id' => 'new-default', 'desc' => 'New', 'is_default' => true, 'services' => []],
        ['id' => 'old', 'desc' => 'Old', 'is_default' => false, 'services' => [['service' => 'DSM', 'display_name' => 'DSM']]],
    ]);
    $client->shouldReceive('importCertificate')->once()->andReturnUsing(function (...$a) use (&$args) {
        $args = $a;
    });
    $client->shouldReceive('setServiceCertificates')->once()->with([
        ['service' => ['service' => 'DSM', 'display_name' => 'DSM'], 'old_id' => 'old', 'id' => 'new-default'],
    ]);
    $client->shouldReceive('logout')->once();

    $deployer = synologyDeployerWith(fn () => $client);
    $deployer->bind(synologyCertRef(), synologyCreds(), ['is_default' => true]);

    // importCertificate(id, desc, key, cert, interCert, asDefault)
    expect($args[0])->toBe('');                 // 新建 id 留空
    expect($args[1])->toStartWith('clouddeploy-'); // desc 自动
    expect($args[2])->toBe('KEYPEM');           // key
    expect($args[3])->toBe('CERTPEM');          // 叶证书
    expect($args[4])->toBe('CHAINPEM');         // 中间证书
    expect($args[5])->toBeTrue();               // as_default
});

test('bind：certificate_id_or_desc 命中 id → 用其 id+desc 替换导入', function () {
    $args = null;
    $client = Mockery::mock(SynologydsmClient::class);
    $client->shouldReceive('login')->once();
    $client->shouldReceive('listCertificates')->once()->andReturn([
        ['id' => 'other', 'desc' => 'Other', 'is_default' => false],
        ['id' => 'cert-x', 'desc' => 'My Cert', 'is_default' => true],
    ]);
    $client->shouldReceive('importCertificate')->once()->andReturnUsing(function (...$a) use (&$args) {
        $args = $a;
    });
    $client->shouldReceive('logout')->once();

    $deployer = synologyDeployerWith(fn () => $client);
    $deployer->bind(synologyCertRef(), synologyCreds(), ['certificate_id_or_desc' => 'cert-x']);

    expect($args[0])->toBe('cert-x');
    expect($args[1])->toBe('My Cert');
    expect($args[5])->toBeTrue(); // 原证书已是默认 → 保持默认
});

test('bind：certificate_id_or_desc 仅命中 desc（id 不匹配时回退按 desc 匹配）', function () {
    $args = null;
    $client = Mockery::mock(SynologydsmClient::class);
    $client->shouldReceive('login')->once();
    $client->shouldReceive('listCertificates')->once()->andReturn([
        ['id' => 'abc', 'desc' => 'Production Cert', 'is_default' => false],
    ]);
    $client->shouldReceive('importCertificate')->once()->andReturnUsing(function (...$a) use (&$args) {
        $args = $a;
    });
    $client->shouldReceive('logout')->once();

    $deployer = synologyDeployerWith(fn () => $client);
    $deployer->bind(synologyCertRef(), synologyCreds(), ['certificate_id_or_desc' => 'Production Cert']);

    expect($args[0])->toBe('abc');
    expect($args[1])->toBe('Production Cert');
    expect($args[5])->toBeFalse(); // 未要求默认且原非默认
});

test('bind：指定 id/desc 未找到则业务报错（且仍登出）', function () {
    $client = Mockery::mock(SynologydsmClient::class);
    $client->shouldReceive('login')->once();
    $client->shouldReceive('listCertificates')->once()->andReturn([['id' => 'a', 'desc' => 'A']]);
    $client->shouldReceive('importCertificate')->never();
    $client->shouldReceive('logout')->once(); // finally 兜底

    $deployer = synologyDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind(synologyCertRef(), synologyCreds(), ['certificate_id_or_desc' => 'missing']))
        ->toThrow(RuntimeException::class, "未找到证书 'missing'");
});

test('bind 遇 SynologydsmApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(SynologydsmClient::class);
    $client->shouldReceive('login')->andThrow(new SynologydsmApiException('400', 'Invalid password or account does not exist'));

    $deployer = synologyDeployerWith(fn () => $client);
    try {
        $deployer->bind(synologyCertRef(), ['server_url' => 'https://nas.example.com:5001', 'username' => 'admin', 'password' => 'PASS-LEAK-123'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('Invalid password');
        expect($e->getMessage())->not->toContain('PASS-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('PASS-LEAK-123');
    }
});

// ============ 客户端线协议（MockHandler 捕获实际外发请求）============

test('client：login 先 query.cgi（API info）再 auth.cgi（login，带 account/passwd/enable_syno_token）', function () {
    $history = new ArrayObject;
    $client = synologyClientWithMock(synologyLoginResponses(), $history);

    $client->login();

    expect($history)->toHaveCount(2);
    /** @var RequestInterface $infoReq */
    $infoReq = $history[0]['request'];
    expect($infoReq->getUri()->getPath())->toBe('/webapi/query.cgi');
    parse_str($infoReq->getUri()->getQuery(), $infoQuery);
    expect($infoQuery['api'])->toBe('SYNO.API.Info');
    expect($infoQuery['query'])->toBe('SYNO.API.Auth');

    /** @var RequestInterface $loginReq */
    $loginReq = $history[1]['request'];
    expect($loginReq->getUri()->getPath())->toBe('/webapi/auth.cgi');
    parse_str($loginReq->getUri()->getQuery(), $loginQuery);
    expect($loginQuery['api'])->toBe('SYNO.API.Auth');
    expect($loginQuery['method'])->toBe('login');
    expect($loginQuery['version'])->toBe('7'); // 来自 query.cgi 的 maxVersion
    expect($loginQuery['account'])->toBe('admin');
    expect($loginQuery['passwd'])->toBe('PASS');
    expect($loginQuery['enable_syno_token'])->toBe('yes');
    expect($loginQuery)->not->toHaveKey('otp_code'); // 无 TOTP
});

test('client：importCertificate 登录后 POST entry.cgi（_sid/SynoToken 查询 + X-SYNO-TOKEN 头 + multipart）', function () {
    $history = new ArrayObject;
    $client = synologyClientWithMock([
        ...synologyLoginResponses(),
        new Response(200, [], json_encode(['success' => true, 'data' => ['restart_httpd' => true]])),
    ], $history);

    $client->login();
    $client->importCertificate('cid-1', 'My Cert', 'KEY', 'CERT', 'INTER', true);

    /** @var RequestInterface $req */
    $req = $history[2]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getPath())->toBe('/webapi/entry.cgi');
    parse_str($req->getUri()->getQuery(), $q);
    expect($q['api'])->toBe('SYNO.Core.Certificate');
    expect($q['method'])->toBe('import');
    expect($q['_sid'])->toBe('SID-123');
    expect($q['SynoToken'])->toBe('SYNO-TOK-456');
    expect($req->getHeaderLine('X-SYNO-TOKEN'))->toBe('SYNO-TOK-456');
    expect($req->getHeaderLine('Content-Type'))->toContain('multipart/form-data');
    $body = (string) $req->getBody();
    expect($body)->toContain('name="key"')->toContain('name="cert"')->toContain('name="inter_cert"')->toContain('name="as_default"');
    expect($body)->toContain('CERT')->toContain('KEY')->toContain('INTER');
});

test('client：listCertificates 解析 data.certificates 列表', function () {
    $history = new ArrayObject;
    $client = synologyClientWithMock([
        ...synologyLoginResponses(),
        new Response(200, [], json_encode(['success' => true, 'data' => ['certificates' => [['id' => 'a', 'desc' => 'A'], ['id' => 'b', 'desc' => 'B']]]])),
    ], $history);

    $client->login();
    $certs = $client->listCertificates();

    expect($certs)->toHaveCount(2);
    expect($certs[0]['id'])->toBe('a');
    /** @var RequestInterface $req */
    $req = $history[2]['request'];
    parse_str($req->getUri()->getQuery(), $q);
    expect($q['api'])->toBe('SYNO.Core.Certificate.CRT');
    expect($q['method'])->toBe('list');
});

test('client：setServiceCertificates POST form settings 到 Certificate.Service:set', function () {
    $history = new ArrayObject;
    $client = synologyClientWithMock([
        ...synologyLoginResponses(),
        new Response(200, [], json_encode(['success' => true, 'data' => []])),
    ], $history);
    $client->login();
    $settings = [['service' => ['service' => 'DSM'], 'old_id' => 'old', 'id' => 'new']];
    $client->setServiceCertificates($settings);

    /** @var RequestInterface $req */
    $req = $history[2]['request'];
    expect($req->getMethod())->toBe('POST');
    parse_str($req->getUri()->getQuery(), $query);
    parse_str((string) $req->getBody(), $form);
    expect($query['api'])->toBe('SYNO.Core.Certificate.Service');
    expect($query['method'])->toBe('set');
    expect(json_decode($form['settings'], true))->toBe($settings);
});

test('client：success=false → SynologydsmApiException（error.code 映射可读描述，无 OTP 密钥）', function () {
    $history = new ArrayObject;
    // query.cgi 成功，auth.cgi 返回 success=false code=403（需要 OTP）
    $client = synologyClientWithMock([
        new Response(200, [], json_encode(['success' => true, 'data' => ['SYNO.API.Auth' => ['path' => 'auth.cgi', 'maxVersion' => 7]]])),
        new Response(200, [], json_encode(['success' => false, 'error' => ['code' => 403]])),
    ], $history);

    try {
        $client->login();
        expect(false)->toBeTrue('应抛异常');
    } catch (SynologydsmApiException $e) {
        expect($e->getErrorCode())->toBe('403');
        expect($e->getErrorMessage())->toContain('2-factor authentication code required');
    }
});

test('client：HTTP 非 2xx → SynologydsmApiException（HTTP 状态码）', function () {
    $client = synologyClientWithMock([new Response(500, [], 'oops')], new ArrayObject);
    expect(fn () => $client->login())
        ->toThrow(SynologydsmApiException::class, '500');
});

test('client：启用 TOTP 时 login 带 6 位 otp_code（与同步 RFC6238 计算一致）', function () {
    $history = new ArrayObject;
    // base32 "JBSWY3DPEHPK3PXP" 是常用 TOTP 测试密钥
    $secret = 'JBSWY3DPEHPK3PXP';
    $client = synologyClientWithMock(synologyLoginResponses(), $history, 'admin', 'PASS', $secret);

    $client->login();

    /** @var RequestInterface $loginReq */
    $loginReq = $history[1]['request'];
    parse_str($loginReq->getUri()->getQuery(), $loginQuery);
    expect($loginQuery)->toHaveKey('otp_code');
    expect($loginQuery['otp_code'])->toMatch('/^\d{6}$/');

    // 与独立 RFC6238 参考实现对比，验证算法正确。容忍 30s 窗口在 client 计算与本断言之间
    // 滚动一次（client 早于此处计算）：接受当前窗口或上一窗口的码，消除边界 flake。
    $now = intdiv(time(), 30);
    $candidates = [referenceTotp($secret, $now), referenceTotp($secret, $now - 1)];
    expect($loginQuery['otp_code'])->toBeIn($candidates);
});

test('makeClient：allow_insecure_connections=true 时不抛、能造出 SynologydsmClient', function () {
    $deployer = new CertificateDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', ['server_url' => 'https://1.1.1.1', 'username' => 'u', 'password' => 'p', 'allow_insecure_connections' => true]);
    expect($client)->toBeInstanceOf(SynologydsmClient::class);
});

/** 独立 RFC6238 TOTP 参考实现（与被测客户端实现互校），避免「自证」。 */
function referenceTotp(string $b32, int $counter): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) {
            $key .= chr((int) bindec($b));
        }
    }
    $bin = pack('N*', 0).pack('N*', $counter);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

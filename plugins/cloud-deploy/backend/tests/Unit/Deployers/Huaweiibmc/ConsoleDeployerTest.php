<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Huaweiibmc\ConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Huaweiibmc\HuaweiibmcClient;
use Plugins\CloudDeploy\Deployers\Huaweiibmc\HuaweiibmcProvider;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{cert:string,key:string,chain:string} */
function huaweiibmcCertificateMaterial(): array
{
    $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $caCsr = openssl_csr_new(['commonName' => 'iBMC Test CA'], $caKey, ['digest_alg' => 'sha256']);
    $caCertificate = openssl_csr_sign($caCsr, null, $caKey, 2, ['digest_alg' => 'sha256']);
    openssl_x509_export($caCertificate, $chainPem);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'ibmc.example.com'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, $caCertificate, $caKey, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certPem);
    openssl_pkey_export($key, $keyPem);

    return ['cert' => $certPem, 'key' => $keyPem, 'chain' => $chainPem];
}

function huaweiibmcDeployerWith(callable $factory): ConsoleDeployer
{
    return new class($factory) extends ConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('Huawei iBMC provider 标记目标地址与敏感凭证', function () {
    $provider = new HuaweiibmcProvider;
    $schema = collect($provider->credentialSchema())->keyBy('key');
    $deployer = new ConsoleDeployer;
    $autoRestart = collect($deployer->configSchema())->firstWhere('key', 'auto_restart');

    expect($provider->key())->toBe('huaweiibmc')
        ->and($schema['host']['destination'])->toBeTrue()
        ->and($schema['password']['secret'])->toBeTrue()
        ->and($deployer->provider())->toBe('huaweiibmc')
        ->and($deployer->product())->toBe('console')
        ->and(array_column($deployer->configSchema(), 'key'))->toBe(['auto_restart'])
        ->and($autoRestart['default'] ?? null)->toBeTrue()
        ->and($deployer->usesRemoteCertStore())->toBeFalse();
});

test('bind 生成随机口令 PFX 导入每个 Manager 并按开关强制重启', function () {
    $material = huaweiibmcCertificateMaterial();
    $captured = null;
    $client = Mockery::mock(HuaweiibmcClient::class);
    $client->shouldReceive('createSession')->once();
    $client->shouldReceive('listManagers')->once()->andReturn([
        ['Id' => 'BMC1', '@odata.id' => '/redfish/v1/Managers/BMC1'],
        ['Id' => 'BMC2', '@odata.id' => '/redfish/v1/Managers/BMC2'],
    ]);
    $client->shouldReceive('importCustomCertificate')->twice()->andReturnUsing(function (string $location, array $body) use (&$captured) {
        $captured ??= [$location, $body];
    });
    $client->shouldReceive('resetManager')->twice()->with(Mockery::type('string'), 'ForceRestart');
    $client->shouldReceive('deleteSession')->once();

    $deployer = huaweiibmcDeployerWith(fn () => $client);
    $deployer->bind(['cert' => $material['cert'], 'key' => $material['key'], 'chain' => $material['chain']], [
        'host' => 'ibmc.example.com', 'username' => 'admin', 'password' => 'secret',
    ], []);

    [$location, $body] = $captured;
    $pfxFile = tempnam(sys_get_temp_dir(), 'ibmc_legacy_test_');
    file_put_contents($pfxFile, base64_decode($body['Certificate']));
    chmod($pfxFile, 0600);
    $info = new Process([
        'openssl', 'pkcs12', '-info', '-noout', '-legacy', '-in', $pfxFile,
        '-passin', 'env:IBMC_TEST_PFX_PASSWORD',
    ], null, ['IBMC_TEST_PFX_PASSWORD' => $body['Password']]);
    $info->run();
    $dump = new Process([
        'openssl', 'pkcs12', '-nodes', '-legacy', '-in', $pfxFile,
        '-passin', 'env:IBMC_TEST_PFX_PASSWORD',
    ], null, ['IBMC_TEST_PFX_PASSWORD' => $body['Password']]);
    $dump->run();
    @unlink($pfxFile);

    expect($location)->toBe('/redfish/v1/Managers/BMC1')
        ->and($body)->toHaveKeys(['Certificate', 'Password'])
        ->and(strlen($body['Password']))->toBe(48)
        ->and($info->isSuccessful())->toBeTrue()
        ->and($info->getErrorOutput())->toContain('RC2-CBC')
        ->and($dump->isSuccessful())->toBeTrue()
        ->and(substr_count($dump->getOutput(), '-----BEGIN CERTIFICATE-----'))->toBe(2)
        ->and($dump->getOutput())->toContain('-----BEGIN PRIVATE KEY-----');
});

test('bind 中途失败仍删除 Redfish session 且异常不泄凭证', function () {
    $material = huaweiibmcCertificateMaterial();
    $client = Mockery::mock(HuaweiibmcClient::class);
    $client->shouldReceive('createSession')->once();
    $client->shouldReceive('listManagers')->once()->andReturn([['Id' => 'BMC1']]);
    $client->shouldReceive('importCustomCertificate')->once()->andThrow(new RuntimeException('PASSWORD-LEAK'));
    $client->shouldReceive('deleteSession')->once();

    $deployer = huaweiibmcDeployerWith(fn () => $client);
    try {
        $deployer->bind(['cert' => $material['cert'], 'key' => $material['key'], 'chain' => ''], [
            'host' => 'ibmc.example.com', 'username' => 'admin', 'password' => 'PASSWORD-LEAK',
        ], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('PASSWORD-LEAK')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('Redfish 客户端会话、Manager、导入、重启及特殊成功响应协议对齐', function () {
    $history = new ArrayObject;
    $mock = new MockHandler([
        new Response(201, ['X-Auth-Token' => 'session-token', 'Location' => '/redfish/v1/SessionService/Sessions/1'], '{}'),
        new Response(200, [], json_encode(['Members' => [['Id' => 'BMC1', '@odata.id' => '/redfish/v1/Managers/BMC1']]])),
        new Response(400, [], json_encode(['error' => ['@Message.ExtendedInfo' => [['MessageId' => 'iBMC.1.0.CertImportOK']]]])),
        new Response(400, [], json_encode(['error' => ['@Message.ExtendedInfo' => [['MessageId' => 'Base.1.0.Success']]]])),
        new Response(204),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new HuaweiibmcClient(new Client([
        'handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    $client->createSession('admin', 'password');
    $members = $client->listManagers();
    $client->importCustomCertificate('/redfish/v1/Managers/BMC1', ['Certificate' => 'PFX', 'Password' => 'PFX-PASS']);
    $client->resetManager('/redfish/v1/Managers/BMC1', 'ForceRestart');
    $client->deleteSession();

    /** @var RequestInterface $session */
    $session = $history[0]['request'];
    expect($session->getUri()->getPath())->toBe('/redfish/v1/SessionService/Sessions')
        ->and(json_decode((string) $session->getBody(), true))->toBe(['UserName' => 'admin', 'Password' => 'password'])
        ->and($members[0]['Id'])->toBe('BMC1')
        ->and($history[1]['request']->getHeaderLine('X-Auth-Token'))->toBe('session-token')
        ->and($history[2]['request']->getUri()->getPath())->toBe('/redfish/v1/Managers/BMC1/SecurityService/HttpsCert/Actions/HttpsCert.ImportCustomCertificate')
        ->and(json_decode((string) $history[2]['request']->getBody(), true))->toMatchArray(['Certificate' => 'PFX', 'Password' => 'PFX-PASS'])
        ->and($history[3]['request']->getUri()->getPath())->toBe('/redfish/v1/Managers/BMC1/Actions/Manager.Reset')
        ->and(json_decode((string) $history[3]['request']->getBody(), true))->toBe(['ResetType' => 'ForceRestart'])
        ->and($history[4]['request']->getMethod())->toBe('DELETE');
});

test('Redfish 拒绝跨源 session Location', function () {
    $mock = new MockHandler([
        new Response(201, ['X-Auth-Token' => 'token', 'Location' => 'https://evil.example/session/1'], '{}'),
    ]);
    $client = new HuaweiibmcClient(new Client([
        'handler' => HandlerStack::create($mock), 'http_errors' => false, 'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    expect(fn () => $client->createSession('admin', 'password'))
        ->toThrow(RuntimeException::class, '跨源');
});

test('Redfish 拒绝空 Location 且不会发送 DELETE', function () {
    $history = new ArrayObject;
    $mock = new MockHandler([
        new Response(201, ['X-Auth-Token' => 'token'], '{}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new HuaweiibmcClient(new Client([
        'handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    expect(fn () => $client->createSession('admin', 'password'))
        ->toThrow(RuntimeException::class, 'Location');
    $client->deleteSession();
    expect($history)->toHaveCount(1);
});

test('Redfish 拒绝同源但错误路径的 Location 且不会发送 DELETE', function () {
    $history = new ArrayObject;
    $mock = new MockHandler([
        new Response(201, ['X-Auth-Token' => 'token', 'Location' => '/redfish/v1/Managers/BMC1'], '{}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new HuaweiibmcClient(new Client([
        'handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    expect(fn () => $client->createSession('admin', 'password'))
        ->toThrow(RuntimeException::class, 'Location');
    $client->deleteSession();
    expect($history)->toHaveCount(1);
});

test('Redfish 客户端不采用远端 error.code', function () {
    $client = new HuaweiibmcClient(new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['error' => ['code' => 'TOKEN-LEAK', 'message' => 'PFX-PASSWORD-LEAK']])),
        ])),
        'http_errors' => false,
        'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    try {
        $client->listManagers();
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('HuaweiibmcApiError')
            ->not->toContain('TOKEN-LEAK')
            ->not->toContain('PFX-PASSWORD-LEAK');
    }
});

test('Redfish 非空 2xx 响应必须是合法 JSON 对象', function (string $body) {
    $client = new HuaweiibmcClient(new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], $body),
        ])),
        'http_errors' => false,
        'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    expect(fn () => $client->listManagers())
        ->toThrow(RuntimeException::class, 'HuaweiibmcInvalidResponse');
})->with([
    '畸形 JSON' => ['{"Members":'],
    'JSON 数组' => ['[]'],
    'JSON 标量' => ['false'],
]);

test('Redfish 建立会话也拒绝非空畸形 2xx JSON', function () {
    $client = new HuaweiibmcClient(new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(201, ['X-Auth-Token' => 'token', 'Location' => '/redfish/v1/SessionService/Sessions/1'], '{'),
        ])),
        'http_errors' => false,
        'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    expect(fn () => $client->createSession('admin', 'password'))
        ->toThrow(RuntimeException::class, 'HuaweiibmcInvalidResponse');
});

test('Redfish 空 2xx 响应与 Certimate SDK 空 body 语义一致', function () {
    $client = new HuaweiibmcClient(new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200),
        ])),
        'http_errors' => false,
        'base_uri' => 'https://ibmc.example.com/',
    ]), 'https://ibmc.example.com/');

    expect($client->listManagers())->toBe([]);
});

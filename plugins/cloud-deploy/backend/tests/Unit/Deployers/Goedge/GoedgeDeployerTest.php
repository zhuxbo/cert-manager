<?php

use Plugins\CloudDeploy\Deployers\Goedge\GoedgeApiException;
use Plugins\CloudDeploy\Deployers\Goedge\GoedgeDeployer;
use Plugins\CloudDeploy\Deployers\Goedge\GoedgeRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock GoedgeRestClient。 */
function goedgeDeployerWith(callable $clientFactory): GoedgeDeployer
{
    return new class($clientFactory) extends GoedgeDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

/** 生成带 CN + SAN 的真实自签证书，供 parseCert 验证元信息提取。 */
function goedgeRealCert(): array
{
    $conf = sys_get_temp_dir().'/goedge_san_'.uniqid().'.cnf';
    file_put_contents($conf, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:test.example.com,DNS:www.example.com\n");
    $privkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'test.example.com'], $privkey, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $privkey, 1, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3'], 0);
    openssl_x509_export($x509, $certPem);
    @unlink($conf);
    $parsed = openssl_x509_parse($certPem);

    return [$certPem, (int) $parsed['validFrom_time_t'], (int) $parsed['validTo_time_t']];
}

function goedgeCreds(): array
{
    return ['server_url' => 'https://edge:7788', 'api_role' => 'user', 'access_key_id' => 'AKID', 'access_key' => 'AK'];
}

test('GoEdge 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new GoedgeDeployer;
    expect($deployer->provider())->toBe('goedge');
    expect($deployer->product())->toBe('goedge');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('bind 调 updateSslCert：id 转 int、cert/key base64、解析 CN/SAN/有效期', function () {
    [$certPem, $nbf, $naf] = goedgeRealCert();
    $captured = null;
    $client = Mockery::mock(GoedgeRestClient::class);
    $client->shouldReceive('updateSslCert')->once()
        ->andReturnUsing(function (int $id, string $serverName, string $cert, string $key, int $b, int $e, array $dns) use (&$captured) {
            $captured = compact('id', 'serverName', 'cert', 'key', 'b', 'e', 'dns');
        });

    $deployer = goedgeDeployerWith(fn () => $client);
    $deployer->bind(['cert' => $certPem, 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'], goedgeCreds(), ['certificate_id' => '88']);

    expect($captured['id'])->toBe(88);
    expect($captured['serverName'])->toBe('test.example.com');           // CN
    expect($captured['dns'])->toBe(['test.example.com', 'www.example.com']); // SAN
    expect($captured['b'])->toBe($nbf);
    expect($captured['e'])->toBe($naf);
    // 传给 client 的是原始 PEM（base64 由 client 内部做）；cert 为完整链
    expect($captured['cert'])->toContain('BEGIN CERTIFICATE')->toContain('CHAINPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('解析失败的证书也不阻断 bind（CN/SAN 空、有效期 0）', function () {
    $captured = null;
    $client = Mockery::mock(GoedgeRestClient::class);
    $client->shouldReceive('updateSslCert')->once()
        ->andReturnUsing(function (int $id, string $serverName, string $cert, string $key, int $b, int $e, array $dns) use (&$captured) {
            $captured = compact('serverName', 'dns', 'b', 'e');
        });

    $deployer = goedgeDeployerWith(fn () => $client);
    $deployer->bind(['cert' => 'NOT-A-CERT', 'key' => 'K', 'chain' => ''], goedgeCreds(), ['certificate_id' => 1]);

    expect($captured)->toBe(['serverName' => '', 'dns' => [], 'b' => 0, 'e' => 0]);
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = goedgeDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], goedgeCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 GoedgeApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(GoedgeRestClient::class);
    $client->shouldReceive('updateSslCert')->andThrow(new GoedgeApiException('400', 'cert not found'));

    $deployer = goedgeDeployerWith(fn () => $client);
    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], [
            'server_url' => 'https://edge:7788', 'api_role' => 'user', 'access_key_id' => 'AKID', 'access_key' => 'AK-LEAK-123',
        ], ['certificate_id' => 1]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('cert not found');
        expect($e->getMessage())->not->toContain('AK-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-123');
    }
});

test('makeClient 注入 base_uri + allow_insecure 关 TLS（凭证不入 Guzzle 头）', function () {
    $deployer = new GoedgeDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', array_replace(goedgeCreds(), ['allow_insecure' => true, 'server_url' => 'https://1.1.1.1:7788/']));
    expect($client)->toBeInstanceOf(GoedgeRestClient::class);

    $httpProp = new ReflectionProperty($client, 'http');
    $httpProp->setAccessible(true);
    $guzzle = $httpProp->getValue($client);
    $cfg = new ReflectionMethod($guzzle, 'getConfig');
    $cfg->setAccessible(true);
    expect($cfg->invoke($guzzle, 'verify'))->toBeFalse();
    expect((string) $cfg->invoke($guzzle, 'base_uri'))->toBe('https://1.1.1.1:7788/');
});

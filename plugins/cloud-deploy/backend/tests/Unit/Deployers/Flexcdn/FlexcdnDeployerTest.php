<?php

use Plugins\CloudDeploy\Deployers\Flexcdn\FlexcdnApiException;
use Plugins\CloudDeploy\Deployers\Flexcdn\FlexcdnDeployer;
use Plugins\CloudDeploy\Deployers\Flexcdn\FlexcdnRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock FlexcdnRestClient。 */
function flexcdnDeployerWith(callable $clientFactory): FlexcdnDeployer
{
    return new class($clientFactory) extends FlexcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

/** 生成带 CN + SAN 的真实自签证书，供 parseCert 验证元信息提取。 */
function flexcdnRealCert(): array
{
    $conf = sys_get_temp_dir().'/flexcdn_san_'.uniqid().'.cnf';
    file_put_contents($conf, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:a.example.com,DNS:b.example.com\n");
    $privkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $privkey, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $privkey, 1, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3'], 0);
    openssl_x509_export($x509, $certPem);
    @unlink($conf);
    $parsed = openssl_x509_parse($certPem);

    return [$certPem, (int) $parsed['validFrom_time_t'], (int) $parsed['validTo_time_t']];
}

function flexcdnCreds(): array
{
    return ['server_url' => 'https://edge:7788', 'api_role' => 'admin', 'access_key_id' => 'AKID', 'access_key' => 'AK'];
}

test('FlexCDN 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new FlexcdnDeployer;
    expect($deployer->provider())->toBe('flexcdn');
    expect($deployer->product())->toBe('flexcdn');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('bind 调 updateSslCert：id 转 int、完整链 cert、解析 CN/SAN/有效期', function () {
    [$certPem, $nbf, $naf] = flexcdnRealCert();
    $captured = null;
    $client = Mockery::mock(FlexcdnRestClient::class);
    $client->shouldReceive('updateSslCert')->once()
        ->andReturnUsing(function (int $id, string $serverName, string $cert, string $key, int $b, int $e, array $dns) use (&$captured) {
            $captured = compact('id', 'serverName', 'cert', 'key', 'b', 'e', 'dns');
        });

    $deployer = flexcdnDeployerWith(fn () => $client);
    $deployer->bind(['cert' => $certPem, 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'], flexcdnCreds(), ['certificate_id' => '5']);

    expect($captured['id'])->toBe(5);
    expect($captured['serverName'])->toBe('a.example.com');
    expect($captured['dns'])->toBe(['a.example.com', 'b.example.com']);
    expect($captured['b'])->toBe($nbf);
    expect($captured['e'])->toBe($naf);
    expect($captured['cert'])->toContain('CHAINPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = flexcdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], flexcdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 FlexcdnApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(FlexcdnRestClient::class);
    $client->shouldReceive('updateSslCert')->andThrow(new FlexcdnApiException('403', 'forbidden'));

    $deployer = flexcdnDeployerWith(fn () => $client);
    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], [
            'server_url' => 'https://edge:7788', 'api_role' => 'admin', 'access_key_id' => 'AKID', 'access_key' => 'AK-LEAK-123',
        ], ['certificate_id' => 1]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('403')->toContain('forbidden');
        expect($e->getMessage())->not->toContain('AK-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-123');
    }
});

test('makeClient 注入 base_uri + allow_insecure 关 TLS', function () {
    $deployer = new FlexcdnDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', flexcdnCreds() + ['allow_insecure' => true, 'server_url' => 'https://edge:7788/']);

    $httpProp = new ReflectionProperty($client, 'http');
    $httpProp->setAccessible(true);
    $guzzle = $httpProp->getValue($client);
    $cfg = new ReflectionMethod($guzzle, 'getConfig');
    $cfg->setAccessible(true);
    expect($cfg->invoke($guzzle, 'verify'))->toBeFalse();
    expect((string) $cfg->invoke($guzzle, 'base_uri'))->toBe('https://edge:7788/');
});

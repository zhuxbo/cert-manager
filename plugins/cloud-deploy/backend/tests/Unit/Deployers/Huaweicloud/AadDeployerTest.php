<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\AadDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** makeClient 2 参（kind/credentials）。aad（全局，list + set-cert）。 */
function hwAadDeployerWith(callable $clientFactory): AadDeployer
{
    return new class($clientFactory) extends AadDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function hwAadCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwAadConfig(): array
{
    return ['instance_id' => 'aad-inst-1', 'domain' => 'aad.example.com'];
}

function hwAadCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

/** 生成含通配符 SAN 的真实证书，验证 certsan 匹配与单层通配语义。 */
function hwAadCertificateWithWildcardSan(): string
{
    $conf = sys_get_temp_dir().'/hwaad_san_'.uniqid('', true).'.cnf';
    file_put_contents($conf, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:api.example.com,DNS:*.wild.example.com\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'api.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, [
        'digest_alg' => 'sha256',
        'config' => $conf,
        'x509_extensions' => 'v3',
    ]);
    openssl_x509_export($cert, $pem);
    @unlink($conf);

    return $pem;
}

test('华为云 AAD：内联型 + schema 对齐 region 与域名匹配模式', function () {
    $deployer = new AadDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->product())->toBe('aad');
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('instance_id')->toContain('domain_match_pattern')->toContain('domain');
});

test('bind：region 透传给客户端，未配置时默认 cn-north-4', function () {
    $regions = [];
    $aad = Mockery::mock(HuaweicloudRestClient::class);
    $aad->shouldReceive('get')->twice()->andReturn(['domains' => [
        ['domain_id' => 'd-9', 'domain_name' => 'aad.example.com', 'domain_status' => '0'],
    ]]);
    $aad->shouldReceive('post')->twice()->andReturn([]);

    $deployer = hwAadDeployerWith(function (string $kind, array $credentials) use (&$regions, $aad) {
        $regions[] = $credentials['region'] ?? null;

        return $aad;
    });
    $deployer->bind(hwAadCertRef(), hwAadCreds(), hwAadConfig());
    $deployer->bind(hwAadCertRef(), hwAadCreds(), array_replace(hwAadConfig(), ['region' => 'cn-east-3']));

    expect($regions)->toBe(['cn-north-4', 'cn-east-3']);
});

test('bind：ListInstanceDomains exact 匹配域名后 SetCertForDomain（op_type=0 + 直灌 PEM）', function () {
    $listPath = null;
    $postCaptured = null;
    $aad = Mockery::mock(HuaweicloudRestClient::class);
    $aad->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $q = []) use (&$listPath) {
        $listPath = $path;

        return ['domains' => [
            ['domain_id' => 'd-other', 'domain_name' => 'other.example.com', 'domain_status' => '0'],
            ['domain_id' => 'd-9', 'domain_name' => 'aad.example.com', 'domain_status' => '0'],
        ]];
    });
    $aad->shouldReceive('post')->once()->andReturnUsing(function (string $path, ?array $body, array $q = []) use (&$postCaptured) {
        $postCaptured = compact('path', 'body');

        return [];
    });

    $deployer = hwAadDeployerWith(fn (string $kind) => $kind === 'aad' ? $aad : new stdClass);
    $deployer->bind(hwAadCertRef(), hwAadCreds(), hwAadConfig());

    expect($listPath)->toBe('/v2/aad/instances/aad-inst-1/domains');
    expect($postCaptured['path'])->toBe('/v1/aad/domains/set-cert');
    $b = $postCaptured['body'];
    expect($b['op_type'])->toBe(0);
    expect($b['domain_id'])->toBe('d-9');
    expect($b['cert_file'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($b['cert_key_file'])->toBe('KEYPEM');
    expect($b['cert_name'])->toStartWith('clouddeploy_');
});

test('bind：跳过停用状态域名（domain_status=1），找不到则 DomainNotFound', function () {
    $aad = Mockery::mock(HuaweicloudRestClient::class);
    $aad->shouldReceive('get')->andReturn(['domains' => [
        ['domain_id' => 'd-9', 'domain_name' => 'aad.example.com', 'domain_status' => '1'], // 停用，跳过
    ]]);

    $deployer = hwAadDeployerWith(fn () => $aad);
    expect(fn () => $deployer->bind(hwAadCertRef(), hwAadCreds(), hwAadConfig()))
        ->toThrow(RuntimeException::class, 'DomainNotFound');
});

test('bind：wildcard 匹配全部单层子域并批量设置证书', function () {
    $updated = [];
    $aad = Mockery::mock(HuaweicloudRestClient::class);
    $aad->shouldReceive('get')->once()->andReturn(['domains' => [
        ['domain_id' => 'd-a', 'domain_name' => 'a.example.com', 'domain_status' => '0'],
        ['domain_id' => 'd-b', 'domain_name' => 'b.example.com', 'domain_status' => '0'],
        ['domain_id' => 'd-deep', 'domain_name' => 'deep.a.example.com', 'domain_status' => '0'],
        ['domain_id' => 'd-off', 'domain_name' => 'off.example.com', 'domain_status' => '1'],
    ]]);
    $aad->shouldReceive('post')->twice()->andReturnUsing(function (string $path, array $body) use (&$updated) {
        $updated[] = $body['domain_id'];

        return [];
    });

    $deployer = hwAadDeployerWith(fn () => $aad);
    $deployer->bind(hwAadCertRef(), hwAadCreds(), [
        'instance_id' => 'aad-inst-1',
        'domain_match_pattern' => 'wildcard',
        'domain' => '*.example.com',
    ]);

    expect($updated)->toBe(['d-a', 'd-b']);
});

test('bind：certsan 以证书 SAN 匹配精确域名与单层通配域名', function () {
    $updated = [];
    $aad = Mockery::mock(HuaweicloudRestClient::class);
    $aad->shouldReceive('get')->once()->andReturn(['domains' => [
        ['domain_id' => 'd-api', 'domain_name' => 'api.example.com', 'domain_status' => '0'],
        ['domain_id' => 'd-wild', 'domain_name' => 'a.wild.example.com', 'domain_status' => '0'],
        ['domain_id' => 'd-deep', 'domain_name' => 'deep.a.wild.example.com', 'domain_status' => '0'],
        ['domain_id' => 'd-other', 'domain_name' => 'other.example.com', 'domain_status' => '0'],
    ]]);
    $aad->shouldReceive('post')->twice()->andReturnUsing(function (string $path, array $body) use (&$updated) {
        $updated[] = $body['domain_id'];

        return [];
    });

    $deployer = hwAadDeployerWith(fn () => $aad);
    $deployer->bind([
        'cert' => hwAadCertificateWithWildcardSan(),
        'key' => 'KEYPEM',
        'chain' => '',
    ], hwAadCreds(), [
        'instance_id' => 'aad-inst-1',
        'domain_match_pattern' => 'certsan',
        'domain' => '',
    ]);

    expect($updated)->toBe(['d-api', 'd-wild']);
});

test('缺 instance_id / domain 配置抛业务错误', function () {
    $deployer = hwAadDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(hwAadCertRef(), hwAadCreds(), ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 instance_id');
    expect(fn () => $deployer->bind(hwAadCertRef(), hwAadCreds(), ['instance_id' => 'i-1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（无 AK/SK、不挂 previous）', function () {
    $aad = Mockery::mock(HuaweicloudRestClient::class);
    $aad->shouldReceive('get')->andReturn(['domains' => [['domain_id' => 'd-9', 'domain_name' => 'aad.example.com', 'domain_status' => '0']]]);
    $aad->shouldReceive('post')->andThrow(new HuaweicloudApiException('AAD.0001', 'cert invalid'));

    $deployer = hwAadDeployerWith(fn () => $aad);

    try {
        $deployer->bind(hwAadCertRef(), ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], hwAadConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AAD.0001');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

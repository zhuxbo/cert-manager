<?php

use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudCertificateManagerDeployer;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudClient;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudIamAuthenticator;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudProvider;
use Tests\TestCase;

uses(TestCase::class);

function yandexDeployerWith(callable $factory): YandexcloudCertificateManagerDeployer
{
    return new class($factory) extends YandexcloudCertificateManagerDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

/** @return array{cert:string,key:string,chain:string,meta:array<string,mixed>} */
function yandexTestCertificate(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $config = tempnam(sys_get_temp_dir(), 'yandex-openssl-');
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:yandex.example.com,DNS:www.yandex.example.com\n");
    $csr = openssl_csr_new(['commonName' => 'yandex.example.com'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 30, ['x509_extensions' => 'v3', 'config' => $config]);
    unlink($config);
    openssl_x509_export($x509, $cert);
    openssl_pkey_export($key, $keyPem);
    $parsed = openssl_x509_parse($cert);

    return [
        'cert' => $cert,
        'key' => $keyPem,
        'chain' => "-----BEGIN CERTIFICATE-----\nCHAIN\n-----END CERTIFICATE-----",
        'meta' => $parsed,
    ];
}

/** @return array{cert:string,key:string,chain:string,meta:array<string,mixed>} */
function yandexNonStandardDnCertificate(): array
{
    $cert = file_get_contents(dirname(__DIR__, 3).'/Fixtures/yandexcloud-nonstandard-dn.pem');
    expect($cert)->not->toBeFalse();
    $cert = (string) $cert;
    $parsed = openssl_x509_parse($cert);

    return [
        'cert' => $cert,
        'key' => 'TEST-KEY-NOT-SENT-BECAUSE-CERTIFICATE-IS-REUSED',
        'chain' => '',
        'meta' => $parsed,
    ];
}

/** 以测试侧独立规则模拟 Go pkix.Name.String() 的常见 DN 输出。 */
function yandexTestDistinguishedName(array $parts): string
{
    $order = [
        ['serialNumber', 'SERIALNUMBER'], ['CN', 'CN'], ['OU', 'OU'], ['O', 'O'],
        ['postalCode', 'POSTALCODE'], ['street', 'STREET'], ['streetAddress', 'STREET'],
        ['L', 'L'], ['ST', 'ST'], ['C', 'C'],
    ];
    $items = [];
    foreach ($order as [$key, $label]) {
        if (! array_key_exists($key, $parts)) {
            continue;
        }
        $value = $parts[$key];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $items[] = $label.'='.$item;
        }
    }

    return implode(',', $items);
}

test('provider、product 和 schema 对齐 Certimate，且不是 UploadOnly', function () {
    $provider = new YandexcloudProvider;
    $deployer = new YandexcloudCertificateManagerDeployer;

    expect($provider->key())->toBe('yandexcloud');
    expect(array_column($provider->credentialSchema(), 'key'))->toBe(['folder_id', 'service_account_key']);
    expect($provider->credentialSchema()[1]['secret'])->toBeTrue();
    expect($deployer->provider())->toBe('yandexcloud');
    expect($deployer->product())->toBe('certificatemanager');
    expect(array_column($deployer->configSchema(), 'key'))->toBe(['certificate_id']);
    expect($deployer)->not->toBeInstanceOf(UploadOnlyDeployerInterface::class);
    expect($deployer->usesRemoteCertStore())->toBeFalse();
});

test('未指定 certificate_id 时分页查找，不匹配则按 Certimate 命名创建', function () {
    $material = yandexTestCertificate();
    $auth = Mockery::mock(YandexcloudIamAuthenticator::class);
    $auth->shouldReceive('fetchIamToken')->once()->with('SERVICE-ACCOUNT-JSON')->andReturn('iam-token');
    $client = Mockery::mock(YandexcloudClient::class);
    $client->shouldReceive('listCertificates')->once()->with('folder-id', '')->andReturn([
        'certificates' => [['id' => 'other', 'domains' => ['other.example.com']]],
        'nextPageToken' => 'page-2',
    ]);
    $client->shouldReceive('listCertificates')->once()->with('folder-id', 'page-2')->andReturn([
        'certificates' => [],
        'nextPageToken' => '',
    ]);
    $client->shouldReceive('createCertificate')->once()->andReturnUsing(function (array $body) use ($material) {
        expect($body['folderId'])->toBe('folder-id');
        expect($body['name'])->toMatch('/^certimate-\d{13}$/');
        expect($body['description'])->toBe('upload from Certimate');
        expect($body['certificate'])->toBe($material['cert']);
        expect($body['chain'])->toBe($material['chain']);
        expect($body['privateKey'])->toBe($material['key']);

        return ['id' => 'created-cert'];
    });

    $deployer = yandexDeployerWith(fn (string $kind, array $credentials) => match ($kind) {
        'iam' => $auth,
        'api' => tap($client, fn () => expect($credentials['iam_token'])->toBe('iam-token')),
    });
    $deployer->bind($material, [
        'folder_id' => 'folder-id',
        'service_account_key' => 'SERVICE-ACCOUNT-JSON',
    ], []);
});

test('完全相同证书复用已有证书，不再创建', function () {
    $material = yandexTestCertificate();
    $meta = $material['meta'];
    $auth = Mockery::mock(YandexcloudIamAuthenticator::class);
    $auth->shouldReceive('fetchIamToken')->andReturn('iam-token');
    $client = Mockery::mock(YandexcloudClient::class);
    $client->shouldReceive('listCertificates')->once()->andReturn([
        'certificates' => [[
            'id' => 'existing-cert',
            'domains' => ['yandex.example.com', 'www.yandex.example.com'],
            'subject' => yandexTestDistinguishedName($meta['subject']),
            'issuer' => yandexTestDistinguishedName($meta['issuer']),
            'serial' => strtolower((string) $meta['serialNumberHex']),
            'notBefore' => gmdate('Y-m-d\TH:i:s\Z', $meta['validFrom_time_t']),
            'notAfter' => gmdate('Y-m-d\TH:i:s\Z', $meta['validTo_time_t']),
        ]],
        'nextPageToken' => '',
    ]);
    $client->shouldNotReceive('createCertificate');

    $deployer = yandexDeployerWith(fn (string $kind) => $kind === 'iam' ? $auth : $client);
    $deployer->bind($material, ['folder_id' => 'folder-id', 'service_account_key' => 'SA'], []);
});

test('含 emailAddress 非标准 OID 和多字段 DN 时按 Go pkix.Name.String 完全匹配复用', function () {
    $material = yandexNonStandardDnCertificate();
    $meta = $material['meta'];
    $auth = Mockery::mock(YandexcloudIamAuthenticator::class);
    $auth->shouldReceive('fetchIamToken')->andReturn('iam-token');
    $client = Mockery::mock(YandexcloudClient::class);
    // 由 Go crypto/x509 解析同构证书后 `cert.Subject.String()` 预计算。
    $goAuthoritativeDn = 'CN=dn.example.com,OU=Certificate Team,O=Example Org,L=Shanghai,ST=Shanghai,C=CN,1.2.840.113549.1.9.1=#0c116365727473406578616d706c652e636f6d';
    $client->shouldReceive('listCertificates')->once()->andReturn([
        'certificates' => [[
            'id' => 'existing-cert',
            'domains' => ['dn.example.com', 'www.dn.example.com'],
            'subject' => $goAuthoritativeDn,
            'issuer' => $goAuthoritativeDn,
            'serial' => strtolower((string) $meta['serialNumberHex']),
            'notBefore' => gmdate('Y-m-d\TH:i:s\Z', $meta['validFrom_time_t']),
            'notAfter' => gmdate('Y-m-d\TH:i:s\Z', $meta['validTo_time_t']),
        ]],
        'nextPageToken' => '',
    ]);
    $client->shouldNotReceive('createCertificate');

    $deployer = yandexDeployerWith(fn (string $kind) => $kind === 'iam' ? $auth : $client);
    $deployer->bind($material, ['folder_id' => 'folder-id', 'service_account_key' => 'SA'], []);
});

test('指定 certificate_id 时先读取并保留元数据，再只替换证书材料', function () {
    $material = yandexTestCertificate();
    $auth = Mockery::mock(YandexcloudIamAuthenticator::class);
    $auth->shouldReceive('fetchIamToken')->andReturn('iam-token');
    $client = Mockery::mock(YandexcloudClient::class);
    $client->shouldReceive('getCertificate')->once()->with('cert-id')->andReturn([
        'id' => 'cert-id',
        'name' => 'preserved-name',
        'description' => 'preserved-description',
        'labels' => ['owner' => 'ops'],
        'deletionProtection' => true,
    ]);
    $client->shouldReceive('updateCertificate')->once()->andReturnUsing(function (string $id, array $body) use ($material) {
        expect($id)->toBe('cert-id');
        expect(explode(',', $body['updateMask']))
            ->toBe(['name', 'description', 'labels', 'certificate', 'chain', 'privateKey', 'deletionProtection']);
        expect($body)->toMatchArray([
            'name' => 'preserved-name',
            'description' => 'preserved-description',
            'labels' => ['owner' => 'ops'],
            'deletionProtection' => true,
            'certificate' => $material['cert'],
            'chain' => $material['chain'],
            'privateKey' => $material['key'],
        ]);

        return [];
    });
    $client->shouldNotReceive('listCertificates');
    $client->shouldNotReceive('createCertificate');

    $deployer = yandexDeployerWith(fn (string $kind) => $kind === 'iam' ? $auth : $client);
    $deployer->bind($material, ['folder_id' => 'folder-id', 'service_account_key' => 'SA'], ['certificate_id' => 'cert-id']);
});

test('认证或 API 失败经 guardSdk 脱敏且不挂 previous', function () {
    $auth = Mockery::mock(YandexcloudIamAuthenticator::class);
    $auth->shouldReceive('fetchIamToken')->andThrow(new RuntimeException('secret.jwt.material PRIVATE-KEY-MATERIAL'));
    $material = yandexTestCertificate();
    $deployer = yandexDeployerWith(fn () => $auth);

    try {
        $deployer->bind($material, ['folder_id' => 'folder-id', 'service_account_key' => 'SA'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Yandex Cloud 调用失败: RuntimeException');
        expect($e->getMessage())->not->toContain('secret.jwt.material')->not->toContain('PRIVATE-KEY-MATERIAL');
        expect($e->getPrevious())->toBeNull();
    }
});

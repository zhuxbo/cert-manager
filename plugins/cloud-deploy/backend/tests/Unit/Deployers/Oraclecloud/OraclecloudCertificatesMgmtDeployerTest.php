<?php

use Plugins\CloudDeploy\Deployers\Oraclecloud\OciRequestSigner;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleApiKeyCredentialProvider;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleCertMgmtUploader;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudApiException;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudCertificatesMgmtDeployer;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudClient;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

// OCI host 由 region 派生：stub 策略放行公网 host，注入场景由授权测试覆盖
beforeEach(function () {
    app()->instance(OutboundDestinationPolicy::class, new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
    ));
});

/** 测试子类：override makeClient（signer / api kind）。 */
function oracleDeployerWith(callable $clientFactory): OraclecloudCertificatesMgmtDeployer
{
    return new class($clientFactory) extends OraclecloudCertificatesMgmtDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, ?OciRequestSigner $signer = null, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $signer, $region);
        }
    };
}

/** 生成 RSA 密钥对，返回 [privatePem, publicPem]，用于 OCI 签名 KAT。 */
function ociTestRsaKeypair(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privPem);
    $pub = openssl_pkey_get_details($res)['key'];

    return [$privPem, $pub];
}

function oracleCreds(string $privatePem): array
{
    return [
        'tenancy_ocid' => 'ocid1.tenancy.oc1..tttt',
        'user_ocid' => 'ocid1.user.oc1..uuuu',
        'fingerprint' => 'aa:bb:cc:dd',
        'private_key' => $privatePem,
        'region' => 'ap-tokyo-1',
    ];
}

test('Oracle Cloud 证书管理为证书服务型 + 元信息', function () {
    $deployer = new OraclecloudCertificatesMgmtDeployer;
    [$privateKey] = ociTestRsaKeypair();
    $compartment = 'ocid1.compartment.oc1..cccc';
    expect($deployer->provider())->toBe('oraclecloud');
    expect($deployer->product())->toBe('certificatesmgmt');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['compartment_ocid' => 'ocid1.compartment.oc1..cccc']))
        ->toBeInstanceOf(OracleCertMgmtUploader::class);
    $metadataStoreKind = $deployer->certUploader(['compartment_ocid' => $compartment])->storeKind();
    expect($metadataStoreKind)
        ->toBe('oci:u:8a11bae8c33b56ab09049352')
        ->not->toContain($compartment)
        ->and(strlen($metadataStoreKind))->toBeLessThanOrEqual(32);
    expect($deployer->certUploader([])->storeKind())
        ->toBe('oci:u:e3b0c44298fc1c149afbf4c8');
    expect($deployer->certUploaderForJob(['compartment_ocid' => $compartment], oracleCreds($privateKey))->storeKind())
        ->toBe('oci:a:'.substr(hash('sha256', "ap-tokyo-1\0".$compartment), 0, 24));
});

test('未准备的 Oracle uploader 只能返回元信息 namespace 且实际上传失败关闭', function () {
    $uploader = (new OraclecloudCertificatesMgmtDeployer)
        ->certUploader(['compartment_ocid' => 'ocid1.compartment.oc1..cccc']);

    expect(fn () => $uploader->upload('LEAF', 'KEY', 'CHAIN', []))
        ->toThrow(RuntimeException::class, 'Oracle Cloud 调用失败: RuntimeException');
});

test('keyId = tenancy/user/fingerprint', function () {
    [$priv] = ociTestRsaKeypair();
    $signer = new OciRequestSigner('TEN', 'USR', 'FP', $priv);
    expect($signer->keyId())->toBe('TEN/USR/FP');
});

test('OCI 签名 KAT（含 body POST）：签名串/头集/Authorization 结构正确且可被公钥验签', function () {
    [$priv, $pub] = ociTestRsaKeypair();
    $signer = new OciRequestSigner('ocid.ten', 'ocid.usr', 'fp:01', $priv);

    $host = 'certificatesmanagement.ap-tokyo-1.oci.oraclecloud.com';
    $target = '/20210224/certificates';
    $body = '{"name":"x"}';
    $date = 'Mon, 01 Jan 2024 00:00:00 GMT';

    $headers = $signer->sign('POST', $host, $target, $body, $date);

    // 含 body 请求必须带 x-content-sha256 / content-type / content-length
    expect($headers['x-content-sha256'])->toBe(base64_encode(hash('sha256', $body, true)));
    expect($headers['content-type'])->toBe('application/json');
    expect($headers['content-length'])->toBe((string) strlen($body));
    expect($headers['host'])->toBe($host);
    expect($headers['date'])->toBe($date);

    // 解析 Authorization 头
    $auth = $headers['Authorization'];
    expect($auth)->toStartWith('Signature version="1"');
    expect($auth)->toContain('keyId="ocid.ten/ocid.usr/fp:01"');
    expect($auth)->toContain('algorithm="rsa-sha256"');
    expect($auth)->toContain('headers="(request-target) date host x-content-sha256 content-type content-length"');

    // 抽出 signature，重建签名串，用公钥验签
    preg_match('/signature="([^"]+)"/', $auth, $m);
    $sig = base64_decode($m[1]);
    $expectedSigningString = implode("\n", [
        '(request-target): post /20210224/certificates',
        'date: '.$date,
        'host: '.$host,
        'x-content-sha256: '.base64_encode(hash('sha256', $body, true)),
        'content-type: application/json',
        'content-length: '.strlen($body),
    ]);
    expect(openssl_verify($expectedSigningString, $sig, $pub, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('OCI 签名 KAT（无 body GET）：只签 (request-target) date host', function () {
    [$priv, $pub] = ociTestRsaKeypair();
    $signer = new OciRequestSigner('t', 'u', 'f', $priv);

    $host = 'certificatesmanagement.us-ashburn-1.oci.oraclecloud.com';
    $target = '/20210224/certificates?compartmentId=ocid.comp';
    $date = 'Tue, 02 Jan 2024 12:00:00 GMT';
    $headers = $signer->sign('GET', $host, $target, '', $date);

    expect($headers)->not->toHaveKey('x-content-sha256');
    expect($headers['Authorization'])->toContain('headers="(request-target) date host"');

    preg_match('/signature="([^"]+)"/', $headers['Authorization'], $m);
    $expectedSigningString = implode("\n", [
        '(request-target): get '.$target,
        'date: '.$date,
        'host: '.$host,
    ]);
    expect(openssl_verify($expectedSigningString, base64_decode($m[1]), $pub, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('私钥无效时签名抛 OraclecloudApiException', function () {
    $signer = new OciRequestSigner('t', 'u', 'f', 'not-a-key');
    expect(fn () => $signer->sign('GET', 'host', '/x'))
        ->toThrow(OraclecloudApiException::class, '私钥');
});

test('uploader.upload 走签名器 + createImportedCertificate（拆 leaf/chain/key）返回 OCID', function () {
    [$priv] = ociTestRsaKeypair();

    $captured = null;
    $regionSeen = null;
    $client = Mockery::mock(OraclecloudClient::class);
    $client->shouldReceive('createImportedCertificate')->once()->andReturnUsing(function (string $compartment, string $name, string $serverCert, string $chain, string $key) use (&$captured) {
        $captured = compact('compartment', 'name', 'serverCert', 'chain', 'key');

        return 'ocid1.certificate.oc1..xxxx';
    });

    $deployer = oracleDeployerWith(function (string $kind, array $cred, $sgn, string $region) use ($client, &$regionSeen) {
        if ($kind === 'provider') {
            return new OracleApiKeyCredentialProvider($cred);
        }
        $regionSeen = $region;

        return $client;
    });

    $ocid = $deployer->certUploaderForJob(['compartment_ocid' => 'ocid1.compartment.oc1..cccc'], oracleCreds($priv))
        ->upload('LEAFPEM', 'KEYPEM', 'CHAINPEM', oracleCreds($priv));

    expect($ocid)->toBe('ocid1.certificate.oc1..xxxx');
    expect($regionSeen)->toBe('ap-tokyo-1');
    expect($captured['compartment'])->toBe('ocid1.compartment.oc1..cccc');
    expect($captured['name'])->toStartWith('clouddeploy-');
    expect($captured['serverCert'])->toContain('LEAFPEM');
    expect($captured['chain'])->toBe('CHAINPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('缺 region 抛明确异常', function () {
    [$priv] = ociTestRsaKeypair();
    $deployer = oracleDeployerWith(fn (string $kind, array $cred) => $kind === 'provider' ? new OracleApiKeyCredentialProvider($cred) : new stdClass);

    $creds = oracleCreds($priv);
    unset($creds['region']);
    expect(fn () => $deployer->certUploaderForJob(['compartment_ocid' => 'c'], $creds)->upload('C', 'K', 'CH', $creds))
        ->toThrow(RuntimeException::class, '缺少区域');
});

test('bind 为 no-op：导入已由 RemoteCertStore 完成，不抛异常', function () {
    $deployer = oracleDeployerWith(fn () => new stdClass);
    $deployer->bind('ocid1.certificate.oc1..x', [], []);
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('upload 遇 OraclecloudApiException 脱敏重抛（无私钥、不挂 previous）', function () {
    [$priv] = ociTestRsaKeypair();
    $client = Mockery::mock(OraclecloudClient::class);
    $client->shouldReceive('createImportedCertificate')->andThrow(new OraclecloudApiException('NotAuthorizedOrNotFound', 'Authorization failed'));

    $deployer = oracleDeployerWith(fn (string $kind, array $cred) => $kind === 'provider' ? new OracleApiKeyCredentialProvider($cred) : $client);
    try {
        $deployer->certUploaderForJob(['compartment_ocid' => 'c'], oracleCreds($priv))->upload('C', 'K', 'CH', oracleCreds($priv));
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NotAuthorizedOrNotFound')->toContain('Authorization failed');
        expect($e->getMessage())->not->toContain('PRIVATE KEY');
        expect($e->getPrevious())->toBeNull();
    }
});

test('region 含 URL 分隔符时即使目标解析为公网也被拒绝', function () {
    $deployer = new OraclecloudCertificatesMgmtDeployer;
    $method = (new ReflectionClass(OraclecloudCertificatesMgmtDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);
    $signer = new OciRequestSigner('', '', '', '', '');

    expect(fn () => $method->invoke($deployer, 'api', [], $signer, 'public.example:443/path'))
        ->toThrow(OutboundDestinationException::class);
});

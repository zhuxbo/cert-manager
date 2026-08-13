<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcDcdnDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（3 参带 region）。uploader 经 certUploader 复用 makeClient('certcenter')。 */
function volcDcdnDeployerWith(callable $clientFactory): VolcDcdnDeployer
{
    return new class($clientFactory) extends VolcDcdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = 'cn-beijing'): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcDcdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function volcDcdnCertificate(string $commonName): string
{
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $pem);

    return $pem;
}

test('火山 DCDN：证书服务型（usesRemoteCertStore + storeKind volc_certcenter）', function () {
    $deployer = new VolcDcdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('dcdn');
});

test('uploader.upload 调 ImportCertificate 返回 InstanceId（CertificateChain 完整链）', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')
        ->once()
        ->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
            $args = compact('action', 'version', 'body');

            return ['InstanceId' => 'cert-inst-1'];
        });

    $deployer = volcDcdnDeployerWith(fn (string $kind) => $kind === 'certcenter' ? $client : new stdClass);
    $id = $deployer->certUploader(['region' => 'cn-beijing'])->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', volcDcdnCreds());

    expect($id)->toBe('cert-inst-1');
    expect($args['action'])->toBe('ImportCertificate');
    expect($args['version'])->toBe('2024-10-01');
    expect($args['body']['CertificateInfo']['CertificateChain'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['body']['CertificateInfo']['PrivateKey'])->toBe('KEYPEM');
    expect($args['body']['Repeatable'])->toBeFalse();
});

test('upload 重复证书走 RepeatId（InstanceId 空时回落）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn(['RepeatId' => 'repeat-9']);

    $deployer = volcDcdnDeployerWith(fn () => $client);
    expect($deployer->certUploader()->upload('C', 'K', 'CH', volcDcdnCreds()))->toBe('repeat-9');
});

test('bind 调 CreateCertBind（CertSource=volc、CertId、DomainNames），exact 去前导 *', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')
        ->once()
        ->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
            $args = compact('action', 'version', 'body');

            return [];
        });

    $deployer = volcDcdnDeployerWith(fn (string $kind) => $kind === 'dcdn' ? $client : new stdClass);
    $deployer->bind('cert-inst-1', volcDcdnCreds(), ['domain' => '*.example.com', 'region' => 'cn-beijing']);

    expect($args['action'])->toBe('CreateCertBind');
    expect($args['version'])->toBe('2021-04-01');
    // "*.example.com" → ".example.com"
    expect($args['body'])->toBe(['CertSource' => 'volc', 'CertId' => 'cert-inst-1', 'DomainNames' => ['.example.com']]);
});

test('certsan 按 ProjectName 分页列举非 Stop 域名并批量绑定匹配项', function () {
    $requests = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$requests) {
        $requests[] = compact('action', 'version', 'body');
        if ($action === 'ListDomainConfig') {
            return ['DomainList' => [
                ['Domain' => 'a.example.com', 'Status' => 'Running'],
                ['Domain' => 'b.example.com', 'Status' => 'Running'],
                ['Domain' => 'a.example.com', 'Status' => 'Stop'],
            ]];
        }

        return [];
    });

    $deployer = volcDcdnDeployerWith(fn () => $client);
    $deployer->bind(['remote_cert_id' => 'cert-1', 'cert' => volcDcdnCertificate('a.example.com'), 'chain' => ''], volcDcdnCreds() + ['project_name' => 'project-a'], [
        'region' => 'cn-beijing',
        'domain_match_pattern' => 'certsan',
    ]);

    expect($requests[0]['body']['ProjectName'])->toBe(['project-a']);
    expect($requests[1]['body']['DomainNames'])->toBe(['a.example.com']);
});

test('region 透传：bind 用 config.region 构造 dcdn client，缺省回落 cn-beijing', function () {
    $regions = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn([]);

    // 捕获 makeClient 收到的 region
    $deployer = volcDcdnDeployerWith(function (string $kind, array $creds, string $region) use ($client, &$regions) {
        $regions[$kind] = $region;

        return $client;
    });

    $deployer->bind('c', volcDcdnCreds(), ['domain' => 'd.example.com', 'region' => 'cn-shanghai']);
    expect($regions['dcdn'])->toBe('cn-shanghai');

    $regions = [];
    $deployer->bind('c', volcDcdnCreds(), ['domain' => 'd.example.com']);
    expect($regions['dcdn'])->toBe('cn-beijing');
});

test('缺 domain 配置抛业务错误', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->never();
    $deployer = volcDcdnDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcDcdnCreds(), ['region' => 'cn-beijing']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('CertBindFailed', 'bind error'));

    $deployer = volcDcdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com', 'region' => 'cn-beijing']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('CertBindFailed');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

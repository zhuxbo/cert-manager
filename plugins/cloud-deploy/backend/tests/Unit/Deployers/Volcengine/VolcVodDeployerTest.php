<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcVodDeployer;
use Tests\TestCase;

uses(TestCase::class);

function volcVodDeployerWith(callable $clientFactory): VolcVodDeployer
{
    return new class($clientFactory) extends VolcVodDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function volcVodCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function volcVodCertificate(string $commonName): string
{
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $pem);

    return $pem;
}

test('火山 VOD：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcVodDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('vod');
});

test('bind：UpdateVodDomainConfig 设 HTTPS.CertInfo.CertId，domain_type 映射 vod_play', function () {
    $body = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->twice()->andReturnUsing(function (string $action, string $version, array $b) use (&$body) {
        if ($action === 'UpdateVodDomainConfig') {
            $body = compact('action', 'version', 'b');
        }

        return [];
    });

    $deployer = volcVodDeployerWith(fn (string $kind) => $kind === 'vod' ? $client : new stdClass);
    $deployer->bind('cert-9', volcVodCreds(), ['space_name' => 'sp-1', 'domain_type' => 'play', 'domain' => 'vod.example.com']);

    expect($body['action'])->toBe('UpdateVodDomainConfig');
    expect($body['version'])->toBe('2026-01-01');
    expect($body['b']['SpaceName'])->toBe('sp-1');
    expect($body['b']['DomainType'])->toBe('vod_play');
    expect($body['b']['UpdateCdnConfigParam']['Domain'])->toBe('vod.example.com');
    expect($body['b']['UpdateCdnConfigParam']['HTTPS']['Switch'])->toBeTrue();
    expect($body['b']['UpdateCdnConfigParam']['HTTPS']['CertInfo']['CertId'])->toBe('cert-9');
});

test('certsan 列举点播域名并跳过已绑定当前证书的匹配项', function () {
    $updated = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$updated) {
        if ($action === 'ListVodDomains') {
            return ['VodInfo' => ['Domains' => [
                ['Domain' => 'a.example.com'],
                ['Domain' => 'b.example.com'],
            ]]];
        }
        if ($action === 'DescribeVodDomainConfig') {
            $domain = $body['DescribeCdnDomainParam']['Domain'];

            return ['DomainInfo' => ['DomainConfig' => ['HTTPS' => [
                'Switch' => true,
                'CertInfo' => ['CertId' => $domain === 'a.example.com' ? 'cert-1' : 'old'],
            ]]]];
        }
        if ($action === 'UpdateVodDomainConfig') {
            $updated[] = $body['UpdateCdnConfigParam']['Domain'];
        }

        return [];
    });

    $deployer = volcVodDeployerWith(fn () => $client);
    $deployer->bind(['remote_cert_id' => 'cert-1', 'cert' => volcVodCertificate('*.example.com'), 'chain' => ''], volcVodCreds(), [
        'space_name' => 'sp-1',
        'domain_type' => 'play',
        'domain_match_pattern' => 'certsan',
    ]);

    expect($updated)->toBe(['b.example.com']);
});

test('domain_type 映射：image→vod_image、third→third', function () {
    $captured = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $a, string $v, array $b) use (&$captured) {
        if ($a === 'UpdateVodDomainConfig') {
            $captured[] = $b['DomainType'];
        }

        return [];
    });

    $deployer = volcVodDeployerWith(fn () => $client);
    $deployer->bind('c', volcVodCreds(), ['space_name' => 's', 'domain_type' => 'image', 'domain' => 'd.example.com']);
    $deployer->bind('c', volcVodCreds(), ['space_name' => 's', 'domain_type' => 'third', 'domain' => 'd.example.com']);

    expect($captured)->toBe(['vod_image', 'third']);
});

test('缺 space_name / domain_type / domain 抛业务错误', function () {
    $deployer = volcVodDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcVodCreds(), ['domain_type' => 'play', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 space_name');
    expect(fn () => $deployer->bind('c', volcVodCreds(), ['space_name' => 's', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 domain_type');
    expect(fn () => $deployer->bind('c', volcVodCreds(), ['space_name' => 's', 'domain_type' => 'play']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('VodErr', 'update failed'));

    $deployer = volcVodDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['space_name' => 's', 'domain_type' => 'play', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('VodErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

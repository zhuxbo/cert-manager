<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\CdnDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

function hwCdnDeployerWith(callable $clientFactory): CdnDeployer
{
    return new class($clientFactory) extends CdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function hwCdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('华为云 CDN：证书服务型 + storeKind huawei_scm + 元信息 + schema', function () {
    $deployer = new CdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('huawei_scm');
    expect($deployer->provider())->toBe('huaweicloud');
    expect($deployer->product())->toBe('cdn');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region')->toContain('domain_match_pattern')->toContain('domain');
});

test('bind wildcard：ListDomains 过滤不可用状态后批量绑定单层子域', function () {
    $getCaptured = null;
    $putCaptured = null;
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query = []) use (&$getCaptured) {
        $getCaptured = compact('path', 'query');

        return ['domains' => [
            ['domain_name' => 'a.example.com', 'domain_status' => 'online'],
            ['domain_name' => 'b.example.com', 'domain_status' => 'online'],
            ['domain_name' => 'deep.a.example.com', 'domain_status' => 'online'],
            ['domain_name' => 'off.example.com', 'domain_status' => 'offline'],
        ]];
    });
    $client->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body, array $query = []) use (&$putCaptured) {
        $putCaptured = compact('path', 'body', 'query');

        return [];
    });

    $deployer = hwCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $deployer->bind('scm-1', ['access_key_id' => 'AK', 'secret_access_key' => 'SK', 'enterprise_project_id' => 'ep-1'], [
        'domain_match_pattern' => 'wildcard',
        'domain' => '*.example.com',
    ]);

    expect($getCaptured['path'])->toBe('/v1.0/cdn/domains');
    expect($getCaptured['query'])->toMatchArray(['enterprise_project_id' => 'ep-1', 'page_number' => 1, 'page_size' => 100]);
    expect($putCaptured['body']['https']['domain_name'])->toBe('a.example.com,b.example.com');
    expect($putCaptured['query'])->toBe(['enterprise_project_id' => 'ep-1']);
});

test('uploader 走 SCM import（与 SCM 端点同上传器）', function () {
    $captured = null;
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andReturnUsing(function (string $path, ?array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['certificate_id' => 'scm-cdn-1'];
    });

    $deployer = hwCdnDeployerWith(fn (string $kind) => $kind === 'scm' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', hwCdnCreds());

    expect($id)->toBe('scm-cdn-1');
    expect($captured['path'])->toBe('/v3/scm/certificates/import');
});

test('bind：UpdateDomainMultiCertificates 绑定 SCM 证书（scm_certificate_id = 上传 id）', function () {
    $captured = null;
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $path, ?array $body, array $query = []) use (&$captured) {
            $captured = compact('path', 'body', 'query');

            return [];
        });

    $deployer = hwCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $deployer->bind('scm-dn-99', ['access_key_id' => 'AK', 'secret_access_key' => 'SK', 'enterprise_project_id' => 'ep-1'], ['domain' => 'cdn.example.com']);

    expect($captured['path'])->toBe('/v1.0/cdn/domains/config-https-info');
    $https = $captured['body']['https'];
    expect($https['domain_name'])->toBe('cdn.example.com');
    expect($https['https_switch'])->toBe(1);
    expect($https['certificate_type'])->toBe(2);
    expect($https['scm_certificate_id'])->toBe('scm-dn-99');
    expect($https['cert_name'])->toStartWith('clouddeploy_');
    // 企业项目作查询参数透传
    expect($captured['query']['enterprise_project_id'])->toBe('ep-1');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = hwCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('scm-1', hwCdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('put')->andThrow(new HuaweicloudApiException('CDN.0001', 'domain not found'));

    $deployer = hwCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('scm-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('CDN.0001')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Plugins\CloudDeploy\Deployers\Huaweicloud\VodDeployer;
use Tests\TestCase;

uses(TestCase::class);

function hwVodDeployerWith(callable $clientFactory): VodDeployer
{
    return new class($clientFactory) extends VodDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $projectId);
        }
    };
}

function hwVodCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwVodConfig(): array
{
    return ['region' => 'cn-north-4', 'domain_match_pattern' => 'exact', 'domain' => 'vod.example.com'];
}

test('华为云 VOD 复用 SCM 远端证书库并暴露 exact 配置', function () {
    $deployer = new VodDeployer;

    expect($deployer->provider())->toBe('huaweicloud')
        ->and($deployer->product())->toBe('vod')
        ->and($deployer->usesRemoteCertStore())->toBeTrue()
        ->and($deployer->certUploader()?->storeKind())->toBe('huawei_scm')
        ->and(array_column($deployer->configSchema(), 'key'))
        ->toContain('region', 'domain_match_pattern', 'domain');
});

test('VOD 先查询 HTTPS 配置再更新并保留 http2 与强制跳转', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->once()->with('/v3/projects', ['name' => 'cn-north-4'])
        ->andReturn(['projects' => [['id' => 'project-vod']]]);

    $captured = null;
    $vod = Mockery::mock(HuaweicloudRestClient::class);
    $vod->shouldReceive('get')->once()
        ->with('/v1.0/project-vod/asset/domain/https', ['domain' => 'vod.example.com'])
        ->andReturn(['cert_id' => 'old-cert', 'http2' => 1, 'force_redirect_https' => 0]);
    $vod->shouldReceive('put')->once()->andReturnUsing(function (string $path, ?array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return [];
    });

    $region = null;
    $projectId = null;
    $deployer = hwVodDeployerWith(function (string $kind, array $credentials, string $clientRegion, string $clientProjectId) use ($iam, $vod, &$region, &$projectId) {
        if ($kind === 'iam') {
            return $iam;
        }
        $region = $clientRegion;
        $projectId = $clientProjectId;

        return $vod;
    });

    $deployer->bind('new-cert', hwVodCreds(), hwVodConfig());

    expect($captured['path'])->toBe('/v1.0/project-vod/asset/domain/https')
        ->and($captured['body'])->toMatchArray([
            'domain' => 'vod.example.com',
            'source' => 'scm',
            'cert_id' => 'new-cert',
            'https_status' => 1,
            'http2' => 1,
            'force_redirect_https' => 0,
        ])
        ->and($region)->toBe('cn-north-4')
        ->and($projectId)->toBe('project-vod');
});

test('VOD 已绑定相同证书时不重复更新', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => [['id' => 'project-vod']]]);
    $vod = Mockery::mock(HuaweicloudRestClient::class);
    $vod->shouldReceive('get')->once()->andReturn(['cert_id' => 'same-cert']);
    $vod->shouldReceive('put')->never();

    hwVodDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $vod)
        ->bind('same-cert', hwVodCreds(), hwVodConfig());
});

test('VOD 拒绝 Certimate 不支持的非 exact 匹配模式', function () {
    $deployer = hwVodDeployerWith(fn () => new stdClass);

    expect(fn () => $deployer->bind('cert', hwVodCreds(), [
        'region' => 'cn-north-4',
        'domain_match_pattern' => 'wildcard',
        'domain' => '*.example.com',
    ]))->toThrow(RuntimeException::class, '不支持的域名匹配模式');
});

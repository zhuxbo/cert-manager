<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Plugins\CloudDeploy\Deployers\Huaweicloud\LiveDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * makeClient 4 参（kind/credentials/region/projectId）。按 $kind 路由 mock：iam（反查 projectId）/ live（绑定）/ scm（上传）。
 */
function hwLiveDeployerWith(callable $clientFactory): LiveDeployer
{
    return new class($clientFactory) extends LiveDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $projectId);
        }
    };
}

function hwLiveCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwLiveConfig(): array
{
    return ['region' => 'cn-north-4', 'domain' => 'live.example.com'];
}

test('华为云 Live：证书服务型 + storeKind huawei_scm + schema(region+domain)', function () {
    $deployer = new LiveDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('huawei_scm');
    expect($deployer->product())->toBe('live');
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('domain');
});

test('bind：IAM 反查 projectId 后 UpdateDomainHttpsCert（source=scm + cert_id + domain 查询）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')
        ->once()
        ->with('/v3/projects', ['name' => 'cn-north-4'])
        ->andReturn(['projects' => [['id' => 'proj-live-1']]]);

    $captured = null;
    $capturedRegion = null;
    $capturedProject = null;
    $live = Mockery::mock(HuaweicloudRestClient::class);
    $live->shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $path, ?array $body, array $query = []) use (&$captured) {
            $captured = compact('path', 'body', 'query');

            return [];
        });

    $deployer = hwLiveDeployerWith(function (string $kind, array $creds, string $region, string $projectId) use ($iam, $live, &$capturedRegion, &$capturedProject) {
        if ($kind === 'iam') {
            return $iam;
        }
        // live：捕获 region/projectId 注入
        $capturedRegion = $region;
        $capturedProject = $projectId;

        return $live;
    });

    $deployer->bind('scm-live-9', hwLiveCreds(), hwLiveConfig());

    expect($captured['path'])->toBe('/v1/proj-live-1/guard/https-cert');
    expect($captured['body']['tls_certificate']['source'])->toBe('scm');
    expect($captured['body']['tls_certificate']['cert_id'])->toBe('scm-live-9');
    expect($captured['query']['domain'])->toBe('live.example.com');
    expect($capturedRegion)->toBe('cn-north-4');
    expect($capturedProject)->toBe('proj-live-1');
});

test('IAM 未返回 project 时业务失败（ProjectNotFound）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => []]);

    $deployer = hwLiveDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : new stdClass);

    expect(fn () => $deployer->bind('scm-1', hwLiveCreds(), hwLiveConfig()))
        ->toThrow(RuntimeException::class, 'ProjectNotFound');
});

test('缺 region 配置抛业务错误', function () {
    $deployer = hwLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('scm-1', hwLiveCreds(), ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = hwLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('scm-1', hwLiveCreds(), ['region' => 'cn-north-4']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（无 AK/SK、不挂 previous）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => [['id' => 'p-1']]]);
    $live = Mockery::mock(HuaweicloudRestClient::class);
    $live->shouldReceive('put')->andThrow(new HuaweicloudApiException('LIVE.0001', 'domain invalid'));

    $deployer = hwLiveDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $live);

    try {
        $deployer->bind('scm-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], hwLiveConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('LIVE.0001');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

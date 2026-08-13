<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Plugins\CloudDeploy\Deployers\Huaweicloud\WafDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** makeClient 4 参（kind/credentials/region/projectId）。iam（反查 projectId）/ waf（建证书 + 查域名 + 绑定）。 */
function hwWafDeployerWith(callable $clientFactory): WafDeployer
{
    return new class($clientFactory) extends WafDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $projectId);
        }
    };
}

function hwWafCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwWafConfig(): array
{
    return ['region' => 'cn-north-4', 'domain' => 'waf.example.com'];
}

test('华为云 WAF：证书服务型 + region 维度 storeKind + schema 部署目标', function () {
    $deployer = new WafDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-north-4'])->storeKind())->toBe('huawei_waf:cn-north-4');
    expect($deployer->product())->toBe('waf');
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('deploy_target')->toContain('domain')->toContain('certificate_id');
});

test('bind premiumhost：ListPremiumHost 后 UpdatePremiumHost 绑定证书', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);
    $putCaptured = null;
    $waf = Mockery::mock(HuaweicloudRestClient::class);
    $waf->shouldReceive('get')->with('/v1/proj-1/waf/certificate/waf-cert-99', [])->andReturn(['name' => 'cert-name-x']);
    $waf->shouldReceive('get')->with('/v1/proj-1/premium-waf/host', Mockery::on(fn ($q) => ($q['hostname'] ?? '') === 'waf.example.com'))
        ->andReturn(['items' => [['id' => 'premium-1', 'hostname' => 'waf.example.com']]]);
    $waf->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body, array $query = []) use (&$putCaptured) {
        $putCaptured = compact('path', 'body');

        return [];
    });

    $deployer = hwWafDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $waf);
    $deployer->bind('waf-cert-99', hwWafCreds(), array_replace(hwWafConfig(), ['deploy_target' => 'premiumhost']));

    expect($putCaptured['path'])->toBe('/v1/proj-1/premium-waf/host/premium-1');
    expect($putCaptured['body'])->toMatchArray(['certificateid' => 'waf-cert-99', 'certificatename' => 'cert-name-x']);
});

test('certificate 目标：uploader 保留证书名并原地 UpdateCertificate，bind no-op', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);
    $captured = null;
    $waf = Mockery::mock(HuaweicloudRestClient::class);
    $waf->shouldReceive('get')->with('/v1/proj-1/waf/certificate/old-cert', [])->andReturn(['name' => 'existing-name']);
    $waf->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body, array $query = []) use (&$captured) {
        $captured = compact('path', 'body', 'query');

        return ['id' => 'old-cert'];
    });

    $deployer = hwWafDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $waf);
    $config = ['region' => 'cn-north-4', 'deploy_target' => 'certificate', 'certificate_id' => 'old-cert'];
    $uploader = $deployer->certUploader($config);
    expect($uploader->storeKind())->toStartWith('huawei-waf-r:');
    expect($uploader->upload('CERT', 'KEY', 'CHAIN', hwWafCreds()))->toBe('old-cert');
    expect($captured['path'])->toBe('/v1/proj-1/waf/certificate/old-cert');
    expect($captured['body'])->toBe(['name' => 'existing-name', 'content' => "CERT\nCHAIN", 'key' => 'KEY']);
    $deployer->bind('old-cert', hwWafCreds(), $config);
});

test('uploader.upload：IAM 反查 projectId 后 WAF CreateCertificate（字段 content/key）返回 id', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-waf-1']]]);

    $captured = null;
    $waf = Mockery::mock(HuaweicloudRestClient::class);
    $waf->shouldReceive('post')->once()->andReturnUsing(function (string $path, ?array $body, array $query = []) use (&$captured) {
        $captured = compact('path', 'body', 'query');

        return ['id' => 'waf-cert-1'];
    });

    $deployer = hwWafDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $waf);
    $id = $deployer->certUploader(['region' => 'cn-north-4'])->upload('CERT', 'KEY', 'CHAIN', hwWafCreds());

    expect($id)->toBe('waf-cert-1');
    expect($captured['path'])->toBe('/v1/proj-waf-1/waf/certificate');
    // WAF 字段名 content / key（非 certificate/private_key）
    expect($captured['body']['content'])->toContain('CERT')->toContain('CHAIN');
    expect($captured['body']['key'])->toBe('KEY');
});

test('bind：ShowCertificate 取名、ListHost exact 匹配、UpdateHost 绑定（certificateid+certificatename）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);

    $putCaptured = null;
    $waf = Mockery::mock(HuaweicloudRestClient::class);
    // ShowCertificate
    $waf->shouldReceive('get')->with('/v1/proj-1/waf/certificate/waf-cert-99', [])->andReturn(['name' => 'cert-name-x']);
    // ListHost（exact 命中）
    $waf->shouldReceive('get')->with('/v1/proj-1/waf/instance', Mockery::on(fn ($q) => ($q['hostname'] ?? '') === 'waf.example.com'))
        ->andReturn(['items' => [['id' => 'host-1', 'hostname' => 'waf.example.com']]]);
    // UpdateHost
    $waf->shouldReceive('put')->once()->andReturnUsing(function (string $path, ?array $body, array $query = []) use (&$putCaptured) {
        $putCaptured = compact('path', 'body');

        return [];
    });

    $deployer = hwWafDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $waf);
    $deployer->bind('waf-cert-99', hwWafCreds(), hwWafConfig());

    expect($putCaptured['path'])->toBe('/v1/proj-1/waf/instance/host-1');
    expect($putCaptured['body']['certificateid'])->toBe('waf-cert-99');
    expect($putCaptured['body']['certificatename'])->toBe('cert-name-x');
});

test('bind：ListHost 找不到域名 → 业务失败（HostNotFound）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);

    $waf = Mockery::mock(HuaweicloudRestClient::class);
    $waf->shouldReceive('get')->with('/v1/proj-1/waf/certificate/waf-1', [])->andReturn(['name' => 'n']);
    $waf->shouldReceive('get')->with('/v1/proj-1/waf/instance', Mockery::any())->andReturn(['items' => []]);

    $deployer = hwWafDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $waf);

    expect(fn () => $deployer->bind('waf-1', hwWafCreds(), hwWafConfig()))
        ->toThrow(RuntimeException::class, 'HostNotFound');
});

test('缺 region / domain 配置抛业务错误', function () {
    $deployer = hwWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('waf-1', hwWafCreds(), ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('waf-1', hwWafCreds(), ['region' => 'cn-north-4']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（无 AK/SK、不挂 previous）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => [['id' => 'p-1']]]);
    $waf = Mockery::mock(HuaweicloudRestClient::class);
    $waf->shouldReceive('get')->with('/v1/p-1/waf/certificate/waf-1', [])->andReturn(['name' => 'n']);
    $waf->shouldReceive('get')->with('/v1/p-1/waf/instance', Mockery::any())->andReturn(['items' => [['id' => 'h-1', 'hostname' => 'waf.example.com']]]);
    $waf->shouldReceive('put')->andThrow(new HuaweicloudApiException('WAF.0001', 'host invalid'));

    $deployer = hwWafDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $waf);

    try {
        $deployer->bind('waf-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], hwWafConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('WAF.0001');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

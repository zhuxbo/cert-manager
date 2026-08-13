<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\ElbDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

// regionalHost 内做 host 校验：stub 策略放行公网 host，注入场景由授权测试覆盖
beforeEach(function () {
    app()->instance(OutboundDestinationPolicy::class, new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
    ));
});

/** makeClient 4 参（kind/credentials/region/projectId）。iam（反查 projectId）/ elb（建证书 + 绑定）。 */
function hwElbDeployerWith(callable $clientFactory): ElbDeployer
{
    return new class($clientFactory) extends ElbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $projectId);
        }
    };
}

function hwElbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwElbConfig(): array
{
    return ['region' => 'cn-north-4', 'listener_id' => 'listener-1'];
}

test('华为云 ELB：证书服务型 + region 维度 storeKind + 三类部署目标 schema', function () {
    $deployer = new ElbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-north-4'])->storeKind())->toBe('huawei_elb:cn-north-4');
    expect($deployer->product())->toBe('elb');
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('deploy_target')->toContain('load_balancer_id')->toContain('listener_id')->toContain('certificate_id');
});

test('bind loadbalancer：ShowLoadBalancer 后列 HTTPS 监听并批量更新', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);
    $updated = [];
    $elb = Mockery::mock(HuaweicloudRestClient::class);
    $elb->shouldReceive('get')->with('/v3/proj-1/elb/loadbalancers/lb-1', [])->andReturn(['loadbalancer' => ['id' => 'lb-1']]);
    $elb->shouldReceive('get')->with('/v3/proj-1/elb/listeners', Mockery::on(fn (array $q) => ($q['loadbalancer_id'] ?? '') === 'lb-1' && ($q['protocol'] ?? '') === 'HTTPS,TERMINATED_HTTPS'))
        ->andReturn(['listeners' => [['id' => 'l-1'], ['id' => 'l-2']], 'page_info' => []]);
    $elb->shouldReceive('get')->twice()->with(Mockery::pattern('#/elb/listeners/l-[12]#'), [])->andReturn(['listener' => []]);
    $elb->shouldReceive('put')->twice()->andReturnUsing(function (string $path, array $body) use (&$updated) {
        $updated[] = [$path, $body['listener']['default_tls_container_ref']];

        return [];
    });

    $deployer = hwElbDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $elb);
    $deployer->bind('elb-cert-99', hwElbCreds(), [
        'region' => 'cn-north-4', 'deploy_target' => 'loadbalancer', 'load_balancer_id' => 'lb-1',
    ]);

    expect($updated)->toBe([
        ['/v3/proj-1/elb/listeners/l-1', 'elb-cert-99'],
        ['/v3/proj-1/elb/listeners/l-2', 'elb-cert-99'],
    ]);
});

test('certificate 目标：uploader 原地 UpdateCertificate，bind 不重复绑定', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);
    $captured = null;
    $elb = Mockery::mock(HuaweicloudRestClient::class);
    $elb->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['certificate' => ['id' => 'old-cert']];
    });

    $deployer = hwElbDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $elb);
    $uploader = $deployer->certUploader([
        'region' => 'cn-north-4', 'deploy_target' => 'certificate', 'certificate_id' => 'old-cert',
    ]);
    expect($uploader->storeKind())->toStartWith('huawei-elb-r:');
    expect($uploader->upload('CERT', 'KEY', 'CHAIN', hwElbCreds()))->toBe('old-cert');
    expect($captured['path'])->toBe('/v3/proj-1/elb/certificates/old-cert');
    expect($captured['body']['certificate'])->toMatchArray([
        'certificate' => "CERT\nCHAIN", 'private_key' => 'KEY',
    ]);
    $deployer->bind('old-cert', hwElbCreds(), [
        'region' => 'cn-north-4', 'deploy_target' => 'certificate', 'certificate_id' => 'old-cert',
    ]);
});

test('uploader.upload：IAM 反查 projectId 后 ELB CreateCertificate（type=server）返回 certificate.id', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')
        ->once()
        ->with('/v3/projects', ['name' => 'cn-north-4'])
        ->andReturn(['projects' => [['id' => 'proj-elb-1']]]);

    $captured = null;
    $capturedProject = null;
    $elb = Mockery::mock(HuaweicloudRestClient::class);
    $elb->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, ?array $body, array $query = []) use (&$captured) {
            $captured = compact('path', 'body');

            return ['certificate' => ['id' => 'elb-cert-1', 'name' => 'n']];
        });

    $deployer = hwElbDeployerWith(function (string $kind, array $creds, string $region, string $projectId) use ($iam, $elb, &$capturedProject) {
        if ($kind === 'iam') {
            return $iam;
        }
        $capturedProject = $projectId;

        return $elb;
    });

    $id = $deployer->certUploader(['region' => 'cn-north-4'])->upload('CERT', 'KEY', 'CHAIN', hwElbCreds());

    expect($id)->toBe('elb-cert-1');
    expect($captured['path'])->toBe('/v3/proj-elb-1/elb/certificates');
    $cert = $captured['body']['certificate'];
    expect($cert['certificate'])->toContain('CERT')->toContain('CHAIN');
    expect($cert['private_key'])->toBe('KEY');
    expect($cert['type'])->toBe('server');
    expect($capturedProject)->toBe('proj-elb-1');
});

test('bind：IAM 反查 projectId、ShowListener 后 UpdateListener（default_tls_container_ref）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);

    $getPath = null;
    $putCaptured = null;
    $elb = Mockery::mock(HuaweicloudRestClient::class);
    $elb->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $q = []) use (&$getPath) {
        $getPath = $path;

        return ['listener' => ['id' => 'listener-1']];
    });
    $elb->shouldReceive('put')->once()->andReturnUsing(function (string $path, ?array $body, array $q = []) use (&$putCaptured) {
        $putCaptured = compact('path', 'body');

        return [];
    });

    $deployer = hwElbDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $elb);
    $deployer->bind('elb-cert-99', hwElbCreds(), hwElbConfig());

    expect($getPath)->toBe('/v3/proj-1/elb/listeners/listener-1');
    expect($putCaptured['path'])->toBe('/v3/proj-1/elb/listeners/listener-1');
    expect($putCaptured['body']['listener']['default_tls_container_ref'])->toBe('elb-cert-99');
});

test('bind SNI：以新证书替换同 SAN 的旧引用并保留其他 SNI 与匹配算法', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);
    $captured = null;
    $elb = Mockery::mock(HuaweicloudRestClient::class);
    $elb->shouldReceive('get')->with('/v3/proj-1/elb/listeners/listener-1', [])->andReturn(['listener' => [
        'sni_container_refs' => ['old-same', 'old-other'],
        'sni_match_algo' => 'wildcard',
    ]]);
    $elb->shouldReceive('get')->with('/v3/proj-1/elb/certificates', ['id' => 'old-same,old-other'])->andReturn(['certificates' => [
        ['id' => 'old-same', 'subject_alternative_names' => ['api.example.com', '*.example.com']],
        ['id' => 'old-other', 'subject_alternative_names' => ['other.example.com']],
    ]]);
    $elb->shouldReceive('get')->with('/v3/proj-1/elb/certificates/new-cert', [])->andReturn(['certificate' => [
        'id' => 'new-cert', 'subject_alternative_names' => ['api.example.com', '*.example.com'],
    ]]);
    $elb->shouldReceive('put')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return [];
    });

    hwElbDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $elb)
        ->bind('new-cert', hwElbCreds(), hwElbConfig());

    expect($captured['body']['listener']['default_tls_container_ref'])->toBe('new-cert');
    expect($captured['body']['listener']['sni_container_refs'])->toBe(['new-cert', 'old-other']);
    expect($captured['body']['listener']['sni_match_algo'])->toBe('wildcard');
});

test('缺 region / listener_id 配置抛业务错误', function () {
    $deployer = hwElbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('elb-1', hwElbCreds(), ['listener_id' => 'l-1']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('elb-1', hwElbCreds(), ['region' => 'cn-north-4']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（无 AK/SK、不挂 previous）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => [['id' => 'p-1']]]);
    $elb = Mockery::mock(HuaweicloudRestClient::class);
    $elb->shouldReceive('get')->andReturn(['listener' => ['id' => 'l-1']]);
    $elb->shouldReceive('put')->andThrow(new HuaweicloudApiException('ELB.0001', 'listener invalid'));

    $deployer = hwElbDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $elb);

    try {
        $deployer->bind('elb-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], hwElbConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ELB.0001');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('uploader 缺 region（探活态）upload 时抛明确异常', function () {
    $deployer = new ElbDeployer;
    // 空 config → uploader region 为空，storeKind 回落 default 不抛；但真正 upload 时报缺 region
    $uploader = $deployer->certUploader([]);
    expect($uploader->storeKind())->toBe('huawei_elb:default');
    expect(fn () => $uploader->upload('C', 'K', 'CH', hwElbCreds()))
        ->toThrow(RuntimeException::class, 'region');
});

test('region 含 URL 分隔符时即使目标解析为公网也被拒绝（regionalHost 共性校验）', function () {
    $deployer = new ElbDeployer;
    $method = (new ReflectionClass(ElbDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 'elb', hwElbCreds(), 'public.example:443/path', 'project-1'))
        ->toThrow(OutboundDestinationException::class);
});

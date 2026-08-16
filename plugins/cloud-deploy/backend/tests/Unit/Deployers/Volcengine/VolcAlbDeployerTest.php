<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcAlbDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（3 参带 region）。alb/certcenter 两 kind。 */
function volcAlbDeployerWith(callable $clientFactory): VolcAlbDeployer
{
    return new class($clientFactory) extends VolcAlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcAlbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK', 'project_name' => 'project-a'];
}

test('火山 ALB：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcAlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-beijing'])->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('alb');
});

test('listener 目标无 SNI：ModifyListenerAttributes 设 cert_center + CertCenterCertificateId', function () {
    $calls = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturnUsing(function (string $action, string $version, array $params) use (&$calls) {
        $calls[] = compact('action', 'params');

        return [];
    });

    $deployer = volcAlbDeployerWith(fn (string $kind) => $kind === 'alb' ? $client : new stdClass);
    $deployer->bind('cert-9', volcAlbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1']);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['action'])->toBe('ModifyListenerAttributes');
    expect($calls[0]['params'])->toBe([
        'ListenerId' => 'lsn-1',
        'CertificateSource' => 'cert_center',
        'CertCenterCertificateId' => 'cert-9',
    ]);
});

test('listener 目标 + SNI：DescribeListenerAttributes 取扩展域名 → 仅换匹配 Domain 的扩展证书', function () {
    $modifyParams = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturnUsing(function (string $action, string $version, array $params) use (&$modifyParams) {
        if ($action === 'DescribeListenerAttributes') {
            return ['DomainExtensions' => [
                ['DomainExtensionId' => 'de-1', 'Domain' => 'a.example.com'],
                ['DomainExtensionId' => 'de-2', 'Domain' => 'sni.example.com'],
            ]];
        }
        if ($action === 'ModifyListenerAttributes') {
            $modifyParams = $params;
        }

        return [];
    });

    $deployer = volcAlbDeployerWith(fn () => $client);
    $deployer->bind('cert-NEW', volcAlbCreds(), [
        'region' => 'cn-beijing', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1', 'domain' => 'sni.example.com',
    ]);

    // 仅 sni.example.com（de-2）被改成新证书，a.example.com 不动
    expect($modifyParams)->toBe([
        'ListenerId' => 'lsn-1',
        'DomainExtensions' => [[
            'DomainExtensionId' => 'de-2',
            'Domain' => 'sni.example.com',
            'CertificateSource' => 'cert_center',
            'CertCenterCertificateId' => 'cert-NEW',
            'Action' => 'modify',
        ]],
    ]);
});

test('SNI 未找到匹配扩展域名抛业务错误', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturnUsing(function (string $action) {
        return $action === 'DescribeListenerAttributes' ? ['DomainExtensions' => []] : [];
    });

    $deployer = volcAlbDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcAlbCreds(), [
        'region' => 'cn-beijing', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1', 'domain' => 'nomatch.example.com',
    ]))->toThrow(RuntimeException::class, 'nomatch.example.com');
});

test('loadbalancer 目标：DescribeListeners(HTTPS) 枚举 → 各监听更新', function () {
    $modifies = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturnUsing(function (string $action, string $version, array $params) use (&$modifies) {
        if ($action === 'DescribeLoadBalancerAttributes') {
            expect($params['LoadBalancerId'])->toBe('lb-1');

            return [];
        }
        if ($action === 'DescribeListeners') {
            expect($params['ProjectName'])->toBe('project-a');

            return ['Listeners' => [['ListenerId' => 'lsn-1'], ['ListenerId' => 'lsn-2']]];
        }
        if ($action === 'ModifyListenerAttributes') {
            $modifies[] = $params['ListenerId'];
        }

        return [];
    });

    $deployer = volcAlbDeployerWith(fn () => $client);
    $deployer->bind('cert-9', volcAlbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1']);

    expect($modifies)->toBe(['lsn-1', 'lsn-2']);
});

test('不支持的 deploy_target 抛业务错误', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->never();
    $deployer = volcAlbDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcAlbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'unknown']))
        ->toThrow(RuntimeException::class, 'unknown');
});

test('缺 region / listener_id 抛业务错误', function () {
    $deployer = volcAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcAlbCreds(), ['deploy_target' => 'listener', 'listener_id' => 'x']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', volcAlbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'listener']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andThrow(new VolcApiException('NoListener', 'listener not found'));

    $deployer = volcAlbDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'region' => 'cn-beijing', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NoListener');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

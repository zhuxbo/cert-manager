<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusAlbDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApiException;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

function byteplusAlbDeployerWith(callable $clientFactory): BytePlusAlbDeployer
{
    return new class($clientFactory) extends BytePlusAlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function byteplusAlbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus ALB：证书服务型（storeKind=byteplus_certcenter）', function () {
    $deployer = new BytePlusAlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_certcenter');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('alb');
});

test('listener 目标 + 无 SNI：直接 ModifyListenerAttributes（CertificateSource=cert_center, CertCenterCertificateId）', function () {
    $captured = null;
    $alb = Mockery::mock(BytePlusRestClient::class);
    $alb->shouldReceive('openApi')->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body = null) use (&$captured) {
        $captured = compact('method', 'action', 'query');

        return new stdClass;
    });

    $deployer = byteplusAlbDeployerWith(fn (string $kind) => $kind === 'lb' ? $alb : new stdClass);
    $deployer->bind('cert-NEW', byteplusAlbCreds(), [
        'region' => 'ap-singapore-1', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1',
    ]);

    expect($captured['method'])->toBe('GET');
    expect($captured['action'])->toBe('ModifyListenerAttributes');
    expect($captured['query'])->toBe([
        'ListenerId' => 'lsn-1',
        'CertificateSource' => 'cert_center',
        'CertCenterCertificateId' => 'cert-NEW',
    ]);
});

test('listener 目标 + SNI：DescribeListenerAttributes 取扩展域名，只摊平匹配 Domain 的 DomainExtensions.N.*', function () {
    $modifyQuery = null;
    $alb = Mockery::mock(BytePlusRestClient::class);
    $alb->shouldReceive('openApi')->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body = null) use (&$modifyQuery) {
        if ($action === 'DescribeListenerAttributes') {
            return (object) ['DomainExtensions' => [
                (object) ['DomainExtensionId' => 'de-a', 'Domain' => 'a.example.com'],
                (object) ['DomainExtensionId' => 'de-sni', 'Domain' => 'sni.example.com'],
            ]];
        }
        if ($action === 'ModifyListenerAttributes') {
            $modifyQuery = $query;
        }

        return new stdClass;
    });

    $deployer = byteplusAlbDeployerWith(fn () => $alb);
    $deployer->bind('cert-NEW', byteplusAlbCreds(), [
        'region' => 'ap-singapore-1', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1', 'domain' => 'sni.example.com',
    ]);

    // 只摊平 sni.example.com（de-sni），a.example.com 不动
    expect($modifyQuery['ListenerId'])->toBe('lsn-1');
    expect($modifyQuery['DomainExtensions.1.DomainExtensionId'])->toBe('de-sni');
    expect($modifyQuery['DomainExtensions.1.Domain'])->toBe('sni.example.com');
    expect($modifyQuery['DomainExtensions.1.CertCenterCertificateId'])->toBe('cert-NEW');
    expect($modifyQuery['DomainExtensions.1.CertificateSource'])->toBe('cert_center');
    expect($modifyQuery['DomainExtensions.1.Action'])->toBe('modify');
    expect($modifyQuery)->not->toHaveKey('DomainExtensions.2.DomainExtensionId');
});

test('loadbalancer 目标：校验实例 → DescribeListeners(Protocol=HTTPS) 取全部监听 → 各自 ModifyListenerAttributes', function () {
    $modifies = [];
    $alb = Mockery::mock(BytePlusRestClient::class);
    $alb->shouldReceive('openApi')->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body = null) use (&$modifies) {
        if ($action === 'DescribeLoadBalancerAttributes') {
            return new stdClass; // 校验实例存在
        }
        if ($action === 'DescribeListeners') {
            expect($query['Protocol'])->toBe('HTTPS');

            return (object) ['Listeners' => [
                (object) ['ListenerId' => 'lsn-1'],
                (object) ['ListenerId' => 'lsn-2'],
            ]];
        }
        if ($action === 'ModifyListenerAttributes') {
            $modifies[] = $query['ListenerId'];
        }

        return new stdClass;
    });

    $deployer = byteplusAlbDeployerWith(fn () => $alb);
    $deployer->bind('cert-NEW', byteplusAlbCreds(), [
        'region' => 'ap-singapore-1', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($modifies)->toBe(['lsn-1', 'lsn-2']);
});

test('loadbalancer 目标带 project_name：DescribeListeners 传 ProjectName', function () {
    $listQuery = null;
    $alb = Mockery::mock(BytePlusRestClient::class);
    $alb->shouldReceive('openApi')->andReturnUsing(function ($m, $action, $v, $query, $b = null) use (&$listQuery) {
        if ($action === 'DescribeListeners') {
            $listQuery = $query;

            return (object) ['Listeners' => []];
        }

        return new stdClass;
    });

    $deployer = byteplusAlbDeployerWith(fn () => $alb);
    $deployer->bind('cert-1', ['access_key_id' => 'AK', 'secret_access_key' => 'SK', 'project_name' => 'proj-9'], [
        'region' => 'ap-singapore-1', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($listQuery['ProjectName'])->toBe('proj-9');
});

test('makeClient lb 收到 config.region（签名 region）', function () {
    $seenRegion = null;
    $alb = Mockery::mock(BytePlusRestClient::class);
    $alb->shouldReceive('openApi')->andReturn(new stdClass);

    $deployer = byteplusAlbDeployerWith(function (string $kind, array $creds, string $region) use (&$seenRegion, $alb) {
        if ($kind === 'lb') {
            $seenRegion = $region;
        }

        return $alb;
    });
    $deployer->bind('cert-1', byteplusAlbCreds(), [
        'region' => 'cn-shanghai', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1',
    ]);

    expect($seenRegion)->toBe('cn-shanghai');
});

test('不支持的 deploy_target 抛业务错误（且不调用 SDK）', function () {
    $deployer = byteplusAlbDeployerWith(fn () => Mockery::mock(BytePlusRestClient::class)->shouldReceive('openApi')->never()->getMock());
    expect(fn () => $deployer->bind('c', byteplusAlbCreds(), ['region' => 'r', 'deploy_target' => 'unknown']))
        ->toThrow(RuntimeException::class, 'deploy_target');
});

test('缺 region / deploy_target / loadbalancer_id / listener_id 抛业务错误', function () {
    $deployer = byteplusAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', byteplusAlbCreds(), ['deploy_target' => 'listener', 'listener_id' => 'l']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', byteplusAlbCreds(), ['region' => 'r']))
        ->toThrow(RuntimeException::class, '缺少配置 deploy_target');
    expect(fn () => $deployer->bind('c', byteplusAlbCreds(), ['region' => 'r', 'deploy_target' => 'loadbalancer']))
        ->toThrow(RuntimeException::class, '缺少配置 loadbalancer_id');
    expect(fn () => $deployer->bind('c', byteplusAlbCreds(), ['region' => 'r', 'deploy_target' => 'listener']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛 BytePlusApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $alb = Mockery::mock(BytePlusRestClient::class);
    $alb->shouldReceive('openApi')->andThrow(new BytePlusApiException('ListenerNotFound', 'no listener'));

    $deployer = byteplusAlbDeployerWith(fn () => $alb);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'region' => 'ap-singapore-1', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ListenerNotFound');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

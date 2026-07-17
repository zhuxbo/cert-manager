<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusClbDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

function byteplusClbDeployerWith(callable $clientFactory): BytePlusClbDeployer
{
    return new class($clientFactory) extends BytePlusClbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function byteplusClbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus CLB：证书服务型（storeKind=byteplus_certcenter）', function () {
    $deployer = new BytePlusClbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_certcenter');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('clb');
});

test('listener 目标：直接 ModifyListenerAttributes（CLB 无 SNI，直设主证书）', function () {
    $captured = null;
    $clb = Mockery::mock(BytePlusRestClient::class);
    $clb->shouldReceive('openApi')->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body = null) use (&$captured) {
        $captured = compact('action', 'query', 'version');

        return new stdClass;
    });

    $deployer = byteplusClbDeployerWith(fn (string $kind) => $kind === 'lb' ? $clb : new stdClass);
    $deployer->bind('cert-NEW', byteplusClbCreds(), [
        'region' => 'ap-singapore-1', 'deploy_target' => 'listener', 'listener_id' => 'lsn-9',
    ]);

    expect($captured['action'])->toBe('ModifyListenerAttributes');
    expect($captured['version'])->toBe('2020-04-01');
    expect($captured['query'])->toBe([
        'ListenerId' => 'lsn-9',
        'CertificateSource' => 'cert_center',
        'CertCenterCertificateId' => 'cert-NEW',
    ]);
});

test('CLB 即便配了 domain 也忽略 SNI（不调 DescribeListenerAttributes，直设主证书）', function () {
    $actions = [];
    $clb = Mockery::mock(BytePlusRestClient::class);
    $clb->shouldReceive('openApi')->andReturnUsing(function ($m, $action, $v, $q, $b = null) use (&$actions) {
        $actions[] = $action;

        return new stdClass;
    });

    $deployer = byteplusClbDeployerWith(fn () => $clb);
    $deployer->bind('cert-1', byteplusClbCreds(), [
        'region' => 'ap-singapore-1', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1', 'domain' => 'sni.example.com',
    ]);

    // CLB supportsSni=false：不应调 DescribeListenerAttributes，只 ModifyListenerAttributes
    expect($actions)->toBe(['ModifyListenerAttributes']);
});

test('loadbalancer 目标：DescribeListeners(HTTPS) 取全部监听 → 各自 ModifyListenerAttributes', function () {
    $modifies = [];
    $clb = Mockery::mock(BytePlusRestClient::class);
    $clb->shouldReceive('openApi')->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body = null) use (&$modifies) {
        if ($action === 'DescribeLoadBalancerAttributes') {
            return new stdClass;
        }
        if ($action === 'DescribeListeners') {
            return (object) ['Listeners' => [(object) ['ListenerId' => 'lsn-1'], (object) ['ListenerId' => 'lsn-2']]];
        }
        if ($action === 'ModifyListenerAttributes') {
            $modifies[] = $query['ListenerId'];
        }

        return new stdClass;
    });

    $deployer = byteplusClbDeployerWith(fn () => $clb);
    $deployer->bind('cert-NEW', byteplusClbCreds(), [
        'region' => 'ap-singapore-1', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($modifies)->toBe(['lsn-1', 'lsn-2']);
});

test('缺 region / deploy_target / loadbalancer_id / listener_id 抛业务错误', function () {
    $deployer = byteplusClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', byteplusClbCreds(), ['deploy_target' => 'listener', 'listener_id' => 'l']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', byteplusClbCreds(), ['region' => 'r', 'deploy_target' => 'loadbalancer']))
        ->toThrow(RuntimeException::class, '缺少配置 loadbalancer_id');
    expect(fn () => $deployer->bind('c', byteplusClbCreds(), ['region' => 'r', 'deploy_target' => 'listener']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

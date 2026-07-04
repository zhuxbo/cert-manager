<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcClbDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

function volcClbDeployerWith(callable $clientFactory): VolcClbDeployer
{
    return new class($clientFactory) extends VolcClbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcClbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 CLB：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcClbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-beijing'])->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('clb');
});

test('listener 目标：ModifyListenerAttributes 设 cert_center + CertCenterCertificateId', function () {
    $params = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->once()->andReturnUsing(function (string $action, string $version, array $p) use (&$params) {
        $params = compact('action', 'p');

        return [];
    });

    $deployer = volcClbDeployerWith(fn (string $kind) => $kind === 'clb' ? $client : new stdClass);
    $deployer->bind('cert-9', volcClbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1']);

    expect($params['action'])->toBe('ModifyListenerAttributes');
    expect($params['p'])->toBe([
        'ListenerId' => 'lsn-1',
        'CertificateSource' => 'cert_center',
        'CertCenterCertificateId' => 'cert-9',
    ]);
});

test('loadbalancer 目标：DescribeListeners(HTTPS) 枚举 → 各监听更新', function () {
    $modifies = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturnUsing(function (string $action, string $version, array $params) use (&$modifies) {
        if ($action === 'DescribeListeners') {
            expect($params['Protocol'])->toBe('HTTPS');

            return ['Listeners' => [['ListenerId' => 'lsn-1'], ['ListenerId' => 'lsn-2']]];
        }
        if ($action === 'ModifyListenerAttributes') {
            $modifies[] = $params['ListenerId'];
        }

        return [];
    });

    $deployer = volcClbDeployerWith(fn () => $client);
    $deployer->bind('cert-9', volcClbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1']);

    expect($modifies)->toBe(['lsn-1', 'lsn-2']);
});

test('不支持的 deploy_target 抛业务错误', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->never();
    $deployer = volcClbDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcClbCreds(), ['region' => 'cn-beijing', 'deploy_target' => 'bad']))
        ->toThrow(RuntimeException::class, 'bad');
});

test('缺 region 抛业务错误', function () {
    $deployer = volcClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcClbCreds(), ['deploy_target' => 'listener', 'listener_id' => 'x']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andThrow(new VolcApiException('ListenerErr', 'modify failed'));

    $deployer = volcClbDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'region' => 'cn-beijing', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ListenerErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

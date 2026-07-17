<?php

use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudAlbDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

function jdcloudAlbDeployerWith(callable $clientFactory): JdcloudAlbDeployer
{
    return new class($clientFactory) extends JdcloudAlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

if (! function_exists('jdCreds')) {
    function jdCreds(): array
    {
        return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
    }
}

test('京东云 ALB：证书服务型（storeKind jdcloud_ssl）', function () {
    $deployer = new JdcloudAlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('jdcloud_ssl');
    expect($deployer->provider())->toBe('jdcloud');
    expect($deployer->product())->toBe('alb');
});

test('listener 目标无 SNI：UpdateListener 设默认证书 certificateSpecs', function () {
    $captured = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('updateAlbListenerCertificate')
        ->once()
        ->andReturnUsing(function (string $regionId, string $listenerId, string $certId) use (&$captured) {
            $captured = compact('regionId', 'listenerId', 'certId');
        });
    // 无 SNI 不应查扩展证书
    $client->shouldReceive('describeAlbListener')->never();

    $deployer = jdcloudAlbDeployerWith(fn (string $kind) => in_array($kind, ['ssl', 'lb'], true) ? $client : new stdClass);
    $deployer->bind('jdcert-001', jdCreds(), [
        'region_id' => 'cn-north-1',
        'deploy_target' => 'listener',
        'listener_id' => 'lsr-1',
    ]);

    expect($captured)->toBe(['regionId' => 'cn-north-1', 'listenerId' => 'lsr-1', 'certId' => 'jdcert-001']);
});

test('listener 目标有 SNI：DescribeListener 定位 certificateBindId → UpdateListenerCertificates', function () {
    $captured = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('describeAlbListener')->once()->with('cn-north-1', 'lsr-1')->andReturn([
        'extensionCertificateSpecs' => [
            ['certificateBindId' => 'bind-A', 'domain' => 'a.example.com', 'certificateId' => 'old-A'],
            ['certificateBindId' => 'bind-B', 'domain' => 'b.example.com', 'certificateId' => 'old-B'],
        ],
    ]);
    $client->shouldReceive('updateAlbListenerCertificates')
        ->once()
        ->andReturnUsing(function (string $regionId, string $listenerId, array $certs) use (&$captured) {
            $captured = compact('regionId', 'listenerId', 'certs');
        });
    $client->shouldReceive('updateAlbListenerCertificate')->never();

    $deployer = jdcloudAlbDeployerWith(fn () => $client);
    $deployer->bind('jdcert-NEW', jdCreds(), [
        'region_id' => 'cn-north-1',
        'deploy_target' => 'listener',
        'listener_id' => 'lsr-1',
        'domain' => 'b.example.com',
    ]);

    // 仅 domain 命中的扩展证书被替换为新 certId（保留 certificateBindId + domain）
    expect($captured['regionId'])->toBe('cn-north-1');
    expect($captured['listenerId'])->toBe('lsr-1');
    expect($captured['certs'])->toBe([
        ['certificateBindId' => 'bind-B', 'certificateId' => 'jdcert-NEW', 'domain' => 'b.example.com'],
    ]);
});

test('listener SNI 无匹配扩展证书 → 业务错误（DeployBusinessException）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('describeAlbListener')->once()->andReturn([
        'extensionCertificateSpecs' => [
            ['certificateBindId' => 'bind-A', 'domain' => 'a.example.com'],
        ],
    ]);
    $client->shouldReceive('updateAlbListenerCertificates')->never();

    $deployer = jdcloudAlbDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('jdcert-1', jdCreds(), [
        'region_id' => 'r', 'deploy_target' => 'listener', 'listener_id' => 'lsr-1', 'domain' => 'z.example.com',
    ]))->toThrow(DeployBusinessException::class, '扩展证书');
});

test('loadbalancer 目标：枚举 https/tls 监听器逐个 UpdateListener', function () {
    $updated = [];
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('listAlbHttpsListenerIds')->once()->with('cn-north-1', 'lb-1')->andReturn(['lsr-1', 'lsr-2']);
    $client->shouldReceive('updateAlbListenerCertificate')
        ->twice()
        ->andReturnUsing(function (string $regionId, string $listenerId, string $certId) use (&$updated) {
            $updated[] = compact('regionId', 'listenerId', 'certId');
        });

    $deployer = jdcloudAlbDeployerWith(fn () => $client);
    $deployer->bind('jdcert-1', jdCreds(), [
        'region_id' => 'cn-north-1',
        'deploy_target' => 'loadbalancer',
        'loadbalancer_id' => 'lb-1',
    ]);

    expect($updated)->toBe([
        ['regionId' => 'cn-north-1', 'listenerId' => 'lsr-1', 'certId' => 'jdcert-1'],
        ['regionId' => 'cn-north-1', 'listenerId' => 'lsr-2', 'certId' => 'jdcert-1'],
    ]);
});

test('loadbalancer 无 https/tls 监听器：无操作不报错（对齐 certimate）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('listAlbHttpsListenerIds')->once()->andReturn([]);
    $client->shouldReceive('updateAlbListenerCertificate')->never();

    $deployer = jdcloudAlbDeployerWith(fn () => $client);
    $deployer->bind('jdcert-1', jdCreds(), [
        'region_id' => 'r', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);
    expect(true)->toBeTrue();
});

test('未知 deploy_target → 业务错误', function () {
    $deployer = jdcloudAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-1', jdCreds(), ['region_id' => 'r', 'deploy_target' => 'foobar']))
        ->toThrow(DeployBusinessException::class, '不支持的部署目标');
});

test('listener 目标缺 listener_id 抛业务错误', function () {
    $deployer = jdcloudAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-1', jdCreds(), ['region_id' => 'r', 'deploy_target' => 'listener']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('loadbalancer 目标缺 loadbalancer_id 抛业务错误', function () {
    $deployer = jdcloudAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-1', jdCreds(), ['region_id' => 'r', 'deploy_target' => 'loadbalancer']))
        ->toThrow(RuntimeException::class, '缺少配置 loadbalancer_id');
});

test('bind SDK 抛 JdcloudApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('updateAlbListenerCertificate')->andThrow(new JdcloudApiException('ERR', 'lb error'));

    $deployer = jdcloudAlbDeployerWith(fn () => $client);

    try {
        $deployer->bind('jdcert-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK-LEAK'], [
            'region_id' => 'r', 'deploy_target' => 'listener', 'listener_id' => 'lsr-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ERR')->toContain('lb error');
        expect($e->getMessage())->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});

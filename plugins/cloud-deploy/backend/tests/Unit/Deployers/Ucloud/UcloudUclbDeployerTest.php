<?php

use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUclbDeployer;
use Tests\TestCase;

uses(TestCase::class);

function ucloudUclbDeployerWith(callable $clientFactory): UcloudUclbDeployer
{
    return new class($clientFactory) extends UcloudUclbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

const UCLB_CREDS = ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => ''];

test('UCLB 走 ULB 证书服务（storeKind=ucloud_ulb:{region}）', function () {
    $deployer = new UcloudUclbDeployer;
    expect($deployer->provider())->toBe('ucloud');
    expect($deployer->product())->toBe('uclb');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-bj2'])->storeKind())->toBe('ucloud_ulb:cn-bj2');
});

test('bind vserver 目标：先 UnbindSSL 旧证书再 BindSSL 新证书（CLB 单证书）', function () {
    $calls = [];
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeVServer')->once()->with('lb-1', 'vs-1', 0, 1)->andReturn([
        'DataSet' => [['VServerId' => 'vs-1', 'SSLSet' => [['SSLId' => 'old-ssl']]]],
    ]);
    $client->shouldReceive('unbindSSL')->once()->andReturnUsing(function (string $lb, string $vs, string $ssl) use (&$calls) {
        $calls[] = "unbind:$ssl";
    });
    $client->shouldReceive('bindSSL')->once()->andReturnUsing(function (string $lb, string $vs, string $ssl) use (&$calls) {
        $calls[] = "bind:$ssl";
    });

    $deployer = ucloudUclbDeployerWith(fn () => $client);
    $deployer->bind('new-ssl', UCLB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'vserver', 'loadbalancer_id' => 'lb-1', 'vserver_id' => 'vs-1',
    ]);

    // 先解绑旧、后绑新（顺序 load-bearing）
    expect($calls)->toBe(['unbind:old-ssl', 'bind:new-ssl']);
});

test('bind vserver 已绑同证书则跳过（不 unbind/bind）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeVServer')->once()->andReturn([
        'DataSet' => [['VServerId' => 'vs-1', 'SSLSet' => [['SSLId' => 'ssl-1']]]],
    ]);
    $client->shouldReceive('unbindSSL')->never();
    $client->shouldReceive('bindSSL')->never();

    $deployer = ucloudUclbDeployerWith(fn () => $client);
    $deployer->bind('ssl-1', UCLB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'vserver', 'loadbalancer_id' => 'lb-1', 'vserver_id' => 'vs-1',
    ]);

    expect(true)->toBeTrue();
});

test('bind vserver 无旧证书：直接 BindSSL（无 unbind）', function () {
    $calls = [];
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeVServer')->once()->andReturn([
        'DataSet' => [['VServerId' => 'vs-1', 'SSLSet' => []]],
    ]);
    $client->shouldReceive('unbindSSL')->never();
    $client->shouldReceive('bindSSL')->once()->andReturnUsing(function (string $lb, string $vs, string $ssl) use (&$calls) {
        $calls[] = "bind:$ssl";
    });

    $deployer = ucloudUclbDeployerWith(fn () => $client);
    $deployer->bind('ssl-new', UCLB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'vserver', 'loadbalancer_id' => 'lb-1', 'vserver_id' => 'vs-1',
    ]);

    expect($calls)->toBe(['bind:ssl-new']);
});

test('bind loadbalancer 目标：列 HTTPS VServer 逐个绑（跳过非 HTTPS）', function () {
    $bound = [];
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeVServer')->with('lb-1', null, 0, 100)->once()->andReturn([
        'DataSet' => [
            ['VServerId' => 'vs-https', 'Protocol' => 'HTTPS'],
            ['VServerId' => 'vs-tcp', 'Protocol' => 'TCP'],
        ],
    ]);
    $client->shouldReceive('describeVServer')->with('lb-1', 'vs-https', 0, 1)->once()->andReturn([
        'DataSet' => [['VServerId' => 'vs-https', 'SSLSet' => []]],
    ]);
    $client->shouldReceive('bindSSL')->andReturnUsing(function (string $lb, string $vs) use (&$bound) {
        $bound[] = $vs;
    });

    $deployer = ucloudUclbDeployerWith(fn () => $client);
    $deployer->bind('ssl-1', UCLB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($bound)->toBe(['vs-https']);
});

test('bind vserver 目标缺 vserver_id 抛业务错误', function () {
    $deployer = ucloudUclbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('ssl-1', UCLB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'vserver', 'loadbalancer_id' => 'lb-1',
    ]))->toThrow(RuntimeException::class, '缺少配置 vserver_id');
});

test('bind vserver 不存在抛业务错误', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeVServer')->once()->andReturn(['DataSet' => []]);

    $deployer = ucloudUclbDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('ssl-1', UCLB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'vserver', 'loadbalancer_id' => 'lb-1', 'vserver_id' => 'nope',
    ]))->toThrow(RuntimeException::class, '未找到 VServer');
});

test('bind SDK 抛 UcloudApiException 脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeVServer')->andThrow(new UcloudApiException('170', 'forbidden'));

    $deployer = ucloudUclbDeployerWith(fn () => $client);

    try {
        $deployer->bind('ssl-1', ['public_key' => 'PUB', 'private_key' => 'PRIV-LEAK-CLB'], [
            'region' => 'cn-bj2', 'deploy_target' => 'vserver', 'loadbalancer_id' => 'lb-1', 'vserver_id' => 'vs-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('170')->toContain('forbidden');
        expect($e->getMessage())->not->toContain('PRIV-LEAK-CLB');
        expect($e->getPrevious())->toBeNull();
    }
});

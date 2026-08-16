<?php

use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUalbDeployer;
use Tests\TestCase;

uses(TestCase::class);

function ucloudUalbDeployerWith(callable $clientFactory): UcloudUalbDeployer
{
    return new class($clientFactory) extends UcloudUalbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

const UALB_CREDS = ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => ''];

test('UALB 走 ULB 证书服务（storeKind=ucloud_ulb:{region}，按 region 隔离）', function () {
    $deployer = new UcloudUalbDeployer;
    expect($deployer->provider())->toBe('ucloud');
    expect($deployer->product())->toBe('ualb');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-bj2'])->storeKind())->toBe('ucloud_ulb:cn-bj2');
    expect($deployer->certUploader(['region' => 'cn-sh2'])->storeKind())->toBe('ucloud_ulb:cn-sh2');
});

test('uploader.upload 调 CreateSSL（UserCert/CaCert/PrivateKey 分列）返回 SSLId', function () {
    $args = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('createUlbSSL')
        ->once()
        ->andReturnUsing(function (string $name, string $userCert, string $caCert, string $priv) use (&$args) {
            $args = compact('name', 'userCert', 'caCert', 'priv');

            return 'ssl-abc-001';
        });

    $deployer = ucloudUalbDeployerWith(fn () => $client);
    $ref = $deployer->certUploader(['region' => 'cn-bj2'])->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', UALB_CREDS);

    expect($ref)->toBe('ssl-abc-001');
    expect($args['name'])->toStartWith('clouddeploy_');
    // UserCert=服务器证书、CaCert=中间证书（分列，非合并），PrivateKey=私钥
    expect($args['userCert'])->toBe('CERTPEM');
    expect($args['caCert'])->toBe('CHAINPEM');
    expect($args['priv'])->toBe('KEYPEM');
});

test('bind listener 目标无 SNI：UpdateListenerAttribute 设默认证书', function () {
    $captured = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeListeners')->once()->with('lb-1', 'ls-1', 0, 1)->andReturn([
        'Listeners' => [['ListenerId' => 'ls-1', 'Certificates' => []]],
    ]);
    $client->shouldReceive('updateListenerAttribute')
        ->once()
        ->andReturnUsing(function (string $lb, string $ls, array $certs) use (&$captured) {
            $captured = compact('lb', 'ls', 'certs');
        });

    $deployer = ucloudUalbDeployerWith(fn () => $client);
    $deployer->bind('ssl-1', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'ls-1',
    ]);

    expect($captured)->toBe(['lb' => 'lb-1', 'ls' => 'ls-1', 'certs' => ['ssl-1']]);
});

test('bind listener 目标带 SNI domain：AddSSLBinding 增扩展证书', function () {
    $captured = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeListeners')->once()->andReturn([
        'Listeners' => [['ListenerId' => 'ls-1', 'Certificates' => []]],
    ]);
    $client->shouldReceive('addSSLBinding')
        ->once()
        ->andReturnUsing(function (string $lb, string $ls, array $sslIds) use (&$captured) {
            $captured = compact('lb', 'ls', 'sslIds');
        });
    $client->shouldReceive('updateListenerAttribute')->never();

    $deployer = ucloudUalbDeployerWith(fn () => $client);
    $deployer->bind('ssl-1', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'ls-1', 'domain' => 'sni.example.com',
    ]);

    expect($captured)->toBe(['lb' => 'lb-1', 'ls' => 'ls-1', 'sslIds' => ['ssl-1']]);
});

test('bind listener 目标带 SNI domain：新增后解绑同域名旧扩展证书和过期证书', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeListeners')->once()->andReturn([
        'Listeners' => [[
            'ListenerId' => 'ls-1',
            'Certificates' => [
                ['SSLId' => 'ssl-old-same-domain', 'IsDefault' => false],
                ['SSLId' => 'ssl-old-expired', 'IsDefault' => false],
                ['SSLId' => 'ssl-default', 'IsDefault' => true],
            ],
        ]],
    ]);
    $client->shouldReceive('addSSLBinding')->once()->with('lb-1', 'ls-1', ['ssl-new']);
    $client->shouldReceive('describeSSLV2')->once()->with('ssl-old-same-domain')->andReturn([
        'DataSet' => [['SSLId' => 'ssl-old-same-domain', 'Domains' => 'sni.example.com', 'NotAfter' => time() + 3600]],
    ]);
    $client->shouldReceive('describeSSLV2')->once()->with('ssl-old-expired')->andReturn([
        'DataSet' => [['SSLId' => 'ssl-old-expired', 'Domains' => 'other.example.com', 'NotAfter' => time() - 3600]],
    ]);
    $client->shouldReceive('deleteSSLBinding')->once()->with('lb-1', 'ls-1', [
        'ssl-old-same-domain',
        'ssl-old-expired',
    ]);

    $deployer = ucloudUalbDeployerWith(fn () => $client);
    $deployer->bind('ssl-new', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'ls-1', 'domain' => 'sni.example.com',
    ]);
});

test('bind listener 已绑同默认证书则跳过（不调 update）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeListeners')->once()->andReturn([
        'Listeners' => [['ListenerId' => 'ls-1', 'Certificates' => [['SSLId' => 'ssl-1', 'IsDefault' => true]]]],
    ]);
    $client->shouldReceive('updateListenerAttribute')->never();
    $client->shouldReceive('addSSLBinding')->never();

    $deployer = ucloudUalbDeployerWith(fn () => $client);
    $deployer->bind('ssl-1', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'ls-1',
    ]);

    expect(true)->toBeTrue();
});

test('bind loadbalancer 目标：列 HTTPS 监听器逐个绑证书（跳过非 HTTPS）', function () {
    $updated = [];
    $client = Mockery::mock(UcloudRestClient::class);
    // 第一次（列全部）：offset 0
    $client->shouldReceive('describeListeners')->with('lb-1', null, 0, 100)->once()->andReturn([
        'Listeners' => [
            ['ListenerId' => 'ls-https-1', 'ListenerProtocol' => 'HTTPS'],
            ['ListenerId' => 'ls-http', 'ListenerProtocol' => 'HTTP'],
            ['ListenerId' => 'ls-https-2', 'ListenerProtocol' => 'HTTPS'],
        ],
    ]);
    // 逐个 update 时各自再 describe（limit 1）
    $client->shouldReceive('describeListeners')->with('lb-1', 'ls-https-1', 0, 1)->once()->andReturn([
        'Listeners' => [['ListenerId' => 'ls-https-1', 'Certificates' => []]],
    ]);
    $client->shouldReceive('describeListeners')->with('lb-1', 'ls-https-2', 0, 1)->once()->andReturn([
        'Listeners' => [['ListenerId' => 'ls-https-2', 'Certificates' => []]],
    ]);
    $client->shouldReceive('updateListenerAttribute')->andReturnUsing(function (string $lb, string $ls) use (&$updated) {
        $updated[] = $ls;
    });

    $deployer = ucloudUalbDeployerWith(fn () => $client);
    $deployer->bind('ssl-1', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    // 仅两个 HTTPS 监听器被绑定
    expect($updated)->toBe(['ls-https-1', 'ls-https-2']);
});

test('bind listener 目标缺 listener_id 抛业务错误', function () {
    $deployer = ucloudUalbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('ssl-1', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1',
    ]))->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind 不支持的 deploy_target 抛业务错误', function () {
    $deployer = ucloudUalbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('ssl-1', UALB_CREDS, [
        'region' => 'cn-bj2', 'deploy_target' => 'bogus', 'loadbalancer_id' => 'lb-1',
    ]))->toThrow(RuntimeException::class, '不支持的部署目标');
});

test('bind SDK 抛 UcloudApiException 脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('describeListeners')->andThrow(new UcloudApiException('170', 'forbidden'));

    $deployer = ucloudUalbDeployerWith(fn () => $client);

    try {
        $deployer->bind('ssl-1', ['public_key' => 'PUB', 'private_key' => 'PRIV-LEAK-ALB'], [
            'region' => 'cn-bj2', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'ls-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('170')->toContain('forbidden');
        expect($e->getMessage())->not->toContain('PRIV-LEAK-ALB');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudApiException;
use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudLbDeployer;
use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient（3 参带 zone）注入缝，按 $kind 返回 mock（lb）。
 * uploader 经 certUploader() 复用同一 makeClient('lb', ..., zone)，故 mock lb 即覆盖上传 + 绑定路径。
 */
function qingcloudLbDeployerWith(callable $clientFactory): QingcloudLbDeployer
{
    return new class($clientFactory) extends QingcloudLbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $zone = ''): object
        {
            return ($this->factory)($kind, $credentials, $zone);
        }
    };
}

function qingcloudCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('青云 LB：证书服务型（usesRemoteCertStore + storeKind zone 维度 + 元信息）', function () {
    $deployer = new QingcloudLbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->provider())->toBe('qingcloud');
    expect($deployer->product())->toBe('lb');
    expect($deployer->label())->toBe('青云 LB');
    // storeKind 编入 zone（隔离跨 zone 标识空间）
    expect($deployer->certUploader(['zone' => 'pek3a'])->storeKind())->toBe('qingcloud:pek3a');
});

test('uploader.upload 调 POST CreateServerCertificate 返回 server_certificate_id（content=完整链, private_key=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(QingcloudRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $action, array $params) use (&$captured) {
            $captured = compact('action', 'params');

            return ['ret_code' => 0, 'server_certificate_id' => 'sc-abc'];
        });

    $deployer = qingcloudLbDeployerWith(fn (string $kind) => $kind === 'lb' ? $client : new stdClass);
    $id = $deployer->certUploader(['zone' => 'pek3a'])->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', qingcloudCreds());

    expect($id)->toBe('sc-abc');
    expect($captured['action'])->toBe('CreateServerCertificate');
    expect($captured['params']['server_certificate_name'])->toStartWith('clouddeploy_');
    expect($captured['params']['certificate_content'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['params']['private_key'])->toBe('KEYPEM');
});

test('bind listener 目标：直接 AssociateServerCertsToLBListener（server_certificates=[certId]）', function () {
    $captured = null;
    $client = Mockery::mock(QingcloudRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $action, array $params) use (&$captured) {
            $captured = compact('action', 'params');

            return ['ret_code' => 0];
        });

    $deployer = qingcloudLbDeployerWith(fn () => $client);
    $deployer->bind('sc-abc', qingcloudCreds(), [
        'zone' => 'pek3a', 'deploy_target' => 'listener', 'listener_id' => 'lbl-1',
    ]);

    expect($captured['action'])->toBe('AssociateServerCertsToLBListener');
    expect($captured['params']['loadbalancer_listener'])->toBe('lbl-1');
    expect($captured['params']['server_certificates'])->toBe(['sc-abc']);
});

test('bind loadbalancer 目标：枚举 HTTPS 监听器（过滤非 https）逐个绑定', function () {
    $associateCalls = [];
    $client = Mockery::mock(QingcloudRestClient::class);
    $client->shouldReceive('get')
        ->once()
        ->andReturnUsing(function (string $action, array $params) {
            expect($action)->toBe('DescribeLoadBalancerListeners');
            expect($params['loadbalancer'])->toBe('lb-1');

            return ['ret_code' => 0, 'loadbalancer_listener_set' => [
                ['loadbalancer_listener_id' => 'lbl-1', 'listener_protocol' => 'https'],
                ['loadbalancer_listener_id' => 'lbl-2', 'listener_protocol' => 'http'], // 过滤
                ['loadbalancer_listener_id' => 'lbl-3', 'listener_protocol' => 'HTTPS'], // 大小写不敏感
            ]];
        });
    $client->shouldReceive('post')
        ->twice()
        ->andReturnUsing(function (string $action, array $params) use (&$associateCalls) {
            $associateCalls[] = $params;

            return ['ret_code' => 0];
        });

    $deployer = qingcloudLbDeployerWith(fn () => $client);
    $deployer->bind('sc-abc', qingcloudCreds(), [
        'zone' => 'pek3a', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($associateCalls)->toHaveCount(2);
    expect($associateCalls[0]['loadbalancer_listener'])->toBe('lbl-1');
    expect($associateCalls[1]['loadbalancer_listener'])->toBe('lbl-3');
});

test('bind loadbalancer 目标：无 HTTPS 监听器 → 业务失败，不绑定', function () {
    $client = Mockery::mock(QingcloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['ret_code' => 0, 'loadbalancer_listener_set' => [
        ['loadbalancer_listener_id' => 'lbl-2', 'listener_protocol' => 'http'],
    ]]);
    $client->shouldReceive('post')->never();

    $deployer = qingcloudLbDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind('sc-abc', qingcloudCreds(), [
        'zone' => 'pek3a', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]))->toThrow(RuntimeException::class, '未找到 HTTPS 监听器');
});

test('缺 deploy_target / loadbalancer_id / listener_id 配置抛业务错误', function () {
    $deployer = qingcloudLbDeployerWith(fn () => new stdClass);

    expect(fn () => $deployer->bind('sc', qingcloudCreds(), ['zone' => 'pek3a']))
        ->toThrow(RuntimeException::class, '缺少配置 deploy_target');

    $deployer2 = qingcloudLbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer2->bind('sc', qingcloudCreds(), ['zone' => 'pek3a', 'deploy_target' => 'listener']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('不支持的部署目标 → 业务失败', function () {
    $deployer = qingcloudLbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('sc', qingcloudCreds(), ['zone' => 'pek3a', 'deploy_target' => 'bogus']))
        ->toThrow(RuntimeException::class, '不支持的部署目标');
});

test('bind SDK 抛 QingcloudApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(QingcloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new QingcloudApiException('1300', 'invalid listener'));

    $deployer = qingcloudLbDeployerWith(fn () => $client);

    try {
        $deployer->bind('sc', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'zone' => 'pek3a', 'deploy_target' => 'listener', 'listener_id' => 'lbl-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('1300')->toContain('invalid listener');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类异常（含签名 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(QingcloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect https://api.qingcloud.com/iaas?access_key_id=AK-LEAK&signature=deadbeef',
    ));

    $deployer = qingcloudLbDeployerWith(fn () => $client);

    try {
        $deployer->bind('sc', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], [
            'zone' => 'pek3a', 'deploy_target' => 'listener', 'listener_id' => 'lbl-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('青云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('api.qingcloud.com');
        expect($e->getPrevious())->toBeNull();
    }
});

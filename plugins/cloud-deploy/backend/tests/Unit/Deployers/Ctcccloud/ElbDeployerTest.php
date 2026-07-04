<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudElbDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（elb）。 */
function ctyunElbDeployerWith(callable $clientFactory): CtcccloudElbDeployer
{
    return new class($clientFactory) extends CtcccloudElbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunElbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('天翼云 ELB：证书服务型（storeKind 含 region + 元信息 + schema）', function () {
    $deployer = new CtcccloudElbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    // storeKind 编入 region（region 维度隔离）
    expect($deployer->certUploader(['region_id' => 'cn-bj'])->storeKind())->toBe('ctcccloud_elb:cn-bj');
    expect($deployer->provider())->toBe('ctcccloud');
    expect($deployer->product())->toBe('elb');
    expect($deployer->label())->toBe('天翼云弹性负载均衡 ELB');

    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toBe(['region_id', 'deploy_target', 'loadbalancer_id', 'listener_id']);
});

test('certUploader 空 config region 退化空串（不抛）', function () {
    $deployer = new CtcccloudElbDeployer;
    expect($deployer->certUploader([])->storeKind())->toBe('ctcccloud_elb:');
});

test('uploader.upload 创建 ELB 证书（Server 类型 + regionID）返回 CertId（lowerCamel id）', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['statusCode' => '800', 'error' => 'SUCCESS', 'returnObj' => ['id' => 'cert-elb-1']];
    });

    $deployer = ctyunElbDeployerWith(fn () => $client);
    $certId = $deployer->certUploader(['region_id' => 'cn-bj'])->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ctyunElbCreds());

    expect($certId)->toBe('cert-elb-1');
    expect($captured['path'])->toBe('/v4/elb/create-certificate');
    expect($captured['body']['regionID'])->toBe('cn-bj');
    expect($captured['body']['type'])->toBe('Server');
    expect($captured['body']['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['privateKey'])->toBe('KEYPEM');
    expect($captured['body']['clientToken'])->toBeString()->not->toBe('');
});

test('bind target=listener：直接 update-listener 绑 certificateID', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['statusCode' => '200', 'error' => 'SUCCESS'];
    });

    $deployer = ctyunElbDeployerWith(fn () => $client);
    $deployer->bind('cert-elb-1', ctyunElbCreds(), [
        'region_id' => 'cn-bj', 'deploy_target' => 'listener', 'listener_id' => 'lsn-1',
    ]);

    expect($captured['path'])->toBe('/v4/elb/update-listener');
    expect($captured['body'])->toBe([
        'regionID' => 'cn-bj',
        'listenerID' => 'lsn-1',
        'certificateID' => 'cert-elb-1',
    ]);
});

test('bind target=loadbalancer：list-listener 过滤 HTTPS 后逐个 update-listener', function () {
    $listCall = null;
    $updateCalls = [];
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query) use (&$listCall) {
        $listCall = compact('path', 'query');

        return ['statusCode' => '200', 'error' => 'SUCCESS', 'returnObj' => [
            ['ID' => 'l-1', 'protocol' => 'HTTPS'],
            ['ID' => 'l-2', 'protocol' => 'HTTP'],   // 非 HTTPS 跳过
            ['ID' => 'l-3', 'protocol' => 'https'],  // 大小写不敏感
        ]];
    });
    $client->shouldReceive('post')->twice()->andReturnUsing(function (string $path, array $body) use (&$updateCalls) {
        $updateCalls[] = $body;

        return ['statusCode' => '200', 'error' => 'SUCCESS'];
    });

    $deployer = ctyunElbDeployerWith(fn () => $client);
    $deployer->bind('cert-elb-1', ctyunElbCreds(), [
        'region_id' => 'cn-bj', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($listCall['path'])->toBe('/v4/elb/list-listener');
    expect($listCall['query'])->toBe(['regionID' => 'cn-bj', 'loadBalancerID' => 'lb-1']);
    // 只更新 2 个 HTTPS 监听
    expect($updateCalls)->toHaveCount(2);
    expect($updateCalls[0]['listenerID'])->toBe('l-1');
    expect($updateCalls[1]['listenerID'])->toBe('l-3');
    expect($updateCalls[0]['certificateID'])->toBe('cert-elb-1');
});

test('bind target=loadbalancer 缺 loadbalancer_id → 业务错误', function () {
    $deployer = ctyunElbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', ctyunElbCreds(), ['region_id' => 'cn-bj', 'deploy_target' => 'loadbalancer']))
        ->toThrow(RuntimeException::class, '缺少配置 loadbalancer_id');
});

test('bind target=listener 缺 listener_id → 业务错误', function () {
    $deployer = ctyunElbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', ctyunElbCreds(), ['region_id' => 'cn-bj', 'deploy_target' => 'listener']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind 不支持的 deploy_target → 业务错误', function () {
    $deployer = ctyunElbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', ctyunElbCreds(), ['region_id' => 'cn-bj', 'deploy_target' => 'bogus']))
        ->toThrow(RuntimeException::class, '不支持的部署目标');
});

test('缺 region_id / deploy_target 配置抛业务错误', function () {
    $deployer = ctyunElbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', ctyunElbCreds(), ['deploy_target' => 'listener', 'listener_id' => 'l']))
        ->toThrow(RuntimeException::class, '缺少配置 region_id');
    expect(fn () => $deployer->bind('c', ctyunElbCreds(), ['region_id' => 'cn-bj']))
        ->toThrow(RuntimeException::class, '缺少配置 deploy_target');
});

test('bind SDK 抛异常脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException('cURL https://ctelb-global.ctapi.ctyun.cn/x AK-LEAK'));

    $deployer = ctyunElbDeployerWith(fn () => $client);
    try {
        $deployer->bind('c', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], [
            'region_id' => 'cn-bj', 'deploy_target' => 'listener', 'listener_id' => 'l-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('天翼云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('ctelb-global');
        expect($e->getPrevious())->toBeNull();
    }
});

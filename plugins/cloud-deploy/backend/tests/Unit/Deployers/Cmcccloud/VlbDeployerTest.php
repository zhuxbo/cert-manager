<?php

use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudApiException;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudRestClient;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudVlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient（3 参带 poolId）注入缝，按 $kind 返回 mock（vlb）。
 * uploader 经 certUploader() 复用同一 makeClient('vlb', ..., poolId)，故 mock vlb 即覆盖上传 + 绑定路径。
 */
function cmcccloudVlbDeployerWith(callable $clientFactory): CmcccloudVlbDeployer
{
    return new class($clientFactory) extends CmcccloudVlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $poolId = ''): object
        {
            return ($this->factory)($kind, $credentials, $poolId);
        }
    };
}

function cmcccloudVlbCreds(): array
{
    return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
}

test('移动云 VLB：证书服务型（usesRemoteCertStore + storeKind 含 poolId + type 维度 + 元信息）', function () {
    $deployer = new CmcccloudVlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->provider())->toBe('cmcccloud');
    expect($deployer->product())->toBe('vlb');
    expect($deployer->label())->toBe('移动云 VLB');
    // 无 SNI 域名 → type=server；有 SNI 域名 → type=sni；均编入 poolId
    expect($deployer->certUploader(['pool_id' => 'CIDC-RP-29'])->storeKind())->toBe('cmcccloud_vlb:CIDC-RP-29:server');
    expect($deployer->certUploader(['pool_id' => 'CIDC-RP-29', 'domain' => 'a.example.com'])->storeKind())->toBe('cmcccloud_vlb:CIDC-RP-29:sni');
});

test('uploader.upload 调 CreateLoadbalanceCertification 返回 certId（type=SERVER 无 SNI, publicKey=完整链）', function () {
    $captured = null;
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')
        ->once()
        ->andReturnUsing(function (string $method, string $path, array $pathParams, array $query, ?array $body) use (&$captured) {
            $captured = compact('method', 'path', 'body');

            return ['state' => 'OK', 'body' => 'cert-vlb-1'];
        });

    $deployer = cmcccloudVlbDeployerWith(fn (string $kind) => $kind === 'vlb' ? $client : new stdClass);
    $id = $deployer->certUploader(['pool_id' => 'CIDC-RP-29'])->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', cmcccloudVlbCreds());

    expect($id)->toBe('cert-vlb-1');
    expect($captured['method'])->toBe('POST');
    expect($captured['path'])->toContain('/acl/v3/certification');
    expect($captured['body']['type'])->toBe('SERVER');
    expect($captured['body']['publicKey'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['privateKey'])->toBe('KEYPEM');
});

test('uploader.upload type=SNI（指定 SNI 域名）', function () {
    $captured = null;
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->once()->andReturnUsing(function (string $m, string $p, array $pp, array $q, ?array $body) use (&$captured) {
        $captured = $body;

        return ['state' => 'OK', 'body' => 'cert-vlb-2'];
    });

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);
    $deployer->certUploader(['pool_id' => 'CIDC-RP-29', 'domain' => 'a.example.com'])->upload('C', 'K', 'CH', cmcccloudVlbCreds());

    expect($captured['type'])->toBe('SNI');
});

test('bind listener 目标（无 SNI）：查监听器 → UpdateListener 设 defaultTlsContainerId', function () {
    $calls = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pathParams = [], array $query = [], ?array $body = null) use (&$calls) {
        $calls[] = compact('method', 'path', 'pathParams', 'body');
        if (str_contains($path, 'listeners/https')) {
            return ['state' => 'OK', 'body' => ['content' => [
                ['id' => 'lsn-1', 'defaultTlsContainerId' => 'old-cert', 'sniContainerIdList' => []],
            ]]];
        }

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);
    $deployer->bind('cert-NEW', cmcccloudVlbCreds(), [
        'pool_id' => 'CIDC-RP-29', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'lsn-1',
    ]);

    $update = collect($calls)->first(fn ($c) => $c['method'] === 'PUT' && str_contains($c['path'], '/acl/v3/listener'));
    expect($update)->not->toBeNull();
    expect($update['body']['id'])->toBe('lsn-1');
    expect($update['body']['defaultTlsContainerId'])->toBe('cert-NEW');
    expect($update['body'])->not->toHaveKey('sniContainerIds');
    // 查监听器用 loadBalanceId 路径参数
    $list = collect($calls)->first(fn ($c) => str_contains($c['path'], 'listeners/https'));
    expect($list['pathParams'])->toBe(['loadBalanceId' => 'lb-1']);
});

test('bind listener 目标（已是该默认证书）→ 跳过 UpdateListener', function () {
    $calls = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path) use (&$calls) {
        $calls[] = $method.' '.$path;
        if (str_contains($path, 'listeners/https')) {
            return ['state' => 'OK', 'body' => ['content' => [['id' => 'lsn-1', 'defaultTlsContainerId' => 'cert-SAME']]]];
        }

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);
    $deployer->bind('cert-SAME', cmcccloudVlbCreds(), [
        'pool_id' => 'CIDC-RP-29', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'lsn-1',
    ]);

    expect(collect($calls)->contains(fn ($c) => str_starts_with($c, 'PUT')))->toBeFalse();
});

test('bind listener 目标（有 SNI 域名）：追加 sniContainerIds + sniUp', function () {
    $update = null;
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pp = [], array $q = [], ?array $body = null) use (&$update) {
        if (str_contains($path, 'listeners/https')) {
            return ['state' => 'OK', 'body' => ['content' => [
                ['id' => 'lsn-1', 'sniContainerIdList' => ['existing-cert']],
            ]]];
        }
        if ($method === 'PUT') {
            $update = $body;
        }

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);
    $deployer->bind('cert-NEW', cmcccloudVlbCreds(), [
        'pool_id' => 'CIDC-RP-29', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'lsn-1', 'domain' => 'a.example.com',
    ]);

    expect($update['id'])->toBe('lsn-1');
    expect($update['sniUp'])->toBeTrue();
    expect($update['sniContainerIds'])->toBe(['existing-cert', 'cert-NEW']);
});

test('bind loadbalancer 目标：枚举全部 HTTPS 监听器逐个 UpdateListener', function () {
    $updates = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pp = [], array $q = [], ?array $body = null) use (&$updates) {
        if (str_contains($path, 'listeners/https')) {
            return ['state' => 'OK', 'body' => ['content' => [
                ['id' => 'lsn-1', 'defaultTlsContainerId' => 'old'],
                ['id' => 'lsn-2', 'defaultTlsContainerId' => 'old'],
            ]]];
        }
        if ($method === 'PUT') {
            $updates[] = $body['id'];
        }

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);
    $deployer->bind('cert-NEW', cmcccloudVlbCreds(), [
        'pool_id' => 'CIDC-RP-29', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    expect($updates)->toBe(['lsn-1', 'lsn-2']);
});

test('bind loadbalancer 目标：无 HTTPS 监听器 → 业务失败', function () {
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturn(['state' => 'OK', 'body' => ['content' => []]]);

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('cert-NEW', cmcccloudVlbCreds(), [
        'pool_id' => 'CIDC-RP-29', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]))->toThrow(RuntimeException::class, '未找到 HTTPS 监听器');
});

test('缺 pool_id / deploy_target / loadbalancer_id / listener_id 抛业务错误', function () {
    $d1 = cmcccloudVlbDeployerWith(fn () => new stdClass);
    expect(fn () => $d1->bind('c', cmcccloudVlbCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 pool_id');

    $d2 = cmcccloudVlbDeployerWith(fn () => new stdClass);
    expect(fn () => $d2->bind('c', cmcccloudVlbCreds(), ['pool_id' => 'p', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('不支持的部署目标 → 业务失败', function () {
    $deployer = cmcccloudVlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', cmcccloudVlbCreds(), ['pool_id' => 'p', 'deploy_target' => 'bogus', 'loadbalancer_id' => 'lb-1']))
        ->toThrow(RuntimeException::class, '不支持的部署目标');
});

test('bind SDK 抛 CmcccloudApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andThrow(new CmcccloudApiException('PARAM_ERROR', 'bad listener'));

    $deployer = cmcccloudVlbDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'pool_id' => 'p', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_id' => 'lsn-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('PARAM_ERROR');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

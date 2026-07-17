<?php

use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudPathxDeployer;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 注入缝 mock 工厂收 (kind, credentials)；可据 credentials 区分有无 project_id。 */
function ucloudPathxDeployerWith(callable $clientFactory): UcloudPathxDeployer
{
    return new class($clientFactory) extends UcloudPathxDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('PathX 走证书服务（storeKind=ucloud_ussl）', function () {
    $deployer = new UcloudPathxDeployer;
    expect($deployer->provider())->toBe('ucloud');
    expect($deployer->product())->toBe('pathx');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('ucloud_ussl');
});

test('bind（凭证已配 project_id）直接 BindPathXSSL（Port=[端口], SSLId=数字 certId）', function () {
    $captured = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('getProjectList')->never(); // 已配 project_id，无需取默认
    $client->shouldReceive('bindPathXSSL')
        ->once()
        ->andReturnUsing(function (string $ugaId, array $ports, string $sslId) use (&$captured) {
            $captured = compact('ugaId', 'ports', 'sslId');
        });

    $deployer = ucloudPathxDeployerWith(fn () => $client);
    $deployer->bind('77777|clouddeploy_5', ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => 'proj-1'], [
        'accelerator_id' => 'uga-1', 'listener_port' => 443,
    ]);

    expect($captured)->toBe(['ugaId' => 'uga-1', 'ports' => [443], 'sslId' => '77777']);
});

test('bind（凭证缺 project_id）先 GetProjectList 取默认项目，再带 ProjectId BindPathXSSL', function () {
    $credsSeen = [];
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('getProjectList')->once()->andReturn([
        ['ProjectId' => 'proj-other', 'IsDefault' => false],
        ['ProjectId' => 'proj-default', 'IsDefault' => true],
    ]);
    $client->shouldReceive('bindPathXSSL')->once();

    $deployer = ucloudPathxDeployerWith(function (string $kind, array $cred) use ($client, &$credsSeen) {
        $credsSeen[] = $cred;

        return $client;
    });
    $deployer->bind('77777|c', ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => ''], [
        'accelerator_id' => 'uga-1', 'listener_port' => 443,
    ]);

    // 第二次构造 client（绑定调用）凭证已补入默认 project_id
    $lastCred = end($credsSeen);
    expect($lastCred['project_id'])->toBe('proj-default');
});

test('bind 缺 project_id 且无默认项目抛业务错误', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('getProjectList')->once()->andReturn([
        ['ProjectId' => 'proj-x', 'IsDefault' => false],
    ]);

    $deployer = ucloudPathxDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind('1|n', ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => ''], [
        'accelerator_id' => 'uga-1', 'listener_port' => 443,
    ]))->toThrow(RuntimeException::class, '未找到默认项目');
});

test('缺 accelerator_id / listener_port 抛业务错误', function () {
    $deployer = ucloudPathxDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1|n', ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => 'p'], ['listener_port' => 443]))
        ->toThrow(RuntimeException::class, '缺少配置 accelerator_id');
    expect(fn () => $deployer->bind('1|n', ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => 'p'], ['accelerator_id' => 'uga-1']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_port');
});

test('bind SDK 抛 UcloudApiException 脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('bindPathXSSL')->andThrow(new UcloudApiException('500', 'bind failed'));

    $deployer = ucloudPathxDeployerWith(fn () => $client);

    try {
        $deployer->bind('1|n', ['public_key' => 'PUB', 'private_key' => 'PRIV-LEAK-PATHX', 'project_id' => 'p'], [
            'accelerator_id' => 'uga-1', 'listener_port' => 443,
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('500')->toContain('bind failed');
        expect($e->getMessage())->not->toContain('PRIV-LEAK-PATHX');
        expect($e->getPrevious())->toBeNull();
    }
});

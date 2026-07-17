<?php

use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelApiException;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelClient;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelConsoleDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BaotapanelClient。 */
function baotapanelConsoleDeployerWith(callable $clientFactory): BaotapanelConsoleDeployer
{
    return new class($clientFactory) extends BaotapanelConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baotaCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function baotaCreds(): array
{
    return ['server_url' => 'https://panel.example.com:8888', 'api_key' => 'KEY'];
}

test('宝塔面板控制台为内联型 + 元信息', function () {
    $deployer = new BaotapanelConsoleDeployer;
    expect($deployer->provider())->toBe('baotapanel');
    expect($deployer->product())->toBe('console');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('auto_restart');
});

test('configSavePanelSSL(cert=完整链, key)；默认不重启', function () {
    $captured = null;
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('configSavePanelSSL')->once()->andReturnUsing(function (string $cert, string $key) use (&$captured) {
        $captured = compact('cert', 'key');
    });
    $client->shouldReceive('systemServiceAdmin')->never();

    $deployer = baotapanelConsoleDeployerWith(fn () => $client);
    $deployer->bind(baotaCertRef(), baotaCreds(), []);

    expect($captured['cert'])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('auto_restart=true 时调 systemServiceAdmin(nginx, restart)', function () {
    $restart = null;
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('configSavePanelSSL')->once();
    $client->shouldReceive('systemServiceAdmin')->once()->andReturnUsing(function (string $name, string $type) use (&$restart) {
        $restart = compact('name', 'type');
    });

    $deployer = baotapanelConsoleDeployerWith(fn () => $client);
    $deployer->bind(baotaCertRef(), baotaCreds(), ['auto_restart' => true]);

    expect($restart)->toBe(['name' => 'nginx', 'type' => 'restart']);
});

test('重启抛异常被吞（断连 error 不影响部署结果）', function () {
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('configSavePanelSSL')->once();
    $client->shouldReceive('systemServiceAdmin')->andThrow(new BaotapanelApiException('BaotaError', 'connection reset'));

    $deployer = baotapanelConsoleDeployerWith(fn () => $client);
    // 不应抛出
    $deployer->bind(baotaCertRef(), baotaCreds(), ['auto_restart' => true]);
    expect(true)->toBeTrue();
});

test('bind 遇 BaotapanelApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(BaotapanelClient::class);
    $client->shouldReceive('configSavePanelSSL')->andThrow(new BaotapanelApiException('BaotaError', '证书格式错误'));

    $deployer = baotapanelConsoleDeployerWith(fn () => $client);
    try {
        $deployer->bind(baotaCertRef(), ['server_url' => 'https://p', 'api_key' => 'APIKEY-LEAK-7'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('证书格式错误');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-7');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-7');
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelApiException;
use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelClient;
use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelConsoleDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock OnepanelClient。 */
function onepanelConsoleDeployerWith(callable $clientFactory): OnepanelConsoleDeployer
{
    return new class($clientFactory) extends OnepanelConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function onepanelCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function onepanelV1Creds(): array
{
    return ['server_url' => 'https://panel.example.com', 'api_version' => 'v1', 'api_key' => 'KEY'];
}

test('1Panel 面板为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new OnepanelConsoleDeployer;
    expect($deployer->provider())->toBe('onepanel');
    expect($deployer->product())->toBe('console');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('auto_restart');
});

test('v1：updatePanelSSL(cert=完整链, key, ssl=enable, sslType=import-paste, autoRestart=false)', function () {
    $captured = null;
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnFalse();
    $client->shouldReceive('updatePanelSSL')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;
    });

    $deployer = onepanelConsoleDeployerWith(fn () => $client);
    $deployer->bind(onepanelCertRef(), onepanelV1Creds(), []);

    expect($captured['cert'])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($captured['key'])->toBe('KEYPEM');
    expect($captured['ssl'])->toBe('enable');
    expect($captured['sslType'])->toBe('import-paste');
    expect($captured['autoRestart'])->toBe('false');
});

test('v2：ssl 枚举为 Enable（大写）+ autoRestart=true（config.auto_restart=true）', function () {
    $captured = null;
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnTrue();
    $client->shouldReceive('updatePanelSSL')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;
    });

    $deployer = onepanelConsoleDeployerWith(fn () => $client);
    $deployer->bind(onepanelCertRef(), [
        'server_url' => 'https://p', 'api_version' => 'v2', 'api_key' => 'KEY',
    ], ['auto_restart' => true]);

    expect($captured['ssl'])->toBe('Enable');
    expect($captured['autoRestart'])->toBe('true');
});

test('bind 遇 OnepanelApiException 时脱敏重抛（含码、无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnFalse();
    $client->shouldReceive('updatePanelSSL')->andThrow(new OnepanelApiException('400', 'bad ssl'));

    $deployer = onepanelConsoleDeployerWith(fn () => $client);
    try {
        $deployer->bind(onepanelCertRef(), [
            'server_url' => 'https://p', 'api_version' => 'v1', 'api_key' => 'APIKEY-LEAK-123',
        ], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('bad ssl');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-123');
    }
});

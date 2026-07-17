<?php

use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoApiException;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoClient;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoConsoleDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BaotapanelgoClient。 */
function baotapanelgoConsoleDeployerWith(callable $clientFactory): BaotapanelgoConsoleDeployer
{
    return new class($clientFactory) extends BaotapanelgoConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baotagoCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function baotagoCreds(): array
{
    return ['server_url' => 'https://panel.example.com:8888', 'api_key' => 'KEY'];
}

test('宝塔（Windows）控制台为内联型 + 元信息', function () {
    $deployer = new BaotapanelgoConsoleDeployer;
    expect($deployer->provider())->toBe('baotapanelgo');
    expect($deployer->product())->toBe('console');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->configSchema())->toBe([]);
});

test('configSetPanelSSL(ssl_status=1, cert=完整链, key)', function () {
    $captured = null;
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('configSetPanelSSL')->once()->andReturnUsing(function (int $status, string $cert, string $key) use (&$captured) {
        $captured = compact('status', 'cert', 'key');
    });

    $deployer = baotapanelgoConsoleDeployerWith(fn () => $client);
    $deployer->bind(baotagoCertRef(), baotagoCreds(), []);

    expect($captured['status'])->toBe(1);
    expect($captured['cert'])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('bind 遇 BaotapanelgoApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(BaotapanelgoClient::class);
    $client->shouldReceive('configSetPanelSSL')->andThrow(new BaotapanelgoApiException('BaotaError', '面板设置失败'));

    $deployer = baotapanelgoConsoleDeployerWith(fn () => $client);
    try {
        $deployer->bind(baotagoCertRef(), ['server_url' => 'https://p', 'api_key' => 'APIKEY-LEAK-5'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('面板设置失败');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-5');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-5');
    }
});

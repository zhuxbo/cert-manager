<?php

use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelApiException;
use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock RatpanelRestClient。 */
function ratpanelConsoleDeployerWith(callable $clientFactory): RatpanelConsoleDeployer
{
    return new class($clientFactory) extends RatpanelConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ratpanelConsoleCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function ratpanelConsoleCreds(): array
{
    return ['server_url' => 'https://panel:8888', 'access_token_id' => 1, 'access_token' => 'TOKEN'];
}

test('耗子面板 console 为内联型 + 无配置字段 + 元信息', function () {
    $deployer = new RatpanelConsoleDeployer;
    expect($deployer->provider())->toBe('ratpanel');
    expect($deployer->product())->toBe('console');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->configSchema())->toBe([]);
});

test('bind 调 setSettingCert（完整链→cert、key→key）', function () {
    $captured = null;
    $client = Mockery::mock(RatpanelRestClient::class);
    $client->shouldReceive('setSettingCert')->once()
        ->andReturnUsing(function (string $cert, string $key) use (&$captured) {
            $captured = compact('cert', 'key');
        });

    $deployer = ratpanelConsoleDeployerWith(fn () => $client);
    $deployer->bind(ratpanelConsoleCertRef(), ratpanelConsoleCreds(), []);

    expect($captured['cert'])->toContain('CERTPEM')->toContain('CHAINPEM'); // 完整链
    expect($captured['key'])->toBe('KEYPEM');
});

test('bind 遇 RatpanelApiException 时脱敏（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(RatpanelRestClient::class);
    $client->shouldReceive('setSettingCert')->andThrow(new RatpanelApiException('RatPanelError', 'forbidden'));

    $deployer = ratpanelConsoleDeployerWith(fn () => $client);
    try {
        $deployer->bind(ratpanelConsoleCertRef(), [
            'server_url' => 'https://panel:8888', 'access_token_id' => 1, 'access_token' => 'TOKEN-LEAK-123',
        ], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('forbidden');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

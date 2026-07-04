<?php

use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafApiException;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafClient;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafConsoleDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BaotawafClient。 */
function baotawafConsoleDeployerWith(callable $clientFactory): BaotawafConsoleDeployer
{
    return new class($clientFactory) extends BaotawafConsoleDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function btwafCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function btwafCreds(): array
{
    return ['server_url' => 'https://waf.example.com', 'api_key' => 'KEY'];
}

test('堡塔云 WAF 控制台为内联型 + 元信息', function () {
    $deployer = new BaotawafConsoleDeployer;
    expect($deployer->provider())->toBe('baotawaf');
    expect($deployer->product())->toBe('console');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->configSchema())->toBe([]);
});

test('configSetCert(certContent=完整链, keyContent)', function () {
    $captured = null;
    $client = Mockery::mock(BaotawafClient::class);
    $client->shouldReceive('configSetCert')->once()->andReturnUsing(function (string $cert, string $key) use (&$captured) {
        $captured = compact('cert', 'key');
    });

    $deployer = baotawafConsoleDeployerWith(fn () => $client);
    $deployer->bind(btwafCertRef(), btwafCreds(), []);

    expect($captured['cert'])->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('bind 遇 BaotawafApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(BaotawafClient::class);
    $client->shouldReceive('configSetCert')->andThrow(new BaotawafApiException('-1', '设置失败'));

    $deployer = baotawafConsoleDeployerWith(fn () => $client);
    try {
        $deployer->bind(btwafCertRef(), ['server_url' => 'https://w', 'api_key' => 'APIKEY-LEAK-1'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('设置失败');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-1');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-1');
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Cpanel\CpanelApiException;
use Plugins\CloudDeploy\Deployers\Cpanel\CpanelClient;
use Plugins\CloudDeploy\Deployers\Cpanel\CpanelDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock CpanelClient。 */
function cpanelDeployerWith(callable $clientFactory): CpanelDeployer
{
    return new class($clientFactory) extends CpanelDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function cpanelCertRef(): array
{
    return ['cert' => 'LEAFPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function cpanelCreds(): array
{
    return ['server_url' => 'https://host:2083', 'username' => 'USER', 'api_token' => 'TOKEN'];
}

test('cPanel 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CpanelDeployer;
    expect($deployer->provider())->toBe('cpanel');
    expect($deployer->product())->toBe('cpanel');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('domain');
});

test('bind 调 installSsl（leaf→cert、key→key、chain→cabundle、domain）', function () {
    $captured = null;
    $client = Mockery::mock(CpanelClient::class);
    $client->shouldReceive('installSsl')->once()
        ->andReturnUsing(function (string $domain, string $cert, string $key, string $ca) use (&$captured) {
            $captured = compact('domain', 'cert', 'key', 'ca');
        });

    $deployer = cpanelDeployerWith(fn () => $client);
    $deployer->bind(cpanelCertRef(), cpanelCreds(), ['domain' => 'www.example.com']);

    expect($captured)->toBe([
        'domain' => 'www.example.com',
        'cert' => 'LEAFPEM',
        'key' => 'KEYPEM',
        'ca' => 'CHAINPEM',
    ]);
});

test('缺 domain 抛业务错误', function () {
    $deployer = cpanelDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cpanelCertRef(), cpanelCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 遇 CpanelApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(CpanelClient::class);
    $client->shouldReceive('installSsl')->andThrow(new CpanelApiException('0', 'The domain does not exist'));

    $deployer = cpanelDeployerWith(fn () => $client);
    try {
        $deployer->bind(cpanelCertRef(), [
            'server_url' => 'https://host:2083', 'username' => 'USER-LEAK', 'api_token' => 'TOKEN-LEAK-123',
        ], ['domain' => 'www.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('The domain does not exist');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

test('makeClient 注入 cpanel 令牌头 + base_uri/execute + allow_insecure 关 TLS 校验', function () {
    // 走真实 makeClient（不 override），用反射读出 Guzzle 配置验证鉴权头与 verify
    $deployer = new CpanelDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', [
        'server_url' => 'https://host:2083/', 'username' => 'u', 'api_token' => 't', 'allow_insecure' => true,
    ]);
    expect($client)->toBeInstanceOf(CpanelClient::class);

    // 读 CpanelClient 内部 Guzzle 的 config 校验头/verify/base_uri
    $httpProp = new ReflectionProperty($client, 'http');
    $httpProp->setAccessible(true);
    $guzzle = $httpProp->getValue($client);
    $cfg = new ReflectionMethod($guzzle, 'getConfig');
    $cfg->setAccessible(true);
    expect($cfg->invoke($guzzle, 'headers')['Authorization'])->toBe('cpanel u:t');
    expect($cfg->invoke($guzzle, 'verify'))->toBeFalse();
    expect((string) $cfg->invoke($guzzle, 'base_uri'))->toBe('https://host:2083/execute/');
});

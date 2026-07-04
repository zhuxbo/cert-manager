<?php

use Plugins\CloudDeploy\Deployers\Samwaf\SamwafApiException;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafClient;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock SamwafClient。 */
function samwafDeployerWith(callable $clientFactory): SamwafDeployer
{
    return new class($clientFactory) extends SamwafDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function samwafCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function samwafCreds(): array
{
    return ['server_url' => 'https://waf:26666', 'api_key' => 'KEY'];
}

test('SamWaf 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new SamwafDeployer;
    expect($deployer->provider())->toBe('samwaf');
    expect($deployer->product())->toBe('samwaf');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('bind detail-then-edit：先查详情确认存在，再 editSslConfig（snake_case 完整链+key）', function () {
    $editArgs = null;
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('getSslConfigDetail')->once()->with('cert-uuid')
        ->andReturn(['id' => 'cert-uuid', 'cert_content' => 'OLD']);
    $client->shouldReceive('editSslConfig')->once()
        ->andReturnUsing(function (string $id, string $cert, string $key) use (&$editArgs) {
            $editArgs = compact('id', 'cert', 'key');
        });

    $deployer = samwafDeployerWith(fn () => $client);
    $deployer->bind(samwafCertRef(), samwafCreds(), ['certificate_id' => 'cert-uuid']);

    expect($editArgs['id'])->toBe('cert-uuid');
    expect($editArgs['cert'])->toContain('CERTPEM')->toContain('CHAINPEM'); // 完整链
    expect($editArgs['key'])->toBe('KEYPEM');
});

test('详情未找到（data 为 null）则报错且不调 edit', function () {
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('getSslConfigDetail')->once()->andReturn(null);
    $client->shouldReceive('editSslConfig')->never();

    $deployer = samwafDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind(samwafCertRef(), samwafCreds(), ['certificate_id' => 'missing']))
        ->toThrow(RuntimeException::class, '未找到 SSL 配置');
});

test('详情返回但 id 为空也视为未找到', function () {
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('getSslConfigDetail')->once()->andReturn(['id' => '']);
    $client->shouldReceive('editSslConfig')->never();

    $deployer = samwafDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind(samwafCertRef(), samwafCreds(), ['certificate_id' => 'x']))
        ->toThrow(RuntimeException::class, '未找到 SSL 配置');
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = samwafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(samwafCertRef(), samwafCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 SamwafApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(SamwafClient::class);
    $client->shouldReceive('getSslConfigDetail')->andThrow(new SamwafApiException('401', 'unauthorized'));

    $deployer = samwafDeployerWith(fn () => $client);
    try {
        $deployer->bind(samwafCertRef(), ['server_url' => 'https://waf:26666', 'api_key' => 'KEY-LEAK-123'], ['certificate_id' => 'x']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('401')->toContain('unauthorized');
        expect($e->getMessage())->not->toContain('KEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('KEY-LEAK-123');
    }
});

test('makeClient 注入 X-API-Key 头 + base/api/v1 + allow_insecure 关 TLS', function () {
    $deployer = new SamwafDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', [
        'server_url' => 'https://waf:26666/', 'api_key' => 'k', 'allow_insecure' => true,
    ]);

    $httpProp = new ReflectionProperty($client, 'http');
    $httpProp->setAccessible(true);
    $guzzle = $httpProp->getValue($client);
    $cfg = new ReflectionMethod($guzzle, 'getConfig');
    $cfg->setAccessible(true);
    expect($cfg->invoke($guzzle, 'headers')['X-API-Key'])->toBe('k');
    expect($cfg->invoke($guzzle, 'verify'))->toBeFalse();
    expect((string) $cfg->invoke($guzzle, 'base_uri'))->toBe('https://waf:26666/api/v1/');
});

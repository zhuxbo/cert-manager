<?php

use Plugins\CloudDeploy\Deployers\Safeline\SafelineApiException;
use Plugins\CloudDeploy\Deployers\Safeline\SafelineClient;
use Plugins\CloudDeploy\Deployers\Safeline\SafelineDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock SafelineClient。 */
function safelineDeployerWith(callable $clientFactory): SafelineDeployer
{
    return new class($clientFactory) extends SafelineDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function safelineCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function safelineCreds(): array
{
    return ['server_url' => 'https://waf:9443', 'api_token' => 'TOKEN'];
}

test('SafeLine 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new SafelineDeployer;
    expect($deployer->provider())->toBe('safeline');
    expect($deployer->product())->toBe('safeline');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('bind 调 updateCertificate（id 转 int、完整链→crt、key→key）', function () {
    $captured = null;
    $client = Mockery::mock(SafelineClient::class);
    $client->shouldReceive('updateCertificate')->once()
        ->andReturnUsing(function (int $id, string $cert, string $key) use (&$captured) {
            $captured = compact('id', 'cert', 'key');
        });

    $deployer = safelineDeployerWith(fn () => $client);
    // certificate_id 以字符串传入（前端表单值），断言被转为 int
    $deployer->bind(safelineCertRef(), safelineCreds(), ['certificate_id' => '42']);

    expect($captured['id'])->toBe(42);
    expect($captured['cert'])->toContain('CERTPEM')->toContain('CHAINPEM'); // 完整链
    expect($captured['key'])->toBe('KEYPEM');
});

test('缺 certificate_id 抛业务错误', function () {
    $deployer = safelineDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(safelineCertRef(), safelineCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 SafelineApiException 时脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(SafelineClient::class);
    $client->shouldReceive('updateCertificate')->andThrow(new SafelineApiException('permission denied', 'token invalid'));

    $deployer = safelineDeployerWith(fn () => $client);
    try {
        $deployer->bind(safelineCertRef(), ['server_url' => 'https://waf:9443', 'api_token' => 'TOKEN-LEAK-123'], ['certificate_id' => 1]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('permission denied')->toContain('token invalid');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

test('makeClient 注入 X-SLCE-API-TOKEN 头 + allow_insecure 关 TLS 校验', function () {
    $deployer = new SafelineDeployer;
    $ref = new ReflectionMethod($deployer, 'makeClient');
    $ref->setAccessible(true);
    $client = $ref->invoke($deployer, 'api', [
        'server_url' => 'https://waf:9443/', 'api_token' => 'tok', 'allow_insecure' => true,
    ]);

    $httpProp = new ReflectionProperty($client, 'http');
    $httpProp->setAccessible(true);
    $guzzle = $httpProp->getValue($client);
    $cfg = new ReflectionMethod($guzzle, 'getConfig');
    $cfg->setAccessible(true);
    expect($cfg->invoke($guzzle, 'headers')['X-SLCE-API-TOKEN'])->toBe('tok');
    expect($cfg->invoke($guzzle, 'verify'))->toBeFalse();
    expect((string) $cfg->invoke($guzzle, 'base_uri'))->toBe('https://waf:9443/');
});

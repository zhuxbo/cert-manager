<?php

use Plugins\CloudDeploy\Deployers\Flyio\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Flyio\FlyioApiException;
use Plugins\CloudDeploy\Deployers\Flyio\FlyioClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock FlyioClient。 */
function flyioCertificateDeployerWith(callable $clientFactory): CertificateDeployer
{
    return new class($clientFactory) extends CertificateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function flyioCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('Fly.io 证书为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('flyio');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('app_name')->toContain('domain');
});

test('bind 调 importCustomCertificate（appName + hostname/fullchain(cert+chain)/private_key）', function () {
    $captured = null;
    $client = Mockery::mock(FlyioClient::class);
    $client->shouldReceive('importCustomCertificate')->once()->andReturnUsing(function (string $appName, array $body) use (&$captured) {
        $captured = [$appName, $body];
    });

    $deployer = flyioCertificateDeployerWith(fn () => $client);
    $deployer->bind(flyioCertRef(), ['api_token' => 'TOKEN'], ['app_name' => 'my-app', 'domain' => 'example.com']);

    [$appName, $body] = $captured;
    expect($appName)->toBe('my-app');
    expect($body['hostname'])->toBe('example.com');
    expect($body['fullchain'])->toBe("CERTPEM\nCHAINPEM");
    expect($body['private_key'])->toBe('KEYPEM');
    expect($deployer->touchedConfigKeys())->toContain('app_name')->toContain('domain');
});

test('缺 app_name 抛业务错误', function () {
    $deployer = flyioCertificateDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(flyioCertRef(), ['api_token' => 'TOKEN'], ['domain' => 'example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 app_name');
});

test('缺 domain 抛业务错误', function () {
    $deployer = flyioCertificateDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(flyioCertRef(), ['api_token' => 'TOKEN'], ['app_name' => 'my-app']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 遇 FlyioApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(FlyioClient::class);
    $client->shouldReceive('importCustomCertificate')->andThrow(new FlyioApiException('401', 'unauthorized'));

    $deployer = flyioCertificateDeployerWith(fn () => $client);
    try {
        $deployer->bind(flyioCertRef(), ['api_token' => 'TOKEN-LEAK-123'], ['app_name' => 'my-app', 'domain' => 'example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('401')->toContain('unauthorized');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

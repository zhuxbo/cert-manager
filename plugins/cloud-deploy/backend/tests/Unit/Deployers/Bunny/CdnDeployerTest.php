<?php

use Plugins\CloudDeploy\Deployers\Bunny\BunnyApiException;
use Plugins\CloudDeploy\Deployers\Bunny\BunnyClient;
use Plugins\CloudDeploy\Deployers\Bunny\CdnDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock BunnyClient。 */
function bunnyCdnDeployerWith(callable $clientFactory): CdnDeployer
{
    return new class($clientFactory) extends CdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function bunnyCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('Bunny CDN 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CdnDeployer;
    expect($deployer->provider())->toBe('bunny');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('pull_zone_id')->toContain('hostname');
});

test('bind 调 addCustomCertificate（Hostname + base64 完整链 + base64 私钥）', function () {
    $captured = null;
    $client = Mockery::mock(BunnyClient::class);
    $client->shouldReceive('addCustomCertificate')->once()->andReturnUsing(function (string $pullZoneId, array $body) use (&$captured) {
        $captured = [$pullZoneId, $body];
    });

    $deployer = bunnyCdnDeployerWith(fn () => $client);
    $deployer->bind(bunnyCertRef(), ['api_key' => 'KEY'], ['pull_zone_id' => 'pz-1', 'hostname' => 'cdn.example.com']);

    [$pullZoneId, $body] = $captured;
    expect($pullZoneId)->toBe('pz-1');
    expect($body['Hostname'])->toBe('cdn.example.com');
    // Certificate/CertificateKey 必须是 base64
    expect(base64_decode($body['Certificate'], true))->toBe("CERTPEM\nCHAINPEM");
    expect(base64_decode($body['CertificateKey'], true))->toBe('KEYPEM');
});

test('缺 pull_zone_id 抛业务错误', function () {
    $deployer = bunnyCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(bunnyCertRef(), ['api_key' => 'KEY'], ['hostname' => 'cdn.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 pull_zone_id');
});

test('缺 hostname 抛业务错误', function () {
    $deployer = bunnyCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(bunnyCertRef(), ['api_key' => 'KEY'], ['pull_zone_id' => 'pz-1']))
        ->toThrow(RuntimeException::class, '缺少配置 hostname');
});

test('bind 遇 BunnyApiException 时脱敏重抛（含状态码、无 key、不挂 previous）', function () {
    $client = Mockery::mock(BunnyClient::class);
    $client->shouldReceive('addCustomCertificate')->andThrow(new BunnyApiException('401', 'Unauthorized'));

    $deployer = bunnyCdnDeployerWith(fn () => $client);
    try {
        $deployer->bind(bunnyCertRef(), ['api_key' => 'KEY-LEAK-123'], ['pull_zone_id' => 'pz-1', 'hostname' => 'cdn.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('401')->toContain('Unauthorized');
        expect($e->getMessage())->not->toContain('KEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('KEY-LEAK-123');
    }
});

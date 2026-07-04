<?php

use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

function jdcloudCdnDeployerWith(callable $clientFactory): JdcloudCdnDeployer
{
    return new class($clientFactory) extends JdcloudCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

if (! function_exists('jdCreds')) {
    function jdCreds(): array
    {
        return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
    }
}

test('京东云 CDN：证书服务型（storeKind jdcloud_ssl）', function () {
    $deployer = new JdcloudCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('jdcloud_ssl');
    expect($deployer->provider())->toBe('jdcloud');
    expect($deployer->product())->toBe('cdn');
});

test('bind：QueryDomainConfig 取 jumpType → SetHttpType 绑 certId（沿用 jumpType）', function () {
    $captured = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('queryCdnDomainHttpsJumpType')->once()->with('cdn.example.com')->andReturn('redirect');
    $client->shouldReceive('setCdnHttpType')
        ->once()
        ->andReturnUsing(function (string $domain, string $certId, string $jumpType) use (&$captured) {
            $captured = compact('domain', 'certId', 'jumpType');
        });

    $deployer = jdcloudCdnDeployerWith(fn (string $kind) => in_array($kind, ['ssl', 'cdn'], true) ? $client : new stdClass);
    $deployer->bind('jdcert-001', jdCreds(), ['domain' => 'cdn.example.com']);

    expect($captured)->toBe(['domain' => 'cdn.example.com', 'certId' => 'jdcert-001', 'jumpType' => 'redirect']);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = jdcloudCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-001', jdCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 JdcloudApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('queryCdnDomainHttpsJumpType')->andThrow(new JdcloudApiException('404', 'domain not found'));

    $deployer = jdcloudCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('jdcert-001', ['access_key_id' => 'AK-SECRET', 'access_key_secret' => 'SK-SECRET'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('404')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET')->not->toContain('SK-SECRET');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SK-SECRET');
    }
});

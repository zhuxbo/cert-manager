<?php

use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudWafDeployer;
use Tests\TestCase;

uses(TestCase::class);

function jdcloudWafDeployerWith(callable $clientFactory): JdcloudWafDeployer
{
    return new class($clientFactory) extends JdcloudWafDeployer
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

test('京东云 WAF：证书服务型（storeKind jdcloud_ssl）', function () {
    $deployer = new JdcloudWafDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('jdcloud_ssl');
    expect($deployer->provider())->toBe('jdcloud');
    expect($deployer->product())->toBe('waf');
});

test('bind：BindCert 传 regionId/wafInstanceId/domain/certId', function () {
    $captured = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('bindWafCert')
        ->once()
        ->andReturnUsing(function (string $regionId, string $instanceId, string $domain, string $certId) use (&$captured) {
            $captured = compact('regionId', 'instanceId', 'domain', 'certId');
        });

    $deployer = jdcloudWafDeployerWith(fn (string $kind) => in_array($kind, ['ssl', 'waf'], true) ? $client : new stdClass);
    $deployer->bind('jdcert-001', jdCreds(), [
        'region_id' => 'cn-north-1',
        'instance_id' => 'waf-abc',
        'domain' => 'app.example.com',
    ]);

    expect($captured)->toBe([
        'regionId' => 'cn-north-1',
        'instanceId' => 'waf-abc',
        'domain' => 'app.example.com',
        'certId' => 'jdcert-001',
    ]);
});

test('缺 region_id / instance_id / domain 配置抛业务错误', function () {
    $deployer = jdcloudWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-001', jdCreds(), ['instance_id' => 'w', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region_id');
    expect(fn () => $deployer->bind('jdcert-001', jdCreds(), ['region_id' => 'r', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 instance_id');
    expect(fn () => $deployer->bind('jdcert-001', jdCreds(), ['region_id' => 'r', 'instance_id' => 'w']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 JdcloudApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('bindWafCert')->andThrow(new JdcloudApiException('INVALID', 'invalid domain'));

    $deployer = jdcloudWafDeployerWith(fn () => $client);

    try {
        $deployer->bind('jdcert-001', ['access_key_id' => 'AK', 'access_key_secret' => 'SK-LEAK'], [
            'region_id' => 'r', 'instance_id' => 'w', 'domain' => 'd',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('INVALID')->toContain('invalid domain');
        expect($e->getMessage())->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});

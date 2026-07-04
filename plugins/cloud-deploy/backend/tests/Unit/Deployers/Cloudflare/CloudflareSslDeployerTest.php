<?php

use Plugins\CloudDeploy\Deployers\Cloudflare\CloudflareApiException;
use Plugins\CloudDeploy\Deployers\Cloudflare\CloudflareClient;
use Plugins\CloudDeploy\Deployers\Cloudflare\CloudflareSslDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock CloudflareClient。 */
function cloudflareSslDeployerWith(callable $clientFactory): CloudflareSslDeployer
{
    return new class($clientFactory) extends CloudflareSslDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function cloudflareCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('Cloudflare SSL 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CloudflareSslDeployer;
    expect($deployer->provider())->toBe('cloudflare');
    expect($deployer->product())->toBe('ssl');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('zone_id')->toContain('certificate_id')->toContain('environment');
});

test('新建路径（未填 certificate_id）：createCustomCertificate(zone, 完整链+key+bundle+deploy)', function () {
    $captured = null;
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('createCustomCertificate')->once()->andReturnUsing(function (string $zone, array $body) use (&$captured) {
        $captured = [$zone, $body];
    });
    $client->shouldNotReceive('editCustomCertificate');

    $deployer = cloudflareSslDeployerWith(fn () => $client);
    $deployer->bind(cloudflareCertRef(), ['api_token' => 'TOKEN'], ['zone_id' => 'zone-1']);

    [$zone, $body] = $captured;
    expect($zone)->toBe('zone-1');
    expect($body['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($body['private_key'])->toBe('KEYPEM');
    expect($body['bundle_method'])->toBe('ubiquitous');
    expect($body['deploy'])->toBe('production'); // environment 默认
});

test('更新路径（填 certificate_id）：editCustomCertificate(zone, certId, body)，不新建', function () {
    $captured = null;
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('editCustomCertificate')->once()->andReturnUsing(function (string $zone, string $certId, array $body) use (&$captured) {
        $captured = [$zone, $certId, $body];
    });
    $client->shouldNotReceive('createCustomCertificate');

    $deployer = cloudflareSslDeployerWith(fn () => $client);
    $deployer->bind(cloudflareCertRef(), ['api_token' => 'TOKEN'], [
        'zone_id' => 'zone-1', 'certificate_id' => 'cert-9', 'environment' => 'staging',
    ]);

    [$zone, $certId, $body] = $captured;
    expect($zone)->toBe('zone-1');
    expect($certId)->toBe('cert-9');
    expect($body['deploy'])->toBe('staging');
});

test('缺 zone_id 抛业务错误', function () {
    $deployer = cloudflareSslDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cloudflareCertRef(), ['api_token' => 'TOKEN'], []))
        ->toThrow(RuntimeException::class, '缺少配置 zone_id');
});

test('bind 遇 CloudflareApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('createCustomCertificate')->andThrow(new CloudflareApiException('1004', 'Custom certificates are only available for Business and Enterprise'));

    $deployer = cloudflareSslDeployerWith(fn () => $client);
    try {
        $deployer->bind(cloudflareCertRef(), ['api_token' => 'TOKEN-LEAK-123'], ['zone_id' => 'zone-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('1004')->toContain('Business and Enterprise');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

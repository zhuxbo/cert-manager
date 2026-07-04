<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcWafDeployer;
use Tests\TestCase;

uses(TestCase::class);

function volcWafDeployerWith(callable $clientFactory): VolcWafDeployer
{
    return new class($clientFactory) extends VolcWafDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcWafCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 WAF：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcWafDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-beijing'])->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('waf');
});

test('bind：ListDomain 精确查 → UpdateDomain 设 VolcCertificateID + certificate-service，保留既有协议/端口', function () {
    $update = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$update) {
        if ($action === 'ListDomain') {
            expect($body['AccurateQuery'])->toBe(1);

            return ['Data' => [[
                'LBAlgorithm' => 'wrr',
                'Protocols' => 'HTTP',
                'ProtocolPorts' => ['HTTP' => [80, 8080]],
            ]]];
        }
        if ($action === 'UpdateDomain') {
            $update = $body;
        }

        return [];
    });

    $deployer = volcWafDeployerWith(fn (string $kind) => $kind === 'waf' ? $client : new stdClass);
    $deployer->bind('cert-9', volcWafCreds(), ['region' => 'cn-beijing', 'access_mode' => 'cname', 'domain' => 'waf.example.com']);

    expect($update['VolcCertificateID'])->toBe('cert-9');
    expect($update['CertificatePlatform'])->toBe('certificate-service');
    expect($update['AccessMode'])->toBe(10);
    expect($update['Domain'])->toBe('waf.example.com');
    // 既有 LBAlgorithm 保留、Protocols 补 HTTPS、端口沿用既有 HTTP + 默认 HTTPS 443
    expect($update['LBAlgorithm'])->toBe('wrr');
    expect($update['Protocols'])->toBe(['HTTP', 'HTTPS']);
    expect($update['ProtocolPorts']['HTTP'])->toBe([80, 8080]);
    expect($update['ProtocolPorts']['HTTPS'])->toBe([443]);
});

test('未找到防护域名抛业务错误', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn(['Data' => []]);
    $deployer = volcWafDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcWafCreds(), ['region' => 'cn-beijing', 'access_mode' => 'cname', 'domain' => 'none.example.com']))
        ->toThrow(RuntimeException::class, 'none.example.com');
});

test('不支持的接入模式抛业务错误（且不调 SDK）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->never();
    $deployer = volcWafDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcWafCreds(), ['region' => 'cn-beijing', 'access_mode' => 'alb', 'domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, 'alb');
});

test('缺 region / access_mode / domain 抛业务错误', function () {
    $deployer = volcWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcWafCreds(), ['access_mode' => 'cname', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', volcWafCreds(), ['region' => 'cn-beijing', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 access_mode');
    expect(fn () => $deployer->bind('c', volcWafCreds(), ['region' => 'cn-beijing', 'access_mode' => 'cname']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('WafErr', 'update failed'));

    $deployer = volcWafDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['region' => 'cn-beijing', 'access_mode' => 'cname', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('WafErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

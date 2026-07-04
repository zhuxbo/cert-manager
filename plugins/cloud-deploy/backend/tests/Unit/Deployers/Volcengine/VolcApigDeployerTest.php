<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcApigDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

function volcApigDeployerWith(callable $clientFactory): VolcApigDeployer
{
    return new class($clientFactory) extends VolcApigDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcApigCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 APIG：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcApigDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-beijing'])->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('apig');
});

test('bind：ListCustomDomains exact 定位 → GetCustomDomain 读协议 → UpdateCustomDomain 含 HTTPS + 新证书', function () {
    $update = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$update) {
        if ($action === 'ListCustomDomains') {
            return ['Items' => [
                ['Id' => 'dom-1', 'Domain' => 'api.example.com', 'Status' => 'Running'],
                ['Id' => 'dom-2', 'Domain' => 'other.example.com', 'Status' => 'Running'],
            ]];
        }
        if ($action === 'GetCustomDomain') {
            return ['CustomDomain' => ['Protocol' => ['HTTP']]];
        }
        if ($action === 'UpdateCustomDomain') {
            $update = $body;
        }

        return [];
    });

    $deployer = volcApigDeployerWith(fn (string $kind) => $kind === 'apig' ? $client : new stdClass);
    $deployer->bind('cert-9', volcApigCreds(), ['region' => 'cn-beijing', 'domain' => 'api.example.com']);

    // 仅 exact 匹配 dom-1；Protocol 补 HTTPS；CertificateId 设新证书
    expect($update['Id'])->toBe('dom-1');
    expect($update['Protocol'])->toBe(['HTTP', 'HTTPS']);
    expect($update['CertificateId'])->toBe('cert-9');
});

test('过渡态域名（Creating 等）被过滤，不参与匹配', function () {
    $listed = false;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action) use (&$listed) {
        if ($action === 'ListCustomDomains') {
            $listed = true;

            return ['Items' => [['Id' => 'dom-x', 'Domain' => 'api.example.com', 'Status' => 'Creating']]];
        }

        return [];
    });

    $deployer = volcApigDeployerWith(fn () => $client);
    // Creating 状态被过滤 → 找不到 → 业务错误
    expect(fn () => $deployer->bind('c', volcApigCreds(), ['region' => 'cn-beijing', 'domain' => 'api.example.com']))
        ->toThrow(RuntimeException::class, 'api.example.com');
    expect($listed)->toBeTrue();
});

test('未找到自定义域名抛业务错误', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn(['Items' => []]);
    $deployer = volcApigDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('c', volcApigCreds(), ['region' => 'cn-beijing', 'domain' => 'none.example.com']))
        ->toThrow(RuntimeException::class, 'none.example.com');
});

test('缺 region / domain 抛业务错误', function () {
    $deployer = volcApigDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcApigCreds(), ['domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', volcApigCreds(), ['region' => 'cn-beijing']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('DomainErr', 'update failed'));

    $deployer = volcApigDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['region' => 'cn-beijing', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DomainErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

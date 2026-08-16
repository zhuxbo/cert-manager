<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApiException;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApigDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

function byteplusApigDeployerWith(callable $clientFactory): BytePlusApigDeployer
{
    return new class($clientFactory) extends BytePlusApigDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function byteplusApigCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus APIG：证书服务型（storeKind=byteplus_certcenter）', function () {
    $deployer = new BytePlusApigDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_certcenter');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('apig');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('domain_match_pattern');
});

test('bind wildcard 更新所有单层匹配域名', function () {
    $updated = [];
    $apig = Mockery::mock(BytePlusRestClient::class);
    $apig->shouldReceive('openApi')->andReturnUsing(function ($method, $action, $version, $query, $body) use (&$updated) {
        if ($action === 'ListCustomDomains') {
            return (object) ['Items' => [
                (object) ['Id' => 'd-1', 'Domain' => 'a.example.com', 'Status' => 'Running'],
                (object) ['Id' => 'd-2', 'Domain' => 'deep.a.example.com', 'Status' => 'Running'],
            ]];
        }
        if ($action === 'GetCustomDomain') {
            return (object) ['CustomDomain' => (object) ['Protocol' => ['HTTPS']]];
        }
        $updated[] = $body['Id'];

        return new stdClass;
    });
    byteplusApigDeployerWith(fn () => $apig)->bind('cert-1', byteplusApigCreds(), [
        'region' => 'ap-singapore-1', 'domain_match_pattern' => 'wildcard', 'domain' => '*.example.com',
    ]);
    expect($updated)->toBe(['d-1']);
});

test('bind exact：ListCustomDomains 过滤 Domain → GetCustomDomain 取 Protocol → UpdateCustomDomain 设 CertificateId(+HTTPS)', function () {
    $calls = [];
    $apig = Mockery::mock(BytePlusRestClient::class);
    $apig->shouldReceive('openApi')->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body) use (&$calls) {
        $calls[] = compact('action', 'body');
        if ($action === 'ListCustomDomains') {
            return (object) ['Items' => [
                (object) ['Id' => 'd-1', 'Domain' => 'api.example.com', 'Status' => 'Running'],
                (object) ['Id' => 'd-2', 'Domain' => 'other.example.com', 'Status' => 'Running'],
                (object) ['Id' => 'd-3', 'Domain' => 'api.example.com', 'Status' => 'Creating'], // 过渡态，应跳过
            ]];
        }
        if ($action === 'GetCustomDomain') {
            return (object) ['CustomDomain' => (object) ['Protocol' => ['HTTP']]];
        }

        return new stdClass; // UpdateCustomDomain
    });

    $deployer = byteplusApigDeployerWith(fn (string $kind) => $kind === 'apig' ? $apig : new stdClass);
    $deployer->bind('cert-1', byteplusApigCreds(), ['region' => 'ap-singapore-1', 'domain' => 'api.example.com']);

    // 只命中 d-1（d-2 域名不符、d-3 Creating 跳过）
    $update = collect($calls)->firstWhere('action', 'UpdateCustomDomain');
    expect($update)->not->toBeNull();
    expect($update['body']['Id'])->toBe('d-1');
    expect($update['body']['CertificateId'])->toBe('cert-1');
    // Protocol 原为 [HTTP]，UpdateCustomDomain 须补 HTTPS
    expect($update['body']['Protocol'])->toContain('HTTP')->toContain('HTTPS');
    // 只有一次 Update（d-3 被跳过）
    expect(collect($calls)->where('action', 'UpdateCustomDomain'))->toHaveCount(1);
});

test('bind 已含 HTTPS 的 Protocol 不重复追加', function () {
    $update = null;
    $apig = Mockery::mock(BytePlusRestClient::class);
    $apig->shouldReceive('openApi')->andReturnUsing(function ($m, $action, $v, $q, $body) use (&$update) {
        if ($action === 'ListCustomDomains') {
            return (object) ['Items' => [(object) ['Id' => 'd-1', 'Domain' => 'api.example.com', 'Status' => 'Running']]];
        }
        if ($action === 'GetCustomDomain') {
            return (object) ['CustomDomain' => (object) ['Protocol' => ['HTTP', 'HTTPS']]];
        }
        $update = $body;

        return new stdClass;
    });

    $deployer = byteplusApigDeployerWith(fn () => $apig);
    $deployer->bind('cert-1', byteplusApigCreds(), ['region' => 'ap-singapore-1', 'domain' => 'api.example.com']);

    expect($update['Protocol'])->toBe(['HTTP', 'HTTPS']); // 不重复
});

test('bind 未找到匹配域名抛业务错误', function () {
    $apig = Mockery::mock(BytePlusRestClient::class);
    $apig->shouldReceive('openApi')->andReturn((object) ['Items' => [
        (object) ['Id' => 'd-9', 'Domain' => 'nomatch.example.com', 'Status' => 'Running'],
    ]]);

    $deployer = byteplusApigDeployerWith(fn () => $apig);
    expect(fn () => $deployer->bind('cert-1', byteplusApigCreds(), ['region' => 'ap-singapore-1', 'domain' => 'api.example.com']))
        ->toThrow(RuntimeException::class, 'api.example.com');
});

test('缺 region / domain 抛业务错误', function () {
    $deployer = byteplusApigDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', byteplusApigCreds(), ['domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', byteplusApigCreds(), ['region' => 'r']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 BytePlusApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $apig = Mockery::mock(BytePlusRestClient::class);
    $apig->shouldReceive('openApi')->andThrow(
        new BytePlusApiException('AccessDenied', 'denied'),
    );

    $deployer = byteplusApigDeployerWith(fn () => $apig);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['region' => 'r', 'domain' => 'd']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AccessDenied');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

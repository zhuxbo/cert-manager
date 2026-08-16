<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcCdnDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cdn）。uploader 经 certUploader 复用 makeClient('cdn')。 */
function volcCdnDeployerWith(callable $clientFactory): VolcCdnDeployer
{
    return new class($clientFactory) extends VolcCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function volcCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 CDN：证书服务型（usesRemoteCertStore + storeKind volc_cdn）', function () {
    $deployer = new VolcCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('volc_cdn');
    expect($deployer->provider())->toBe('volcengine');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('火山引擎 CDN');
});

test('uploader.upload 调 AddCertificate 返回 CertId（Source=volc_cert_center、完整链）', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')
        ->once()
        ->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
            $args = compact('action', 'version', 'body');

            return ['CertId' => 'volc-cert-001'];
        });

    $deployer = volcCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', volcCreds() + ['project_name' => 'project-a']);

    expect($id)->toBe('volc-cert-001');
    expect($args['action'])->toBe('AddCertificate');
    expect($args['version'])->toBe('2021-03-01');
    expect($args['body']['Source'])->toBe('volc_cert_center');
    expect($args['body']['Certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['body']['PrivateKey'])->toBe('KEYPEM');
    expect($args['body']['Desc'])->toStartWith('clouddeploy_');
    expect($args['body']['Project'])->toBe('project-a');
});

test('upload 未返回 CertId 抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn([]);

    $deployer = volcCdnDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', volcCreds()))
        ->toThrow(RuntimeException::class, 'CertId');
});

test('bind 调 BatchDeployCert 把 CertId + Domain 传给资源', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')
        ->once()
        ->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
            $args = compact('action', 'version', 'body');

            return [];
        });

    $deployer = volcCdnDeployerWith(fn () => $client);
    $deployer->bind('volc-cert-001', volcCreds(), ['domain' => 'cdn.example.com']);

    expect($args['action'])->toBe('BatchDeployCert');
    expect($args['version'])->toBe('2021-03-01');
    expect($args['body'])->toBe(['Domain' => 'cdn.example.com', 'CertId' => 'volc-cert-001']);
});

test('wildcard 按 Project 分页列举在线域名并仅绑定单层匹配项', function () {
    $actions = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$actions) {
        $actions[] = compact('action', 'version', 'body');
        if ($action === 'ListCdnDomains') {
            return ['Data' => [
                ['Domain' => 'a.example.com'],
                ['Domain' => 'deep.a.example.com'],
                ['Domain' => 'other.test'],
            ]];
        }

        return [];
    });

    $deployer = volcCdnDeployerWith(fn () => $client);
    $deployer->bind('cert-1', volcCreds() + ['project_name' => 'project-a'], [
        'domain_match_pattern' => 'wildcard',
        'domain' => '*.example.com',
    ]);

    expect($actions[0]['action'])->toBe('ListCdnDomains');
    expect($actions[0]['body'])->toMatchArray(['Project' => 'project-a', 'Domain' => 'example.com', 'Status' => 'online', 'PageNum' => 1, 'PageSize' => 100]);
    expect($actions)->toHaveCount(2);
    expect($actions[1]['body'])->toBe(['Domain' => 'a.example.com', 'CertId' => 'cert-1']);
});

test('certsan 使用 DescribeCertConfig 返回的可关联域名批量部署', function () {
    $bound = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$bound) {
        if ($action === 'DescribeCertConfig') {
            return [
                'CertNotConfig' => [['Domain' => 'a.example.com']],
                'OtherCertConfig' => [['Domain' => 'b.example.com']],
                'SpecifiedCertConfig' => [['Domain' => 'same.example.com']],
            ];
        }
        $bound[] = $body['Domain'];

        return [];
    });

    $deployer = volcCdnDeployerWith(fn () => $client);
    $deployer->bind(['remote_cert_id' => 'cert-1', 'cert' => 'LEAF', 'chain' => 'CHAIN'], volcCreds(), [
        'domain_match_pattern' => 'certsan',
    ]);

    expect($bound)->toBe(['a.example.com', 'b.example.com']);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = volcCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('InvalidDomain', 'domain not found'));

    $deployer = volcCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidDomain')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类异常时脱敏只暴露类名（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect open.volcengineapi.com with AK-LEAK-9999',
    ));

    $deployer = volcCdnDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'secret_access_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999');
        expect($e->getMessage())->toContain('火山引擎调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

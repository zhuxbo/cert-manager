<?php

use Plugins\CloudDeploy\Deployers\Ksyun\KsyunApiException;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunCdnDeployer;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock。
 */
function ksyunCdnDeployerWith(callable $clientFactory): KsyunCdnDeployer
{
    return new class($clientFactory) extends KsyunCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ksyunCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function ksyunCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('金山云 CDN：内联型（usesRemoteCertStore=false + certUploader=null + 元信息）', function () {
    $deployer = new KsyunCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('ksyun');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('金山云 CDN');
});

test('bind：exact 匹配域名后逐个 ConfigCertificate（DomainIds + Enable=on + cert/key）', function () {
    $configCalls = [];
    $client = Mockery::mock(KsyunRestClient::class);
    // GetCdnDomains 单页返回：匹配 + 不匹配 + 被忽略状态
    $client->shouldReceive('get')
        ->once()
        ->andReturnUsing(function (string $path, array $params) {
            expect($path)->toBe('/2019-06-01/GetCdnDomains');
            expect($params['Action'])->toBe('GetCdnDomains');
            expect($params['Version'])->toBe('2019-06-01');

            return ['Domains' => [
                ['DomainId' => 'd-1', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'online'],
                ['DomainId' => 'd-2', 'DomainName' => 'other.example.com', 'DomainStatus' => 'online'],
                ['DomainId' => 'd-3', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'offline'], // 跳过
            ]];
        });
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $params) use (&$configCalls) {
            $configCalls[] = compact('path', 'params');

            return ['CertificateId' => 'c-1'];
        });

    $deployer = ksyunCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $deployer->bind(ksyunCertRef(), ksyunCreds(), ['domain' => 'cdn.example.com']);

    expect($configCalls)->toHaveCount(1);
    expect($configCalls[0]['path'])->toBe('/2016-09-01/cert/ConfigCertificate');
    $p = $configCalls[0]['params'];
    expect($p['Action'])->toBe('ConfigCertificate');
    expect($p['Version'])->toBe('2016-09-01');
    expect($p['Enable'])->toBe('on');
    expect($p['DomainIds'])->toBe('d-1');
    expect($p['CertificateName'])->toStartWith('clouddeploy_');
    // 证书本体 + 中间证书拼完整链
    expect($p['ServerCertificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($p['PrivateKey'])->toBe('KEYPEM');
});

test('bind：多个匹配域名各 ConfigCertificate 一次', function () {
    $count = 0;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['Domains' => [
        ['DomainId' => 'd-1', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'online'],
        ['DomainId' => 'd-9', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'online'],
    ]]);
    $client->shouldReceive('post')->twice()->andReturnUsing(function () use (&$count) {
        $count++;

        return [];
    });

    $deployer = ksyunCdnDeployerWith(fn () => $client);
    $deployer->bind(ksyunCertRef(), ksyunCreds(), ['domain' => 'cdn.example.com']);

    expect($count)->toBe(2);
});

test('bind：分页拉取（满页继续翻页，PageNumber 递增）', function () {
    $pages = [];
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')
        ->twice()
        ->andReturnUsing(function (string $path, array $params) use (&$pages) {
            $pages[] = $params['PageNumber'];
            // 第一页满 100 条（无匹配）→ 继续；第二页不满 → 停
            if ($params['PageNumber'] === '1') {
                $domains = [];
                for ($i = 0; $i < 100; $i++) {
                    $domains[] = ['DomainId' => "x-$i", 'DomainName' => 'nomatch.example.com', 'DomainStatus' => 'online'];
                }

                return ['Domains' => $domains];
            }

            return ['Domains' => [['DomainId' => 'd-1', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'online']]];
        });
    $client->shouldReceive('post')->once()->andReturn([]);

    $deployer = ksyunCdnDeployerWith(fn () => $client);
    $deployer->bind(ksyunCertRef(), ksyunCreds(), ['domain' => 'cdn.example.com']);

    expect($pages)->toBe(['1', '2']);
});

test('bind：project_id 选填透传到 GetCdnDomains', function () {
    $captured = null;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $params) use (&$captured) {
        $captured = $params;

        return ['Domains' => [['DomainId' => 'd-1', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'online']]];
    });
    $client->shouldReceive('post')->once()->andReturn([]);

    $deployer = ksyunCdnDeployerWith(fn () => $client);
    $deployer->bind(ksyunCertRef(), ksyunCreds(), ['domain' => 'cdn.example.com', 'project_id' => '12345']);

    expect($captured['ProjectId'])->toBe('12345');
});

test('bind：未填 project_id 时不传 ProjectId 键', function () {
    $captured = null;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $params) use (&$captured) {
        $captured = $params;

        return ['Domains' => [['DomainId' => 'd-1', 'DomainName' => 'cdn.example.com', 'DomainStatus' => 'online']]];
    });
    $client->shouldReceive('post')->once()->andReturn([]);

    $deployer = ksyunCdnDeployerWith(fn () => $client);
    $deployer->bind(ksyunCertRef(), ksyunCreds(), ['domain' => 'cdn.example.com']);

    expect($captured)->not->toHaveKey('ProjectId');
});

test('bind：无匹配域名 → 业务失败（脱敏后含可读文案，不调 ConfigCertificate）', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')->once()->andReturn(['Domains' => [
        ['DomainId' => 'd-2', 'DomainName' => 'other.example.com', 'DomainStatus' => 'online'],
    ]]);
    $client->shouldReceive('post')->never();

    $deployer = ksyunCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind(ksyunCertRef(), ksyunCreds(), ['domain' => 'cdn.example.com']))
        ->toThrow(RuntimeException::class, '未找到匹配');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = ksyunCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(ksyunCertRef(), ksyunCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 KsyunApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')->andThrow(new KsyunApiException('InvalidParam', 'domain not found'));

    $deployer = ksyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind(ksyunCertRef(), ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidParam')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('get')->andThrow(new RuntimeException(
        'cURL error 7: Failed to connect https://cdn.api.ksyun.com/?Accesskey=AK-LEAK&Signature=deadbeef',
    ));

    $deployer = ksyunCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind(ksyunCertRef(), ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('金山云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('cdn.api.ksyun.com');
        expect($e->getPrevious())->toBeNull();
    }
});

<?php

use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerApiException;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerCdnDeployer;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝（按 $kind 返回 mock）+ sleep no-op（轮询不真等）。
 * uploader 经 certUploader() 复用同一 makeClient('cdn')，故 mock cdn 即覆盖上传 + 绑定路径。
 */
function zenlayerCdnDeployerWith(callable $clientFactory): ZenlayerCdnDeployer
{
    return new class($clientFactory) extends ZenlayerCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function sleep(int $seconds): void {}
    };
}

function zenlayerCdnCreds(): array
{
    return ['access_key_id' => 'AK', 'access_key_password' => 'PWD'];
}

test('Zenlayer CDN：证书服务型（usesRemoteCertStore + storeKind zenlayer_cdn + 元信息）', function () {
    $deployer = new ZenlayerCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('zenlayer_cdn');
    expect($deployer->provider())->toBe('zenlayer');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('Zenlayer CDN');
});

test('uploader.upload 调 CreateCertificate 返回 certificateId（content=完整链, key=私钥, 透传 resourceGroupId）', function () {
    $captured = null;
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')
        ->once()
        ->andReturnUsing(function (string $action, array $body) use (&$captured) {
            $captured = compact('action', 'body');

            return ['certificateId' => 'cert-xyz'];
        });

    $deployer = zenlayerCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $creds = zenlayerCdnCreds() + ['resource_group_id' => 'rg-1'];
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', $creds);

    expect($id)->toBe('cert-xyz');
    expect($captured['action'])->toBe('CreateCertificate');
    expect($captured['body']['certificateLabel'])->toStartWith('clouddeploy_');
    expect($captured['body']['certificateContent'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['certificateKey'])->toBe('KEYPEM');
    expect($captured['body']['resourceGroupId'])->toBe('rg-1');
});

test('bind：exact 匹配域名 → 未绑定时 ModifyDomainCertificate + 轮询 DEPLOYED', function () {
    $calls = [];
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $action, array $body) use (&$calls) {
        $calls[] = compact('action', 'body');
        if ($action === 'DescribeDomains' && ($body['domainStatus'] ?? null) === 'ENABLED') {
            return ['dataSet' => [
                ['domainId' => 'd-1', 'domainName' => 'cdn.example.com'],
                ['domainId' => 'd-2', 'domainName' => 'other.example.com'],
            ]];
        }
        if ($action === 'DescribeDomainCertificate') {
            return ['certificate' => ['certificateId' => 'old-cert']]; // 非目标证书 → 需修改
        }
        if ($action === 'ModifyDomainCertificate') {
            return [];
        }

        // 轮询 DescribeDomains（带 domainIds）
        return ['dataSet' => [['domainId' => 'd-1', 'configStatus' => 'DEPLOYED']]];
    });

    $deployer = zenlayerCdnDeployerWith(fn () => $client);
    $deployer->bind('cert-NEW', zenlayerCdnCreds(), ['domain' => 'cdn.example.com']);

    $modify = collect($calls)->firstWhere('action', 'ModifyDomainCertificate');
    expect($modify)->not->toBeNull();
    expect($modify['body']['domainId'])->toBe('d-1');
    expect($modify['body']['certificateId'])->toBe('cert-NEW');
    // 只对匹配域名 d-1 操作（d-2 不匹配）
    $modifyCount = collect($calls)->where('action', 'ModifyDomainCertificate')->count();
    expect($modifyCount)->toBe(1);
});

test('bind：域名已绑定该证书 → 跳过 ModifyDomainCertificate', function () {
    $calls = [];
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $action, array $body) use (&$calls) {
        $calls[] = $action;
        if ($action === 'DescribeDomains') {
            return ['dataSet' => [['domainId' => 'd-1', 'domainName' => 'cdn.example.com']]];
        }
        if ($action === 'DescribeDomainCertificate') {
            return ['certificate' => ['certificateId' => 'cert-SAME']]; // 已是目标证书
        }

        return [];
    });

    $deployer = zenlayerCdnDeployerWith(fn () => $client);
    $deployer->bind('cert-SAME', zenlayerCdnCreds(), ['domain' => 'cdn.example.com']);

    expect($calls)->not->toContain('ModifyDomainCertificate');
});

test('bind：无匹配域名 → 业务失败', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturn(['dataSet' => [['domainId' => 'd-2', 'domainName' => 'other.example.com']]]);

    $deployer = zenlayerCdnDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('cert-NEW', zenlayerCdnCreds(), ['domain' => 'cdn.example.com']))
        ->toThrow(RuntimeException::class, '未找到匹配');
});

test('bind：轮询返回 FAILED → 业务失败', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $action) {
        if ($action === 'DescribeDomains') {
            // 第一次（找域名）+ 轮询都走这里；用 configStatus 区分：找域名时无 configStatus
            static $first = true;
            if ($first) {
                $first = false;

                return ['dataSet' => [['domainId' => 'd-1', 'domainName' => 'cdn.example.com']]];
            }

            return ['dataSet' => [['domainId' => 'd-1', 'configStatus' => 'FAILED']]];
        }
        if ($action === 'DescribeDomainCertificate') {
            return ['certificate' => ['certificateId' => 'old']];
        }

        return [];
    });

    $deployer = zenlayerCdnDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('cert-NEW', zenlayerCdnCreds(), ['domain' => 'cdn.example.com']))
        ->toThrow(RuntimeException::class, '部署失败');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = zenlayerCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', zenlayerCdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 ZenlayerApiException 时脱敏重抛（含错误码、无 AK/PWD、不挂 previous）', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andThrow(new ZenlayerApiException('AUTH_FAILED', 'invalid signature'));

    $deployer = zenlayerCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_password' => 'PWD-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AUTH_FAILED')->toContain('invalid signature');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('PWD-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('PWD-SECRET-ABC');
    }
});

test('bind SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andThrow(new RuntimeException('cURL error 7: connect https://console.zenlayer.com/api/v2/cdn with AK-LEAK'));

    $deployer = zenlayerCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-LEAK', 'access_key_password' => 'PWD'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Zenlayer 调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('console.zenlayer.com');
        expect($e->getPrevious())->toBeNull();
    }
});

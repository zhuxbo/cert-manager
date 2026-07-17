<?php

use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUs3Deployer;
use Tests\TestCase;

uses(TestCase::class);

/** region-aware 注入缝：mock 工厂收 (kind, credentials, region)。 */
function ucloudUs3DeployerWith(callable $clientFactory): UcloudUs3Deployer
{
    return new class($clientFactory) extends UcloudUs3Deployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

const US3_CREDS = ['public_key' => 'PUB', 'private_key' => 'PRIV', 'project_id' => ''];

test('US3 走证书服务（storeKind=ucloud_ussl，全局不分 region）', function () {
    $deployer = new UcloudUs3Deployer;
    expect($deployer->provider())->toBe('ucloud');
    expect($deployer->product())->toBe('us3');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-bj2'])->storeKind())->toBe('ucloud_ussl');
});

test('bind 调 AddUFileSSLCert（bucket/domain/certName/USSLId=数字 certId）', function () {
    $captured = null;
    $regionSeen = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('addUFileSSLCert')
        ->once()
        ->andReturnUsing(function (string $bucket, string $domain, string $certName, string $usslId) use (&$captured) {
            $captured = compact('bucket', 'domain', 'certName', 'usslId');
        });

    $deployer = ucloudUs3DeployerWith(function (string $kind, array $cred, string $region) use ($client, &$regionSeen) {
        $regionSeen = $region;

        return $client;
    });
    $deployer->bind('55555|clouddeploy_9', US3_CREDS, ['region' => 'cn-bj2', 'bucket' => 'mybucket', 'domain' => 's3.example.com']);

    expect($captured)->toBe([
        'bucket' => 'mybucket',
        'domain' => 's3.example.com',
        'certName' => 'clouddeploy_9',
        'usslId' => '55555',
    ]);
    // bind 的 client 携带 config.region
    expect($regionSeen)->toBe('cn-bj2');
});

test('uploader 用 region-less client（USSL 全局）', function () {
    $regionSeen = 'UNSET';
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('uploadNormalCertificate')->andReturn(123);

    $deployer = ucloudUs3DeployerWith(function (string $kind, array $cred, string $region) use ($client, &$regionSeen) {
        $regionSeen = $region;

        return $client;
    });
    // certUploader 即便带 region config，USSL 上传仍走 region-less client
    $deployer->certUploader(['region' => 'cn-bj2'])->upload('C', 'K', 'CH', US3_CREDS);

    expect($regionSeen)->toBe('');
});

test('缺 bucket / domain / region 配置抛业务错误', function () {
    $deployer = ucloudUs3DeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1|n', US3_CREDS, ['bucket' => 'b', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('1|n', US3_CREDS, ['region' => 'r', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
});

test('bind SDK 抛 UcloudApiException 脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('addUFileSSLCert')->andThrow(new UcloudApiException('400', 'bucket not exist'));

    $deployer = ucloudUs3DeployerWith(fn () => $client);

    try {
        $deployer->bind('1|n', ['public_key' => 'PUB', 'private_key' => 'PRIV-LEAK-US3'], ['region' => 'cn-bj2', 'bucket' => 'b', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('bucket not exist');
        expect($e->getMessage())->not->toContain('PRIV-LEAK-US3');
        expect($e->getPrevious())->toBeNull();
    }
});

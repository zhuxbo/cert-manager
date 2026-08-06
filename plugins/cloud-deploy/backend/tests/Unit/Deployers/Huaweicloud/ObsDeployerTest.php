<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweiObsClient;
use Plugins\CloudDeploy\Deployers\Huaweicloud\ObsDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

// OBS host 由 bucket 派生：stub 策略放行公网 host，注入场景由授权测试覆盖
beforeEach(function () {
    app()->instance(OutboundDestinationPolicy::class, new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
    ));
});

/** makeClient 4 参（kind/credentials/region/bucket）。obs（绑定，HMAC-SHA1）/ scm（上传，HMAC-SHA256）。 */
function hwObsDeployerWith(callable $clientFactory): ObsDeployer
{
    return new class($clientFactory) extends ObsDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $bucket = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $bucket);
        }
    };
}

function hwObsCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwObsConfig(): array
{
    return ['region' => 'cn-north-4', 'bucket' => 'mybucket', 'domain' => 'static.example.com'];
}

test('华为云 OBS：证书服务型 + storeKind huawei_scm + schema(region+bucket+domain)', function () {
    $deployer = new ObsDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('huawei_scm');
    expect($deployer->product())->toBe('obs');
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('bucket')->toContain('domain');
});

test('uploader 走 SCM import（OBS 证书托管到 SCM）', function () {
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andReturn(['certificate_id' => 'scm-obs-1']);

    $deployer = hwObsDeployerWith(fn (string $kind) => $kind === 'scm' ? $client : new stdClass);
    expect($deployer->certUploader()->upload('C', 'K', 'CH', hwObsCreds()))->toBe('scm-obs-1');
});

test('bind：PutBucketCustomDomain 用 SCM certificate_id（virtual-host bucket + domain）', function () {
    $captured = null;
    $capturedRegion = null;
    $capturedBucket = null;
    $obs = Mockery::mock(HuaweiObsClient::class);
    $obs->shouldReceive('putBucketCustomDomain')
        ->once()
        ->andReturnUsing(function (string $domain, string $name, string $certId) use (&$captured) {
            $captured = compact('domain', 'name', 'certId');

            return ['status' => 200];
        });

    $deployer = hwObsDeployerWith(function (string $kind, array $creds, string $region, string $bucket) use ($obs, &$capturedRegion, &$capturedBucket) {
        $capturedRegion = $region;
        $capturedBucket = $bucket;

        return $obs;
    });

    $deployer->bind('scm-obs-99', hwObsCreds(), hwObsConfig());

    expect($captured['domain'])->toBe('static.example.com');
    expect($captured['certId'])->toBe('scm-obs-99');
    expect($captured['name'])->toStartWith('clouddeploy_');
    expect($capturedRegion)->toBe('cn-north-4');
    expect($capturedBucket)->toBe('mybucket');
});

test('缺 bucket 配置抛业务错误', function () {
    $deployer = hwObsDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('scm-1', hwObsCreds(), ['region' => 'cn-north-4', 'domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = hwObsDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('scm-1', hwObsCreds(), ['region' => 'cn-north-4', 'bucket' => 'b']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（无 AK/SK、不挂 previous）', function () {
    $obs = Mockery::mock(HuaweiObsClient::class);
    $obs->shouldReceive('putBucketCustomDomain')->andThrow(new HuaweicloudApiException('NoSuchBucket', 'bucket not found'));

    $deployer = hwObsDeployerWith(fn () => $obs);

    try {
        $deployer->bind('scm-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], hwObsConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NoSuchBucket');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('OBS bucket 含 URL 分隔符时即使目标解析为公网也被拒绝', function () {
    $deployer = new ObsDeployer;
    $method = (new ReflectionClass(ObsDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 'obs', hwObsCreds(), 'cn-north-4', 'public.example:443/path'))
        ->toThrow(OutboundDestinationException::class);
});

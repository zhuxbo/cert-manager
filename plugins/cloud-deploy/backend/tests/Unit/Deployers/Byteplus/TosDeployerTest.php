<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApiException;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusTosDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient（4 参带 region + bucket）注入缝，按 $kind 返回 mock（certcenter / tos）。
 * uploader 经 certUploader() 复用 makeClient('certcenter')，mock certcenter 即覆盖上传。
 */
function byteplusTosDeployerWith(callable $clientFactory): BytePlusTosDeployer
{
    return new class($clientFactory) extends BytePlusTosDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $bucket = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $bucket);
        }
    };
}

function byteplusTosCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus TOS：证书服务型（证书走证书中心 storeKind=byteplus_certcenter）', function () {
    $deployer = new BytePlusTosDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_certcenter');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('tos');
});

test('uploader 走证书中心 UploadCertificate 返回 CertId', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturnUsing(function ($m, $action, $v, $q, $body) use (&$captured) {
        $captured = compact('action', 'body');

        return (object) ['InstanceId' => 'tos-cc-1'];
    });

    $deployer = byteplusTosDeployerWith(fn (string $kind) => $kind === 'certcenter' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', byteplusTosCreds());

    expect($ref)->toBe('tos-cc-1');
    expect($captured['action'])->toBe('UploadCertificate');
});

test('bind 调 TOS PutBucketCustomDomain（CustomDomainRule:{Domain,CertId}），host 含 bucket+region', function () {
    $captured = null;
    $seenRegion = null;
    $seenBucket = null;
    $tos = Mockery::mock(BytePlusRestClient::class);
    $tos->shouldReceive('tosPut')
        ->once()
        ->andReturnUsing(function (string $path, ?array $body) use (&$captured) {
            $captured = compact('path', 'body');

            return new stdClass;
        });

    $deployer = byteplusTosDeployerWith(function (string $kind, array $creds, string $region, string $bucket) use (&$seenRegion, &$seenBucket, $tos) {
        if ($kind === 'tos') {
            $seenRegion = $region;
            $seenBucket = $bucket;

            return $tos;
        }

        return new stdClass;
    });
    $deployer->bind('cert-1', byteplusTosCreds(), [
        'region' => 'ap-singapore-1', 'bucket' => 'mybucket', 'domain' => 'cdn.example.com',
    ]);

    expect($captured['path'])->toBe('/?customdomain');
    expect($captured['body'])->toBe([
        'CustomDomainRule' => ['Domain' => 'cdn.example.com', 'CertId' => 'cert-1'],
    ]);
    // makeClient('tos') 收到 region + bucket（用于构造 host {bucket}.tos-{region}.bytepluses.com）
    expect($seenRegion)->toBe('ap-singapore-1');
    expect($seenBucket)->toBe('mybucket');
});

test('缺 region / bucket / domain 抛业务错误', function () {
    $deployer = byteplusTosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', byteplusTosCreds(), ['bucket' => 'b', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', byteplusTosCreds(), ['region' => 'r', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
    expect(fn () => $deployer->bind('c', byteplusTosCreds(), ['region' => 'r', 'bucket' => 'b']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind TOS 抛 BytePlusApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $tos = Mockery::mock(BytePlusRestClient::class);
    $tos->shouldReceive('tosPut')->andThrow(new BytePlusApiException('NoSuchBucket', 'bucket gone'));

    $deployer = byteplusTosDeployerWith(fn (string $kind) => $kind === 'tos' ? $tos : new stdClass);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'region' => 'ap-singapore-1', 'bucket' => 'b', 'domain' => 'd.example.com',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NoSuchBucket');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

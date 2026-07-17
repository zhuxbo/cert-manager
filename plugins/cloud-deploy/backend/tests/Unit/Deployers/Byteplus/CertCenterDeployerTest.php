<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusCertCenterDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient（3 参带 region）注入缝。
 * uploader 经 certUploader() 复用 makeClient('certcenter')，mock 即覆盖上传路径。
 */
function byteplusCertCenterDeployerWith(callable $clientFactory): BytePlusCertCenterDeployer
{
    return new class($clientFactory) extends BytePlusCertCenterDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function byteplusCcCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('BytePlus 证书中心：证书服务型（storeKind=byteplus_certcenter）、bind no-op', function () {
    $deployer = new BytePlusCertCenterDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('byteplus_certcenter');
    expect($deployer->provider())->toBe('byteplus');
    expect($deployer->product())->toBe('certcenter');

    // bind 是 no-op：不应触碰任何 client。
    $deployer->bind('cert-1', byteplusCcCreds(), []);
    expect(true)->toBeTrue();
});

test('uploader.upload 调 UploadCertificate（CertificateInfo + Repeatable=false）返回 InstanceId', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')
        ->once()
        ->andReturnUsing(function (string $method, string $action, string $version, array $query, ?array $body) use (&$captured) {
            $captured = compact('method', 'action', 'version', 'body');

            return (object) ['InstanceId' => 'cc-inst-001'];
        });

    $deployer = byteplusCertCenterDeployerWith(fn (string $kind) => $kind === 'certcenter' ? $client : new stdClass);
    $ref = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', byteplusCcCreds());

    expect($ref)->toBe('cc-inst-001');
    expect($captured['action'])->toBe('UploadCertificate');
    expect($captured['version'])->toBe('2021-06-01');
    expect($captured['body']['CertificateInfo']['CertificateChain'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['body']['CertificateInfo']['PrivateKey'])->toBe('KEYPEM');
    expect($captured['body']['Repeatable'])->toBeFalse();
});

test('upload 已存在时回落 RepeatId', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturn((object) ['InstanceId' => '', 'RepeatId' => 'cc-repeat-9']);

    $deployer = byteplusCertCenterDeployerWith(fn () => $client);
    expect($deployer->certUploader()->upload('C', 'K', 'CH', byteplusCcCreds()))->toBe('cc-repeat-9');
});

test('upload 带 project_name 时附 ProjectName', function () {
    $captured = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturnUsing(function ($m, $a, $v, $q, $body) use (&$captured) {
        $captured = $body;

        return (object) ['InstanceId' => 'x'];
    });

    $deployer = byteplusCertCenterDeployerWith(fn () => $client);
    $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'secret_access_key' => 'SK', 'project_name' => 'proj-1']);

    expect($captured['ProjectName'])->toBe('proj-1');
});

test('upload InstanceId/RepeatId 均空抛明确异常', function () {
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturn(new stdClass);

    $deployer = byteplusCertCenterDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', byteplusCcCreds()))
        ->toThrow(RuntimeException::class, 'InstanceId/RepeatId');
});

test('certUploader 透传 config.region 给 makeClient（自定义 region 覆盖默认）', function () {
    $seenRegion = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturn((object) ['InstanceId' => 'x']);

    $deployer = byteplusCertCenterDeployerWith(function (string $kind, array $creds, string $region) use (&$seenRegion, $client) {
        $seenRegion = $region;

        return $client;
    });
    $deployer->certUploader(['region' => 'cn-beijing'])->upload('C', 'K', 'CH', byteplusCcCreds());

    expect($seenRegion)->toBe('cn-beijing');
});

test('certUploader 缺 region 用默认 ap-singapore-1', function () {
    $seenRegion = null;
    $client = Mockery::mock(BytePlusRestClient::class);
    $client->shouldReceive('openApi')->andReturn((object) ['InstanceId' => 'x']);

    $deployer = byteplusCertCenterDeployerWith(function (string $kind, array $creds, string $region) use (&$seenRegion, $client) {
        $seenRegion = $region;

        return $client;
    });
    $deployer->certUploader([])->upload('C', 'K', 'CH', byteplusCcCreds());

    expect($seenRegion)->toBe('ap-singapore-1');
});

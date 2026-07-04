<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\ApigDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** makeClient 4 参（kind/credentials/region/projectId）。iam（反查 projectId）/ apig（show + update）。 */
function hwApigDeployerWith(callable $clientFactory): ApigDeployer
{
    return new class($clientFactory) extends ApigDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
        {
            return ($this->factory)($kind, $credentials, $region, $projectId);
        }
    };
}

function hwApigCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function hwApigConfig(): array
{
    return ['region' => 'cn-north-4', 'certificate_id' => 'apig-cert-1'];
}

function hwApigCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('华为云 APIG：内联型（usesRemoteCertStore=false + certUploader=null）+ schema(region+certificate_id)', function () {
    $deployer = new ApigDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->product())->toBe('apig');
    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('certificate_id');
});

test('bind（global 型证书）：IAM 反查 projectId、ShowDetails、UpdateCertificateV2 直灌 PEM', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->with('/v3/projects', ['name' => 'cn-north-4'])->andReturn(['projects' => [['id' => 'proj-1']]]);

    $putCaptured = null;
    $apig = Mockery::mock(HuaweicloudRestClient::class);
    $apig->shouldReceive('get')->with('/v2/proj-1/apigw/certificates/apig-cert-1')->andReturn(['type' => 'global']);
    $apig->shouldReceive('put')->once()->andReturnUsing(function (string $path, ?array $body, array $q = []) use (&$putCaptured) {
        $putCaptured = compact('path', 'body');

        return [];
    });

    $deployer = hwApigDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $apig);
    $deployer->bind(hwApigCertRef(), hwApigCreds(), hwApigConfig());

    expect($putCaptured['path'])->toBe('/v2/proj-1/apigw/certificates/apig-cert-1');
    $b = $putCaptured['body'];
    expect($b['cert_content'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($b['private_key'])->toBe('KEYPEM');
    expect($b['type'])->toBe('global');
    // global 型不带 instance_id
    expect($b)->not->toHaveKey('instance_id');
});

test('bind（instance 型证书）：沿用 instance_id', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => [['id' => 'proj-1']]]);

    $putCaptured = null;
    $apig = Mockery::mock(HuaweicloudRestClient::class);
    $apig->shouldReceive('get')->andReturn(['type' => 'instance', 'instance_id' => 'inst-9']);
    $apig->shouldReceive('put')->andReturnUsing(function (string $path, ?array $body, array $q = []) use (&$putCaptured) {
        $putCaptured = $body;

        return [];
    });

    $deployer = hwApigDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $apig);
    $deployer->bind(hwApigCertRef(), hwApigCreds(), hwApigConfig());

    expect($putCaptured['type'])->toBe('instance');
    expect($putCaptured['instance_id'])->toBe('inst-9');
});

test('缺 region / certificate_id 配置抛业务错误', function () {
    $deployer = hwApigDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(hwApigCertRef(), hwApigCreds(), ['certificate_id' => 'c-1']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind(hwApigCertRef(), hwApigCreds(), ['region' => 'cn-north-4']))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind SDK 抛 HuaweicloudApiException 时脱敏（无 AK/SK、不挂 previous）', function () {
    $iam = Mockery::mock(HuaweicloudRestClient::class);
    $iam->shouldReceive('get')->andReturn(['projects' => [['id' => 'p-1']]]);
    $apig = Mockery::mock(HuaweicloudRestClient::class);
    $apig->shouldReceive('get')->andReturn(['type' => 'global']);
    $apig->shouldReceive('put')->andThrow(new HuaweicloudApiException('APIG.0001', 'certificate invalid'));

    $deployer = hwApigDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : $apig);

    try {
        $deployer->bind(hwApigCertRef(), ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], hwApigConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('APIG.0001');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

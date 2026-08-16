<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcCertCenterDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

function volcCertCenterDeployerWith(callable $clientFactory): VolcCertCenterDeployer
{
    return new class($clientFactory) extends VolcCertCenterDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = 'cn-beijing'): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcCertCenterCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山证书中心：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcCertCenterDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('certcenter');
});

test('uploader.upload 调 ImportCertificate 返回 InstanceId', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->once()->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
        $args = compact('action', 'version', 'body');

        return ['InstanceId' => 'cert-inst-1'];
    });

    $deployer = volcCertCenterDeployerWith(fn (string $kind) => $kind === 'certcenter' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', volcCertCenterCreds() + ['project_name' => 'project-a']);

    expect($id)->toBe('cert-inst-1');
    expect($args['action'])->toBe('ImportCertificate');
    expect($args['version'])->toBe('2024-10-01');
    expect($args['body']['ProjectName'])->toBe('project-a');
    expect($args['body']['CertificateInfo']['CertificateChain'])->toContain('CERTPEM');
});

test('bind 为 no-op（纯上传端点，不调任何 SDK）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->never();

    $deployer = volcCertCenterDeployerWith(fn () => $client);
    // bind 不抛、不调 SDK
    $deployer->bind('cert-inst-1', volcCertCenterCreds(), []);
    expect(true)->toBeTrue();
});

test('upload 未返回 InstanceId/RepeatId 抛明确异常', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn([]);

    $deployer = volcCertCenterDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', volcCertCenterCreds()))
        ->toThrow(RuntimeException::class, 'InstanceId/RepeatId');
});

test('upload SDK 抛异常时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new RuntimeException('connect open.volcengineapi.com AK-LEAK-9999'));

    $deployer = volcCertCenterDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'secret_access_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999');
        expect($e->getMessage())->toContain('火山引擎调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

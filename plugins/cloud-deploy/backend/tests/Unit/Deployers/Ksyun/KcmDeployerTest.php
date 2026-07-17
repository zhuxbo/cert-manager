<?php

use Plugins\CloudDeploy\Deployers\Ksyun\KsyunApiException;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunKcmDeployer;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock（kcm）。
 * uploader 经 certUploader() 复用同一 makeClient('kcm')，故 mock kcm 即覆盖上传路径。
 */
function ksyunKcmDeployerWith(callable $clientFactory): KsyunKcmDeployer
{
    return new class($clientFactory) extends KsyunKcmDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ksyunKcmCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('金山云 KCM：纯上传端点（usesRemoteCertStore + storeKind ksyun_kcm + bind no-op）', function () {
    $deployer = new KsyunKcmDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('ksyun_kcm');
    expect($deployer->provider())->toBe('ksyun');
    expect($deployer->product())->toBe('kcm');
    expect($deployer->configSchema())->toBe([]);

    // bind 是 no-op：不调用任何 SDK（注入会抛异常的 client，bind 仍不触碰它），不抛异常。
    $neverCalled = ksyunKcmDeployerWith(fn () => Mockery::mock()->shouldReceive('post')->never()->getMock());
    $neverCalled->bind('kcm-cert-1', ksyunKcmCreds(), []);
    expect(true)->toBeTrue();
});

test('uploader.upload 调 POST / UploadCertificate 返回 CertID（CertFile=完整链, CertKey=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $params) use (&$captured) {
            $captured = compact('path', 'params');

            return ['Success' => true, 'Ret' => ['CertID' => 'kcm-abc-001', 'CertName' => 'clouddeploy_x']];
        });

    $deployer = ksyunKcmDeployerWith(fn (string $kind) => $kind === 'kcm' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ksyunKcmCreds());

    expect($id)->toBe('kcm-abc-001');
    expect($captured['path'])->toBe('/');
    $p = $captured['params'];
    expect($p['Action'])->toBe('UploadCertificate');
    expect($p['Version'])->toBe('2016-03-04');
    expect($p['CertName'])->toStartWith('clouddeploy_');
    expect($p['CertFile'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($p['CertKey'])->toBe('KEYPEM');
});

test('upload 未返回 CertID 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')->andReturn(['Success' => true, 'Ret' => ['CertName' => 'x']]);

    $deployer = ksyunKcmDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ksyunKcmCreds()))
        ->toThrow(RuntimeException::class, 'CertID');
});

test('upload SDK 抛 KsyunApiException 时脱敏（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')->andThrow(new KsyunApiException('DuplicateCert', '重复的证书文件'));

    $deployer = ksyunKcmDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DuplicateCert')->toContain('重复的证书文件');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('upload SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException('cURL error 7: connect https://kcm.api.ksyun.com/ with AK-LEAK'));

    $deployer = ksyunKcmDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('金山云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('kcm.api.ksyun.com');
        expect($e->getPrevious())->toBeNull();
    }
});

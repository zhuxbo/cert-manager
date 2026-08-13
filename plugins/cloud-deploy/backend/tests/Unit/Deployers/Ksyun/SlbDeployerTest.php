<?php

use Plugins\CloudDeploy\Deployers\Ksyun\KsyunApiException;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunRestClient;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunSlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock（kcm）。
 * uploader（KCM 托管）与 bind（ModifyCertificate）同走 makeClient('kcm')，故 mock kcm 即覆盖全路径。
 */
function ksyunSlbDeployerWith(callable $clientFactory): KsyunSlbDeployer
{
    return new class($clientFactory) extends KsyunSlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ksyunSlbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

function ksyunSlbConfig(): array
{
    return ['region' => 'cn-beijing-6', 'certificate_id' => 'lb-cert-1'];
}

test('金山云 SLB：证书服务型（usesRemoteCertStore + storeKind ksyun_kcm + 元信息 + schema）', function () {
    $deployer = new KsyunSlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    // 上传器与 kcm 端点共用同一存储空间 ksyun_kcm（KCM 托管 region-less）
    expect($deployer->certUploader()->storeKind())->toBe('ksyun_kcm');
    expect($deployer->provider())->toBe('ksyun');
    expect($deployer->product())->toBe('slb');

    $keys = array_column($deployer->configSchema(), 'key');
    expect($keys)->toContain('region')->toContain('certificate_id');
});

test('uploader.upload 托管证书到 KCM 返回 SslCertificateId（同 KCM 上传器）', function () {
    $captured = null;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $params) use (&$captured) {
            $captured = compact('path', 'params');

            return ['Success' => true, 'Ret' => ['CertID' => 'kcm-ssl-001']];
        });

    $deployer = ksyunSlbDeployerWith(fn (string $kind) => $kind === 'kcm' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ksyunSlbCreds());

    expect($id)->toBe('kcm-ssl-001');
    expect($captured['params']['Action'])->toBe('UploadCertificate');
});

test('SLB project_id 进入 KCM 上传请求并隔离 RemoteCertStore 命名空间', function () {
    $captured = null;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $params) use (&$captured) {
        $captured = $params;

        return ['Success' => true, 'Ret' => ['CertID' => 'kcm-project-2']];
    });

    $deployer = ksyunSlbDeployerWith(fn () => $client);
    $uploader = $deployer->certUploader(['project_id' => '67890']);
    expect($uploader->storeKind())->toBe('ksyun_kcm:67890');
    expect($uploader->upload('C', 'K', 'CH', ksyunSlbCreds()))->toBe('kcm-project-2');
    expect($captured['ProjectId'])->toBe('67890');
});

test('bind：ModifyCertificate 把负载均衡证书指向新 KCM SslCertificateId（带 Region + CertificateId）', function () {
    $captured = null;
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, array $params) use (&$captured) {
            $captured = compact('path', 'params');

            return ['Certificate' => ['CertificateId' => 'lb-cert-1']];
        });

    $deployer = ksyunSlbDeployerWith(fn (string $kind) => $kind === 'kcm' ? $client : new stdClass);
    // bind 收 remote_cert_id（= KCM SslCertificateId）
    $deployer->bind('kcm-ssl-001', ksyunSlbCreds(), ksyunSlbConfig());

    expect($captured['path'])->toBe('/');
    $p = $captured['params'];
    expect($p['Action'])->toBe('ModifyCertificate');
    expect($p['Version'])->toBe('2016-03-04');
    expect($p['Region'])->toBe('cn-beijing-6');
    // 偏差修正：把 config.certificate_id 作 CertificateId 下发（定位被替换的负载均衡证书）
    expect($p['CertificateId'])->toBe('lb-cert-1');
    expect($p['SslCertificateId'])->toBe('kcm-ssl-001');
});

test('缺 region 配置抛业务错误', function () {
    $deployer = ksyunSlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('kcm-ssl-001', ksyunSlbCreds(), ['certificate_id' => 'lb-cert-1']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('缺 certificate_id 配置抛业务错误', function () {
    $deployer = ksyunSlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('kcm-ssl-001', ksyunSlbCreds(), ['region' => 'cn-beijing-6']))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind SDK 抛 KsyunApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')->andThrow(new KsyunApiException('CertNotFound', 'certificate not found'));

    $deployer = ksyunSlbDeployerWith(fn () => $client);

    try {
        $deployer->bind('kcm-ssl-001', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ksyunSlbConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('CertNotFound')->toContain('certificate not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('bind SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(KsyunRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException('cURL error 28: timeout https://kcm.api.ksyun.com/?Signature=deadbeef'));

    $deployer = ksyunSlbDeployerWith(fn () => $client);

    try {
        $deployer->bind('kcm-ssl-001', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK'], ksyunSlbConfig());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('金山云调用失败');
        expect($e->getMessage())->not->toContain('kcm.api.ksyun.com');
        expect($e->getPrevious())->toBeNull();
    }
});

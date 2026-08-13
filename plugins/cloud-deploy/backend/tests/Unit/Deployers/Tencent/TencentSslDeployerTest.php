<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUploader;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

test('腾讯云 SSL（仅上传）走证书服务、product=ssl', function () {
    $deployer = new TencentSslDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('ssl');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(TencentSslUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
});

test('纯上传仅暴露可选 endpoint 配置', function () {
    expect(array_column((new TencentSslDeployer)->configSchema(), 'key'))->toBe(['endpoint']);
});

test('bind 为 no-op：上传已由 RemoteCertStore 完成，无后续动作不抛异常', function () {
    $deployer = new class extends TencentSslDeployer
    {
        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass; // bind 不触达 client
        }
    };

    $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], []);
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('SSL uploader 透传可选 ProjectId 并禁止云端重复证书', function () {
    $captured = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->once()->andReturnUsing(function (UploadCertificateRequest $request) use (&$captured) {
        $captured = $request;
        $response = new UploadCertificateResponse;
        $response->deserialize(['CertificateId' => 'cert-project']);

        return $response;
    });
    $deployer = new class($ssl) extends TencentSslDeployer
    {
        public function __construct(private object $client) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return $this->client;
        }
    };

    $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', [
        'secret_id' => 'AK',
        'secret_key' => 'SK',
        'project_id' => 123,
    ]);

    expect($captured->ProjectId)->toBe(123);
    expect($captured->Repeatable)->toBeFalse();
});

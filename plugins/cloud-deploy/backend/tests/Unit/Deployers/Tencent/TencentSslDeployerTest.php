<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUploader;
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

test('纯上传无资源配置：configSchema 为空', function () {
    expect((new TencentSslDeployer)->configSchema())->toBe([]);
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

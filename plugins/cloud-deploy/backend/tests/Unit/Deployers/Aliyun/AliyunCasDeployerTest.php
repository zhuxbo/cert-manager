<?php

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasUploader;
use Tests\TestCase;

uses(TestCase::class);

test('阿里云 CAS（仅上传）走证书服务、product=cas', function () {
    $deployer = new AliyunCasDeployer;
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('cas');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(AliyunCasUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('cas');
});

test('纯上传无资源配置：configSchema 为空', function () {
    expect((new AliyunCasDeployer)->configSchema())->toBe([]);
});

test('bind 为 no-op：上传已由 RemoteCertStore 完成，无后续动作不抛异常', function () {
    $deployer = new class extends AliyunCasDeployer
    {
        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass; // bind 不触达 client
        }
    };

    $deployer->bind('123456-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []);
    expect($deployer->touchedConfigKeys())->toBe([]); // 未读任何 config
});

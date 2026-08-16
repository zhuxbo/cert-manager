<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasUploader;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunProvider;
use Tests\TestCase;

uses(TestCase::class);

test('阿里云 CAS（仅上传）走证书服务、product=cas', function () {
    $deployer = new AliyunCasDeployer;
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('cas');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(AliyunCasUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->certUploader(['region' => 'ap-southeast-1'])->storeKind())->toBe('cas:ap-southeast-1');
});

test('纯上传仅暴露官方 region 配置', function () {
    $schema = collect((new AliyunCasDeployer)->configSchema())->keyBy('key');
    expect($schema)->toHaveKey('region');
    expect($schema['region']['required'])->toBeFalse();
});

test('CAS uploader 把 config.region 传入 client 构造缝', function () {
    $seen = new stdClass;
    $deployer = new class($seen) extends AliyunCasDeployer
    {
        public function __construct(private stdClass $seen) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            $this->seen->region = $credentials['region'] ?? null;

            return Mockery::mock(Cas::class)
                ->shouldReceive('uploadUserCertificate')->andReturn(
                    new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => 1])])
                )->getMock()
                ->shouldReceive('getUserCertificateDetail')->andReturn(
                    new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody(['certIdentifier' => '1-ap-southeast-1'])])
                )->getMock();
        }
    };

    $deployer->certUploader(['region' => 'ap-southeast-1'])->upload('C', 'K', 'CH', [
        'access_key_id' => 'AK', 'access_key_secret' => 'SK',
    ]);
    expect($seen->region)->toBe('ap-southeast-1');
});

test('Aliyun 凭证暴露可选资源组且 CAS 上传透传 resourceGroupId', function () {
    $schema = collect((new AliyunProvider)->credentialSchema())->keyBy('key');
    expect($schema)->toHaveKey('resource_group_id');
    expect($schema['resource_group_id']['required'])->toBeFalse();

    $captured = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')->once()->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => 123])]);
    });
    $cas->shouldReceive('getUserCertificateDetail')->once()->andReturn(
        new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody(['certIdentifier' => '123-cn-hangzhou'])])
    );

    $uploader = new AliyunCasUploader(fn () => $cas);
    $uploader->upload('CERT', 'KEY', 'CHAIN', [
        'access_key_id' => 'AK',
        'access_key_secret' => 'SK',
        'resource_group_id' => 'rg-acfmxazb4ph6aiy',
    ]);

    expect($captured->resourceGroupId)->toBe('rg-acfmxazb4ph6aiy');
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

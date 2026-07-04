<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceResponse;
use TencentCloud\Ssl\V20191205\Models\DescribeHostDeployRecordDetailResponse;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（ssl kind，第三参 region）+ sleep no-op。 */
function tencentSslDeployDeployerWith(callable $clientFactory): TencentSslDeployDeployer
{
    return new class($clientFactory) extends TencentSslDeployDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }

        protected function sleep(int $seconds): void {}
    };
}

function sslDeployUploadResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

function sslDeployDeployResponse(?int $recordId): DeployCertificateInstanceResponse
{
    $resp = new DeployCertificateInstanceResponse;
    $data = ['RequestId' => 'r'];
    if ($recordId !== null) {
        $data['DeployRecordId'] = $recordId;
    }
    $resp->deserialize($data);

    return $resp;
}

function sslDeployRecordDetailResponse(?int $total, int $succeeded = 0, int $failed = 0): DescribeHostDeployRecordDetailResponse
{
    $resp = new DescribeHostDeployRecordDetailResponse;
    $data = ['RequestId' => 'r', 'SuccessTotalCount' => $succeeded, 'FailedTotalCount' => $failed];
    if ($total !== null) {
        $data['TotalCount'] = $total;
    }
    $resp->deserialize($data);

    return $resp;
}

test('腾讯云一键部署（通用）走证书服务（storeKind=tencent_ssl）+ 基本元信息', function () {
    $deployer = new TencentSslDeployDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('ssl-deploy');
    expect($deployer->label())->toBe('腾讯云一键部署（通用）');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('resource_type')->toContain('instance_id_list')->toContain('region');
});

test('ssl-deploy uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => sslDeployUploadResponse('cert-x'));

    $deployer = tencentSslDeployDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-x');
});

test('ssl-deploy bind 用 config 指定的 ResourceType + InstanceIdList（数组）调 DeployCertificateInstance + Status=1', function () {
    $deployReq = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')
        ->once()
        ->andReturnUsing(function (DeployCertificateInstanceRequest $req) use (&$deployReq) {
            $deployReq = $req;

            return sslDeployDeployResponse(7);
        });
    $ssl->shouldReceive('DescribeHostDeployRecordDetail')->once()->andReturn(sslDeployRecordDetailResponse(2, 2, 0));

    $deployer = tencentSslDeployDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'ddos',
        'instance_id_list' => ['inst-1', 'inst-2'],
    ]);

    expect($deployReq->CertificateId)->toBe('cert-x');
    // ResourceType 来自 config（通用兜底）
    expect($deployReq->ResourceType)->toBe('ddos');
    expect($deployReq->InstanceIdList)->toBe(['inst-1', 'inst-2']);
    expect($deployReq->Status)->toBe(1);
});

test('ssl-deploy instance_id_list 支持换行/逗号分隔字符串，归一为去重数组', function () {
    $deployReq = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')
        ->once()
        ->andReturnUsing(function (DeployCertificateInstanceRequest $req) use (&$deployReq) {
            $deployReq = $req;

            return sslDeployDeployResponse(null); // 触发即成功，省轮询
        });
    $ssl->shouldNotReceive('DescribeHostDeployRecordDetail');

    $deployer = tencentSslDeployDeployerWith(fn () => $ssl);
    $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'clb',
        // 混合换行 + 逗号 + 重复 + 空白
        'instance_id_list' => "lb-1\nlb-2, lb-2 ,\nlb-3",
    ]);

    expect($deployReq->InstanceIdList)->toBe(['lb-1', 'lb-2', 'lb-3']);
});

test('ssl-deploy region 透传进 ssl client（按 resourceRegion 实例化）', function () {
    $seenRegion = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')->andReturn(sslDeployDeployResponse(null));

    $deployer = tencentSslDeployDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $ssl) {
        $seenRegion = $region;

        return $ssl;
    });
    $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'clb', 'instance_id_list' => ['lb-1'], 'region' => 'ap-shanghai',
    ]);

    expect($seenRegion)->toBe('ap-shanghai');
});

test('ssl-deploy region 选填：缺省时 client 空 region', function () {
    $seenRegion = 'SENTINEL';
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')->andReturn(sslDeployDeployResponse(null));

    $deployer = tencentSslDeployDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $ssl) {
        $seenRegion = $region;

        return $ssl;
    });
    $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'cdn', 'instance_id_list' => ['example.com'],
    ]);

    expect($seenRegion)->toBe('');
});

test('ssl-deploy 轮询发现失败子任务时抛业务错误', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')->once()->andReturn(sslDeployDeployResponse(5));
    $ssl->shouldReceive('DescribeHostDeployRecordDetail')->once()->andReturn(sslDeployRecordDetailResponse(3, 2, 1));

    $deployer = tencentSslDeployDeployerWith(fn () => $ssl);
    expect(fn () => $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'clb', 'instance_id_list' => ['lb-1', 'lb-2', 'lb-3'],
    ]))->toThrow(RuntimeException::class, '失败子任务');
});

test('ssl-deploy 缺 resource_type 配置抛业务错误', function () {
    $deployer = tencentSslDeployDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'instance_id_list' => ['inst-1'],
    ]))->toThrow(RuntimeException::class, '缺少配置 resource_type');
});

test('ssl-deploy 缺 instance_id_list 配置抛业务错误', function () {
    $deployer = tencentSslDeployDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'clb',
    ]))->toThrow(RuntimeException::class, '缺少配置 instance_id_list');
});

test('ssl-deploy instance_id_list 全空白时也抛缺少配置（归一后为空）', function () {
    $deployer = tencentSslDeployDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-x', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_type' => 'clb', 'instance_id_list' => "  ,\n , ",
    ]))->toThrow(RuntimeException::class, '缺少配置 instance_id_list');
});

test('ssl-deploy bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')
        ->andThrow(new TencentCloudSDKException('FailedOperation', 'deploy instance failed', 'req-1'));

    $deployer = tencentSslDeployDeployerWith(fn () => $ssl);

    try {
        $deployer->bind('cert-x', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'resource_type' => 'clb', 'instance_id_list' => ['lb-1'],
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('deploy instance failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

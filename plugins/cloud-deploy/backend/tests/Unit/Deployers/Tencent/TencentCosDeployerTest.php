<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentCosDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceResponse;
use TencentCloud\Ssl\V20191205\Models\DescribeHostDeployRecordDetailRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeHostDeployRecordDetailResponse;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝（仅 ssl kind），并把 sleep 置 no-op
 * （pollIntervalSeconds 真实为 10s，测试不能真等）。
 */
function tencentCosDeployerWith(callable $clientFactory): TencentCosDeployer
{
    return new class($clientFactory) extends TencentCosDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        // 测试不真实 sleep（否则轮询每次卡 10s）
        protected function sleep(int $seconds): void {}
    };
}

function cosUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

function cosDeployResponse(?int $recordId): DeployCertificateInstanceResponse
{
    $resp = new DeployCertificateInstanceResponse;
    $data = ['RequestId' => 'r'];
    if ($recordId !== null) {
        $data['DeployRecordId'] = $recordId;
    }
    $resp->deserialize($data);

    return $resp;
}

/** 构造部署任务详情响应（仅计数字段，poller 只读这些）。 */
function cosRecordDetailResponse(?int $total, int $succeeded = 0, int $failed = 0): DescribeHostDeployRecordDetailResponse
{
    $resp = new DescribeHostDeployRecordDetailResponse;
    $data = ['RequestId' => 'r', 'SuccessTotalCount' => $succeeded, 'FailedTotalCount' => $failed];
    if ($total !== null) {
        $data['TotalCount'] = $total;
    }
    $resp->deserialize($data);

    return $resp;
}

test('腾讯云 COS 走证书服务（storeKind=tencent_ssl）+ 基本元信息', function () {
    $deployer = new TencentCosDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('cos');
    expect($deployer->label())->toBe('腾讯云 COS');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('region')->toContain('bucket')->toContain('domain');
});

test('COS uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => cosUploadCertResponse('cert-cos'));

    $deployer = tencentCosDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-cos');
});

test('COS bind 调 ssl.DeployCertificateInstance 设 ResourceType=cos + InstanceIdList=region|bucket|domain + Status=1，再轮询至成功', function () {
    $deployReq = null;
    $detailReq = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')
        ->once()
        ->andReturnUsing(function (DeployCertificateInstanceRequest $req) use (&$deployReq) {
            $deployReq = $req;

            return cosDeployResponse(12345);
        });
    $ssl->shouldReceive('DescribeHostDeployRecordDetail')
        ->once()
        ->andReturnUsing(function (DescribeHostDeployRecordDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            // 一次即终态：1 成功 / 0 失败 / 共 1
            return cosRecordDetailResponse(1, 1, 0);
        });

    $deployer = tencentCosDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $deployer->bind('cert-cos', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou',
        'bucket' => 'my-bucket-1250000000',
        'domain' => 'cos.example.com',
    ]);

    expect($deployReq->CertificateId)->toBe('cert-cos');
    expect($deployReq->ResourceType)->toBe('cos');
    // InstanceIdList 三段拼接
    expect($deployReq->InstanceIdList)->toBe(['ap-guangzhou|my-bucket-1250000000|cos.example.com']);
    expect($deployReq->Status)->toBe(1);
    // 轮询用 DeployRecordId（字符串化）
    expect($detailReq->DeployRecordId)->toBe('12345');
});

test('COS bind 轮询多轮直到 succeeded+failed==total 才返回', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')->once()->andReturn(cosDeployResponse(999));
    // 第一轮 running（0+0<1），第二轮成功（1+0==1）
    $ssl->shouldReceive('DescribeHostDeployRecordDetail')
        ->twice()
        ->andReturn(cosRecordDetailResponse(1, 0, 0), cosRecordDetailResponse(1, 1, 0));

    $deployer = tencentCosDeployerWith(fn () => $ssl);
    // 不抛即通过
    $deployer->bind('cert-cos', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'bucket' => 'b', 'domain' => 'd.example.com',
    ]);

    expect(true)->toBeTrue();
});

test('COS bind 轮询发现失败子任务时抛业务错误', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')->once()->andReturn(cosDeployResponse(1));
    // 1 成功 1 失败 共 2 → 终态且 failed>0
    $ssl->shouldReceive('DescribeHostDeployRecordDetail')->once()->andReturn(cosRecordDetailResponse(2, 1, 1));

    $deployer = tencentCosDeployerWith(fn () => $ssl);
    expect(fn () => $deployer->bind('cert-cos', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'bucket' => 'b', 'domain' => 'd.example.com',
    ]))->toThrow(RuntimeException::class, '失败子任务');
});

test('COS bind 部署响应无 DeployRecordId 时触发即成功（不轮询）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')->once()->andReturn(cosDeployResponse(null));
    // 不应调用轮询
    $ssl->shouldNotReceive('DescribeHostDeployRecordDetail');

    $deployer = tencentCosDeployerWith(fn () => $ssl);
    $deployer->bind('cert-cos', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'bucket' => 'b', 'domain' => 'd.example.com',
    ]);

    expect(true)->toBeTrue();
});

test('COS 缺 region 配置抛业务错误', function () {
    $deployer = tencentCosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'bucket' => 'b', 'domain' => 'd.example.com',
    ]))->toThrow(RuntimeException::class, '缺少配置 region');
});

test('COS 缺 bucket 配置抛业务错误', function () {
    $deployer = tencentCosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'domain' => 'd.example.com',
    ]))->toThrow(RuntimeException::class, '缺少配置 bucket');
});

test('COS 缺 domain 配置抛业务错误', function () {
    $deployer = tencentCosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'bucket' => 'b',
    ]))->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('COS bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DeployCertificateInstance')
        ->andThrow(new TencentCloudSDKException('FailedOperation', 'deploy cos failed', 'req-1'));

    $deployer = tencentCosDeployerWith(fn () => $ssl);

    try {
        $deployer->bind('cert-cos', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'region' => 'ap-guangzhou', 'bucket' => 'b', 'domain' => 'd.example.com',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('deploy cos failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

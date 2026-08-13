<?php

use Aws\Acm\AcmClient;
use Aws\CloudFront\CloudFrontClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\Result;
use Plugins\CloudDeploy\Deployers\Aws\AwsCloudFrontDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsCloudFrontDeployerWith(callable $clientFactory): AwsCloudFrontDeployer
{
    return new class($clientFactory) extends AwsCloudFrontDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsCfApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

$cfCreds = ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
$cfCfg = ['region' => 'us-east-1', 'distribution_id' => 'E123ABC'];

test('AWS CloudFront 元信息 + ACM 源（region 维度）', function () {
    $deployer = new AwsCloudFrontDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('cloudfront');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('acm:us-east-1');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_source');
    expect($deployer->certUploader([
        'region' => 'us-east-1', 'certificate_source' => 'IAM',
    ])->storeKind())->toBe('iam-cloudfront-id');
});

test('bind get→改 ViewerCertificate→UpdateDistribution（ACMCertificateArn=ARN、关闭默认证书、IfMatch=ETag）', function () use ($cfCreds, $cfCfg) {
    $captured = null;
    $cf = Mockery::mock(CloudFrontClient::class);
    $cf->shouldReceive('getDistributionConfig')->once()->andReturn(new Result([
        'DistributionConfig' => [
            'CallerReference' => 'ref-1',
            'ViewerCertificate' => ['CloudFrontDefaultCertificate' => true],
        ],
        'ETag' => 'ETAG123',
    ]));
    $cf->shouldReceive('updateDistribution')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });

    $deployer = awsCloudFrontDeployerWith(fn (string $kind) => $kind === 'cloudfront' ? $cf : new stdClass);
    $deployer->bind('arn:aws:acm:us-east-1:1:certificate/abc', $cfCreds, $cfCfg);

    expect($captured['Id'])->toBe('E123ABC');
    expect($captured['IfMatch'])->toBe('ETAG123');
    expect($captured['DistributionConfig']['ViewerCertificate']['ACMCertificateArn'])->toBe('arn:aws:acm:us-east-1:1:certificate/abc');
    expect($captured['DistributionConfig']['ViewerCertificate']['CloudFrontDefaultCertificate'])->toBeFalse();
    // 保留原有配置（CallerReference 不丢）
    expect($captured['DistributionConfig']['CallerReference'])->toBe('ref-1');
    // 清空 IAMCertificateId（ACM 源）
    expect($captured['DistributionConfig']['ViewerCertificate'])->not->toHaveKey('IAMCertificateId');
});

test('bind 分发不存在（get 返回空）→ 业务错误', function () use ($cfCreds, $cfCfg) {
    $cf = Mockery::mock(CloudFrontClient::class);
    $cf->shouldReceive('getDistributionConfig')->andReturn(new Result([]));
    $cf->shouldReceive('updateDistribution')->never();

    $deployer = awsCloudFrontDeployerWith(fn () => $cf);
    expect(fn () => $deployer->bind('arn:cert', $cfCreds, $cfCfg))
        ->toThrow(RuntimeException::class, '未找到 CloudFront 分发');
});

test('缺 distribution_id 抛业务错误', function () use ($cfCreds) {
    $deployer = awsCloudFrontDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('arn:cert', $cfCreds, ['region' => 'us-east-1']))
        ->toThrow(RuntimeException::class, '缺少配置 distribution_id');
});

test('bind SDK 抛 AwsException 脱敏含错误码、无 AK/SK、不挂 previous', function () use ($cfCfg) {
    $cf = Mockery::mock(CloudFrontClient::class);
    $cf->shouldReceive('getDistributionConfig')->andThrow(awsCfApiException('NoSuchDistribution', 'no such distribution'));

    $deployer = awsCloudFrontDeployerWith(fn () => $cf);

    try {
        $deployer->bind('arn:cert', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ'], $cfCfg);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NoSuchDistribution')->toContain('no such distribution');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

test('uploader.upload 经 makeClient(acm) 上传返回 ARN', function () use ($cfCreds) {
    $acm = Mockery::mock(AcmClient::class);
    $acm->shouldReceive('importCertificate')->once()->andReturn(new Result(['CertificateArn' => 'arn:aws:acm:us-east-1:1:certificate/xyz']));

    $deployer = awsCloudFrontDeployerWith(fn (string $kind) => $kind === 'acm' ? $acm : new stdClass);
    $id = $deployer->certUploader(['region' => 'us-east-1'])->upload('C', 'K', 'CH', $cfCreds);
    expect($id)->toBe('arn:aws:acm:us-east-1:1:certificate/xyz');
});

test('IAM 源上传返回 ServerCertificateId 并绑定 IAMCertificateId', function () use ($cfCreds, $cfCfg) {
    $iam = Mockery::mock(IamClient::class);
    $iam->shouldReceive('uploadServerCertificate')->once()->andReturn(new Result([
        'ServerCertificateMetadata' => [
            'Arn' => 'arn:aws:iam::123:server-certificate/cloudfront/cert',
            'ServerCertificateId' => 'ASCA-CLOUDFRONT-ID',
        ],
    ]));

    $captured = null;
    $cf = Mockery::mock(CloudFrontClient::class);
    $cf->shouldReceive('getDistributionConfig')->once()->andReturn(new Result([
        'DistributionConfig' => ['ViewerCertificate' => ['ACMCertificateArn' => 'old-arn']],
        'ETag' => 'ETAG-IAM',
    ]));
    $cf->shouldReceive('updateDistribution')->once()->andReturnUsing(function (array $request) use (&$captured) {
        $captured = $request;

        return new Result;
    });

    $deployer = awsCloudFrontDeployerWith(fn (string $kind) => match ($kind) {
        'iam' => $iam,
        'cloudfront' => $cf,
        default => new stdClass,
    });
    $config = $cfCfg + ['certificate_source' => 'IAM'];
    $certificateId = $deployer->certUploader($config)->upload('C', 'K', 'CH', $cfCreds);
    $deployer->bind($certificateId, $cfCreds, $config);

    expect($certificateId)->toBe('ASCA-CLOUDFRONT-ID');
    expect($captured['DistributionConfig']['ViewerCertificate']['IAMCertificateId'])->toBe('ASCA-CLOUDFRONT-ID');
    expect($captured['DistributionConfig']['ViewerCertificate'])->not->toHaveKey('ACMCertificateArn');
});

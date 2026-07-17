<?php

use Aws\Acm\AcmClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Plugins\CloudDeploy\Deployers\Aws\AwsAcmDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（acm）。
 * uploader 经 certUploader() 复用同一 makeClient('acm')，故 mock acm 即覆盖上传路径。
 */
function awsAcmDeployerWith(callable $clientFactory): AwsAcmDeployer
{
    return new class($clientFactory) extends AwsAcmDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

/** 构造一个携带服务端 errorCode/errorMessage 的 AwsException（不含凭证）。 */
function awsApiException(string $code, string $message): AwsException
{
    $cmd = Mockery::mock(CommandInterface::class);

    return new AwsException($message, $cmd, [
        'code' => $code,
        'message' => $message,
    ]);
}

test('AWS ACM 元信息 + 走证书服务（region 维度 storeKind）', function () {
    $deployer = new AwsAcmDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('acm');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('acm:us-east-1');
    // 空 config 不崩（RegistryCompletenessTest 会以 [] 调用）
    expect($deployer->certUploader([])->storeKind())->toBe('acm:');
});

test('uploader.upload 调 acm.importCertificate（Certificate/CertificateChain/PrivateKey）返回 CertificateArn', function () {
    $captured = null;
    $acm = Mockery::mock(AcmClient::class);
    $acm->shouldReceive('importCertificate')
        ->once()
        ->andReturnUsing(function (array $req) use (&$captured) {
            $captured = $req;

            return new Result(['CertificateArn' => 'arn:aws:acm:us-east-1:123:certificate/abc']);
        });

    $deployer = awsAcmDeployerWith(fn (string $kind) => $kind === 'acm' ? $acm : new stdClass);
    $id = $deployer->certUploader(['region' => 'us-east-1'])
        ->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'secret_access_key' => 'SK']);

    expect($id)->toBe('arn:aws:acm:us-east-1:123:certificate/abc');
    // 插件已拆好 leaf/chain：Certificate=leaf 原样、CertificateChain=chain 原样（不拼接）
    expect($captured['Certificate'])->toBe('CERTPEM');
    expect($captured['CertificateChain'])->toBe('CHAINPEM');
    expect($captured['PrivateKey'])->toBe('KEYPEM');
});

test('upload 未返回 CertificateArn 抛明确异常', function () {
    $acm = Mockery::mock(AcmClient::class);
    $acm->shouldReceive('importCertificate')->andReturn(new Result([]));

    $deployer = awsAcmDeployerWith(fn () => $acm);

    expect(fn () => $deployer->certUploader(['region' => 'us-east-1'])
        ->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'secret_access_key' => 'SK']))
        ->toThrow(RuntimeException::class, 'CertificateArn');
});

test('bind 为 no-op（纯上传端点，不调任何资源 SDK）', function () {
    $deployer = awsAcmDeployerWith(fn () => throw new RuntimeException('bind 不应构造 client'));
    // 不抛即通过
    $deployer->bind('arn:aws:acm:us-east-1:1:certificate/x', ['access_key_id' => 'AK', 'secret_access_key' => 'SK'], ['region' => 'us-east-1']);
    expect(true)->toBeTrue();
});

test('upload SDK 抛 AwsException（服务端错误码）脱敏含错误码、无 AK/SK、不挂 previous', function () {
    $acm = Mockery::mock(AcmClient::class);
    $acm->shouldReceive('importCertificate')->andThrow(awsApiException('ValidationException', 'The certificate is invalid'));

    $deployer = awsAcmDeployerWith(fn () => $acm);

    try {
        $deployer->certUploader(['region' => 'us-east-1'])
            ->upload('C', 'K', 'CH', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ValidationException')->toContain('The certificate is invalid');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SK-LEAK-XYZ');
    }
});

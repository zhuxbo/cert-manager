<?php

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\Result;
use Plugins\CloudDeploy\Deployers\Aws\AwsIamDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsIamDeployerWith(callable $clientFactory): AwsIamDeployer
{
    return new class($clientFactory) extends AwsIamDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsIamApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

test('AWS IAM 元信息 + storeKind=iam（全局，非 region）', function () {
    $deployer = new AwsIamDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('iam');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('iam');
    expect($deployer->certUploader([])->storeKind())->toBe('iam');
});

test('uploader.upload 调 iam.uploadServerCertificate 返回 ServerCertificateMetadata.Arn', function () {
    $captured = null;
    $iam = Mockery::mock(IamClient::class);
    $iam->shouldReceive('uploadServerCertificate')
        ->once()
        ->andReturnUsing(function (array $req) use (&$captured) {
            $captured = $req;

            return new Result(['ServerCertificateMetadata' => [
                'Arn' => 'arn:aws:iam::123:server-certificate/clouddeploy_x',
                'ServerCertificateId' => 'ASCA123',
            ]]);
        });

    $deployer = awsIamDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : new stdClass);
    $id = $deployer->certUploader(['region' => 'us-east-1', 'certificate_path' => '/elb/'])
        ->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'secret_access_key' => 'SK']);

    expect($id)->toBe('arn:aws:iam::123:server-certificate/clouddeploy_x');
    expect($captured['CertificateBody'])->toBe('CERTPEM');
    expect($captured['CertificateChain'])->toBe('CHAINPEM');
    expect($captured['PrivateKey'])->toBe('KEYPEM');
    expect($captured['Path'])->toBe('/elb/');
    expect($captured['ServerCertificateName'])->toStartWith('clouddeploy_');
});

test('uploader Path 缺省回落 "/"', function () {
    $captured = null;
    $iam = Mockery::mock(IamClient::class);
    $iam->shouldReceive('uploadServerCertificate')->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result(['ServerCertificateMetadata' => ['Arn' => 'arn:aws:iam::1:server-certificate/y']]);
    });

    $deployer = awsIamDeployerWith(fn () => $iam);
    $deployer->certUploader(['region' => 'us-east-1'])->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'secret_access_key' => 'SK']);

    expect($captured['Path'])->toBe('/');
});

test('upload 未返回 Arn 抛明确异常', function () {
    $iam = Mockery::mock(IamClient::class);
    $iam->shouldReceive('uploadServerCertificate')->andReturn(new Result(['ServerCertificateMetadata' => []]));

    $deployer = awsIamDeployerWith(fn () => $iam);

    expect(fn () => $deployer->certUploader(['region' => 'us-east-1'])
        ->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'secret_access_key' => 'SK']))
        ->toThrow(RuntimeException::class, 'Arn');
});

test('bind 为 no-op（纯上传端点）', function () {
    $deployer = awsIamDeployerWith(fn () => throw new RuntimeException('bind 不应构造 client'));
    $deployer->bind('arn:aws:iam::1:server-certificate/x', ['access_key_id' => 'AK', 'secret_access_key' => 'SK'], ['region' => 'us-east-1']);
    expect(true)->toBeTrue();
});

test('upload SDK 抛 AwsException 脱敏无 AK/SK、不挂 previous', function () {
    $iam = Mockery::mock(IamClient::class);
    $iam->shouldReceive('uploadServerCertificate')->andThrow(awsIamApiException('MalformedCertificate', 'bad cert'));

    $deployer = awsIamDeployerWith(fn () => $iam);

    try {
        $deployer->certUploader(['region' => 'us-east-1'])
            ->upload('C', 'K', 'CH', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('MalformedCertificate')->toContain('bad cert');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

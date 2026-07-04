<?php

use Aws\Amplify\AmplifyClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Plugins\CloudDeploy\Deployers\Aws\AwsAmplifyDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsAmplifyDeployerWith(callable $clientFactory): AwsAmplifyDeployer
{
    return new class($clientFactory) extends AwsAmplifyDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsAmplifyApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

$amCreds = ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
$amCfg = ['region' => 'us-east-1', 'app_id' => 'd123', 'domain' => 'www.example.com'];

test('AWS Amplify 元信息 + ACM 源', function () {
    $deployer = new AwsAmplifyDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('amplify');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('acm:us-east-1');
});

test('bind 调 amplify.updateDomainAssociation（小驼峰键 + certificateSettings type=CUSTOM, customCertificateArn=ARN）', function () use ($amCreds, $amCfg) {
    $captured = null;
    $amp = Mockery::mock(AmplifyClient::class);
    $amp->shouldReceive('updateDomainAssociation')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });

    $deployer = awsAmplifyDeployerWith(fn (string $kind) => $kind === 'amplify' ? $amp : new stdClass);
    $deployer->bind('arn:aws:acm:us-east-1:1:certificate/abc', $amCreds, $amCfg);

    expect($captured['appId'])->toBe('d123');
    expect($captured['domainName'])->toBe('www.example.com');
    expect($captured['certificateSettings']['type'])->toBe('CUSTOM');
    expect($captured['certificateSettings']['customCertificateArn'])->toBe('arn:aws:acm:us-east-1:1:certificate/abc');
});

test('缺 app_id 抛业务错误', function () use ($amCreds) {
    $deployer = awsAmplifyDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('arn:cert', $amCreds, ['region' => 'us-east-1', 'domain' => 'x.example.com']))
        ->toThrow(RuntimeException::class, '缺少配置 app_id');
});

test('bind SDK 抛 AwsException 脱敏含错误码、无 AK/SK、不挂 previous', function () use ($amCfg) {
    $amp = Mockery::mock(AmplifyClient::class);
    $amp->shouldReceive('updateDomainAssociation')->andThrow(awsAmplifyApiException('NotFoundException', 'domain not found'));

    $deployer = awsAmplifyDeployerWith(fn () => $amp);

    try {
        $deployer->bind('arn:cert', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ'], $amCfg);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NotFoundException')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

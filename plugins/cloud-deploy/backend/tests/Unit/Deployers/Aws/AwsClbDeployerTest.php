<?php

use Aws\CommandInterface;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\Result;
use Plugins\CloudDeploy\Deployers\Aws\AwsClbDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsClbDeployerWith(callable $clientFactory): AwsClbDeployer
{
    return new class($clientFactory) extends AwsClbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsClbApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

$clbCreds = ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
$clbCfg = ['region' => 'us-east-1', 'load_balancer_name' => 'my-clb', 'listener_port' => 443];

test('AWS CLB 元信息 + 默认 ACM / 可选 IAM 源', function () {
    $deployer = new AwsClbDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('clb');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('acm:us-east-1');
    expect($deployer->certUploader(['region' => 'us-east-1', 'certificate_source' => 'IAM'])->storeKind())->toBe('iam');
});

test('IAM 源 uploader 经 makeClient(iam) 上传返回 Arn（Path=/elb/）', function () use ($clbCreds) {
    $captured = null;
    $iam = Mockery::mock(IamClient::class);
    $iam->shouldReceive('uploadServerCertificate')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result(['ServerCertificateMetadata' => ['Arn' => 'arn:aws:iam::1:server-certificate/x']]);
    });

    $deployer = awsClbDeployerWith(fn (string $kind) => $kind === 'iam' ? $iam : new stdClass);
    $id = $deployer->certUploader(['region' => 'us-east-1', 'certificate_source' => 'IAM'])->upload('C', 'K', 'CH', $clbCreds);

    expect($id)->toBe('arn:aws:iam::1:server-certificate/x');
    expect($captured['Path'])->toBe('/elb/');
});

test('bind 调经典 elb.setLoadBalancerListenerSSLCertificate（Name/Port/SSLCertificateId=ARN）', function () use ($clbCreds, $clbCfg) {
    $captured = null;
    $elb = Mockery::mock(ElasticLoadBalancingClient::class);
    $elb->shouldReceive('setLoadBalancerListenerSSLCertificate')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });

    $deployer = awsClbDeployerWith(fn (string $kind) => $kind === 'elb' ? $elb : new stdClass);
    $deployer->bind('arn:aws:acm:us-east-1:1:certificate/abc', $clbCreds, $clbCfg);

    expect($captured['LoadBalancerName'])->toBe('my-clb');
    expect($captured['LoadBalancerPort'])->toBe(443);
    expect($captured['LoadBalancerPort'])->toBeInt();
    expect($captured['SSLCertificateId'])->toBe('arn:aws:acm:us-east-1:1:certificate/abc');
});

test('缺 load_balancer_name 抛业务错误', function () use ($clbCreds) {
    $deployer = awsClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('arn:cert', $clbCreds, ['region' => 'us-east-1', 'listener_port' => 443]))
        ->toThrow(RuntimeException::class, '缺少配置 load_balancer_name');
});

test('bind SDK 抛 AwsException 脱敏含错误码、无 AK/SK、不挂 previous', function () use ($clbCfg) {
    $elb = Mockery::mock(ElasticLoadBalancingClient::class);
    $elb->shouldReceive('setLoadBalancerListenerSSLCertificate')->andThrow(awsClbApiException('LoadBalancerNotFound', 'lb not found'));

    $deployer = awsClbDeployerWith(fn () => $elb);

    try {
        $deployer->bind('arn:cert', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ'], $clbCfg);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('LoadBalancerNotFound')->toContain('lb not found');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

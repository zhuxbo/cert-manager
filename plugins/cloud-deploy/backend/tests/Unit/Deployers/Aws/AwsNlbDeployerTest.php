<?php

use Aws\CommandInterface;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\Exception\AwsException;
use Aws\Result;
use Mockery\MockInterface;
use Plugins\CloudDeploy\Deployers\Aws\AwsNlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsNlbDeployerWith(callable $clientFactory): AwsNlbDeployer
{
    return new class($clientFactory) extends AwsNlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsNlbApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

function nlbElbv2MockBase(): MockInterface
{
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'network']]]));
    $elbv2->shouldReceive('describeListeners')->andReturn(new Result(['Listeners' => [['Certificates' => []]]]));

    return $elbv2;
}

$nlbCreds = ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
$nlbCfg = ['region' => 'us-east-1', 'load_balancer_arn' => 'arn:lb', 'listener_arn' => 'arn:listener'];

test('AWS NLB 元信息 + 默认 ACM 源', function () {
    $deployer = new AwsNlbDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('nlb');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'eu-west-1'])->storeKind())->toBe('acm:eu-west-1');
});

test('bind 默认 → AddListenerCertificates（network 类型校验通过）', function () use ($nlbCreds, $nlbCfg) {
    $captured = null;
    $elbv2 = nlbElbv2MockBase();
    $elbv2->shouldReceive('addListenerCertificates')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });

    $deployer = awsNlbDeployerWith(fn (string $kind) => $kind === 'elbv2' ? $elbv2 : new stdClass);
    $deployer->bind('arn:cert', $nlbCreds, $nlbCfg);

    expect($captured['ListenerArn'])->toBe('arn:listener');
    expect($captured['Certificates'][0]['CertificateArn'])->toBe('arn:cert');
});

test('bind is_default=true → ModifyListener', function () use ($nlbCreds, $nlbCfg) {
    $captured = null;
    $elbv2 = nlbElbv2MockBase();
    $elbv2->shouldReceive('modifyListener')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });

    $deployer = awsNlbDeployerWith(fn () => $elbv2);
    $deployer->bind('arn:cert', $nlbCreds, $nlbCfg + ['is_default' => true]);
    expect($captured['Certificates'][0]['CertificateArn'])->toBe('arn:cert');
});

test('bind is_default=true 但同 ARN 仅为 SNI → 仍设为默认证书', function () use ($nlbCreds, $nlbCfg) {
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'network']]]));
    $elbv2->shouldReceive('describeListeners')->andReturn(new Result(['Listeners' => [['Certificates' => [['CertificateArn' => 'arn:cert', 'IsDefault' => false]]]]]));
    $elbv2->shouldReceive('modifyListener')->once();

    awsNlbDeployerWith(fn () => $elbv2)->bind('arn:cert', $nlbCreds, $nlbCfg + ['is_default' => true]);
});

test('bind is_default=false 且同 ARN 已为 SNI → 跳过重复添加', function () use ($nlbCreds, $nlbCfg) {
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'network']]]));
    $elbv2->shouldReceive('describeListeners')->andReturn(new Result(['Listeners' => [['Certificates' => [['CertificateArn' => 'arn:cert', 'IsDefault' => false]]]]]));
    $elbv2->shouldReceive('addListenerCertificates')->never();

    awsNlbDeployerWith(fn () => $elbv2)->bind('arn:cert', $nlbCreds, $nlbCfg);
});

test('bind 非 network 类型 LB（application）→ 业务错误', function () use ($nlbCreds, $nlbCfg) {
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'application']]]));

    $deployer = awsNlbDeployerWith(fn () => $elbv2);
    expect(fn () => $deployer->bind('arn:cert', $nlbCreds, $nlbCfg))
        ->toThrow(RuntimeException::class, '未找到 NLB 实例');
});

test('bind SDK 抛 AwsException 脱敏无 AK/SK、不挂 previous', function () use ($nlbCfg) {
    $elbv2 = nlbElbv2MockBase();
    $elbv2->shouldReceive('addListenerCertificates')->andThrow(awsNlbApiException('ListenerNotFound', 'no listener'));

    $deployer = awsNlbDeployerWith(fn () => $elbv2);

    try {
        $deployer->bind('arn:cert', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ'], $nlbCfg);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ListenerNotFound');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

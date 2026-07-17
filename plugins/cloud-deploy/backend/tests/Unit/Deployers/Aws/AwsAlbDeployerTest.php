<?php

use Aws\Acm\AcmClient;
use Aws\CommandInterface;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\Exception\AwsException;
use Aws\Result;
use Mockery\MockInterface;
use Plugins\CloudDeploy\Deployers\Aws\AwsAlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsAlbDeployerWith(callable $clientFactory): AwsAlbDeployer
{
    return new class($clientFactory) extends AwsAlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsAlbApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

/** application 类型 LB + 一个侦听器（默认无证书）的 elbv2 mock 基线。 */
function albElbv2MockBase(): MockInterface
{
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'application']]]));
    $elbv2->shouldReceive('describeListeners')->andReturn(new Result(['Listeners' => [['ListenerArn' => 'arn:listener', 'Certificates' => []]]]));

    return $elbv2;
}

$albCreds = ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
$albCfg = ['region' => 'us-east-1', 'load_balancer_arn' => 'arn:lb', 'listener_arn' => 'arn:listener'];

test('AWS ALB 元信息 + 默认 ACM 源 storeKind', function () {
    $deployer = new AwsAlbDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('alb');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    // 默认 ACM 源（region 维度）
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('acm:us-east-1');
    // 指定 IAM 源 → iam（全局）
    expect($deployer->certUploader(['region' => 'us-east-1', 'certificate_source' => 'IAM'])->storeKind())->toBe('iam');
});

test('ACM 源 uploader 经 makeClient(acm) 上传返回 ARN', function () use ($albCreds) {
    $acm = Mockery::mock(AcmClient::class);
    $acm->shouldReceive('importCertificate')->once()->andReturn(new Result(['CertificateArn' => 'arn:aws:acm:us-east-1:1:certificate/abc']));

    $deployer = awsAlbDeployerWith(fn (string $kind) => $kind === 'acm' ? $acm : new stdClass);
    $id = $deployer->certUploader(['region' => 'us-east-1'])->upload('C', 'K', 'CH', $albCreds);
    expect($id)->toBe('arn:aws:acm:us-east-1:1:certificate/abc');
});

test('bind 默认（is_default=false）→ AddListenerCertificates（Certificates[].CertificateArn=ARN）', function () use ($albCreds, $albCfg) {
    $captured = null;
    $elbv2 = albElbv2MockBase();
    $elbv2->shouldReceive('addListenerCertificates')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });
    $elbv2->shouldReceive('modifyListener')->never();

    $deployer = awsAlbDeployerWith(fn (string $kind) => $kind === 'elbv2' ? $elbv2 : new stdClass);
    $deployer->bind('arn:aws:acm:us-east-1:1:certificate/abc', $albCreds, $albCfg);

    expect($captured['ListenerArn'])->toBe('arn:listener');
    expect($captured['Certificates'][0]['CertificateArn'])->toBe('arn:aws:acm:us-east-1:1:certificate/abc');
});

test('bind is_default=true → ModifyListener（设默认证书）', function () use ($albCreds, $albCfg) {
    $captured = null;
    $elbv2 = albElbv2MockBase();
    $elbv2->shouldReceive('modifyListener')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });
    $elbv2->shouldReceive('addListenerCertificates')->never();

    $deployer = awsAlbDeployerWith(fn (string $kind) => $kind === 'elbv2' ? $elbv2 : new stdClass);
    $deployer->bind('arn:cert', $albCreds, $albCfg + ['is_default' => true]);

    expect($captured['ListenerArn'])->toBe('arn:listener');
    expect($captured['Certificates'][0]['CertificateArn'])->toBe('arn:cert');
});

test('bind is_default=true 且证书已是默认 → 跳过（不调 ModifyListener）', function () use ($albCreds, $albCfg) {
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'application']]]));
    $elbv2->shouldReceive('describeListeners')->andReturn(new Result(['Listeners' => [['Certificates' => [['CertificateArn' => 'arn:cert']]]]]));
    $elbv2->shouldReceive('modifyListener')->never();

    $deployer = awsAlbDeployerWith(fn () => $elbv2);
    $deployer->bind('arn:cert', $albCreds, $albCfg + ['is_default' => true]);
    expect(true)->toBeTrue();
});

test('bind 非 application 类型 LB → 业务错误（可读、未脱敏）', function () use ($albCreds, $albCfg) {
    $elbv2 = Mockery::mock(ElasticLoadBalancingV2Client::class);
    // 返回 network 类型（NLB），ALB 端点应拒绝
    $elbv2->shouldReceive('describeLoadBalancers')->andReturn(new Result(['LoadBalancers' => [['Type' => 'network']]]));

    $deployer = awsAlbDeployerWith(fn () => $elbv2);
    expect(fn () => $deployer->bind('arn:cert', $albCreds, $albCfg))
        ->toThrow(RuntimeException::class, '未找到 ALB 实例');
});

test('缺 region 配置抛业务错误', function () use ($albCreds) {
    $deployer = awsAlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('arn:cert', $albCreds, ['load_balancer_arn' => 'arn:lb', 'listener_arn' => 'arn:listener']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('bind SDK 抛 AwsException 脱敏含错误码、无 AK/SK、不挂 previous', function () use ($albCfg) {
    $elbv2 = albElbv2MockBase();
    $elbv2->shouldReceive('addListenerCertificates')->andThrow(awsAlbApiException('ListenerNotFound', 'listener not found'));

    $deployer = awsAlbDeployerWith(fn () => $elbv2);

    try {
        $deployer->bind('arn:cert', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ'], $albCfg);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ListenerNotFound')->toContain('listener not found');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SK-LEAK-XYZ');
    }
});

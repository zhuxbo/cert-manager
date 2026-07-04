<?php

use Aws\ApiGatewayV2\ApiGatewayV2Client;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Plugins\CloudDeploy\Deployers\Aws\AwsApigatewayDeployer;
use Tests\TestCase;

uses(TestCase::class);

function awsApigatewayDeployerWith(callable $clientFactory): AwsApigatewayDeployer
{
    return new class($clientFactory) extends AwsApigatewayDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function awsApigwApiException(string $code, string $message): AwsException
{
    return new AwsException($message, Mockery::mock(CommandInterface::class), ['code' => $code, 'message' => $message]);
}

$agCreds = ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
$agCfg = ['region' => 'us-east-1', 'domain' => 'api.example.com'];

test('AWS API Gateway 元信息 + ACM 源', function () {
    $deployer = new AwsApigatewayDeployer;
    expect($deployer->provider())->toBe('aws');
    expect($deployer->product())->toBe('apigateway');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'us-east-1'])->storeKind())->toBe('acm:us-east-1');
});

test('bind 调 apigatewayv2.updateDomainName（DomainNameConfigurations[].CertificateArn=ARN）', function () use ($agCreds, $agCfg) {
    $captured = null;
    $ag = Mockery::mock(ApiGatewayV2Client::class);
    $ag->shouldReceive('updateDomainName')->once()->andReturnUsing(function (array $req) use (&$captured) {
        $captured = $req;

        return new Result([]);
    });

    $deployer = awsApigatewayDeployerWith(fn (string $kind) => $kind === 'apigatewayv2' ? $ag : new stdClass);
    $deployer->bind('arn:aws:acm:us-east-1:1:certificate/abc', $agCreds, $agCfg);

    expect($captured['DomainName'])->toBe('api.example.com');
    expect($captured['DomainNameConfigurations'][0]['CertificateArn'])->toBe('arn:aws:acm:us-east-1:1:certificate/abc');
});

test('缺 domain 抛业务错误', function () use ($agCreds) {
    $deployer = awsApigatewayDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('arn:cert', $agCreds, ['region' => 'us-east-1']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 AwsException 脱敏含错误码、无 AK/SK、不挂 previous', function () use ($agCfg) {
    $ag = Mockery::mock(ApiGatewayV2Client::class);
    $ag->shouldReceive('updateDomainName')->andThrow(awsApigwApiException('NotFoundException', 'domain name not found'));

    $deployer = awsApigatewayDeployerWith(fn () => $ag);

    try {
        $deployer->bind('arn:cert', ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SK-LEAK-XYZ'], $agCfg);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NotFoundException')->toContain('domain name not found');
        expect($e->getMessage())->not->toContain('AKIA-LEAK-1234567890')->not->toContain('SK-LEAK-XYZ');
        expect($e->getPrevious())->toBeNull();
    }
});

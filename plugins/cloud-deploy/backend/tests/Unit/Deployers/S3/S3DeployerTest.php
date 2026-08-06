<?php

use Aws\AwsClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Plugins\CloudDeploy\Deployers\S3\S3Deployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（s3 kind）注入 mock S3Client。 */
function s3DeployerWith(callable $clientFactory): S3Deployer
{
    return new class($clientFactory) extends S3Deployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function s3CertRef(): array
{
    return ['cert' => 'LEAFPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function s3Creds(): array
{
    return ['access_key_id' => 'AKIDXXXX', 'secret_access_key' => 'SECRET', 'region' => 'us-east-1'];
}

test('S3 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new S3Deployer;
    expect($deployer->provider())->toBe('s3');
    expect($deployer->product())->toBe('s3');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('bucket')->toContain('object_key_for_crt')->toContain('object_key_for_key');
});

test('PutObject 写完整链 + 私钥到指定对象键', function () {
    $puts = [];
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->andReturnUsing(function (array $args) use (&$puts) {
        $puts[$args['Key']] = $args;
    });

    $deployer = s3DeployerWith(fn () => $client);
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'my-bucket',
        'object_key_for_crt' => 'ssl/full.pem',
        'object_key_for_key' => 'ssl/key.pem',
    ]);

    expect($puts)->toHaveKey('ssl/full.pem')->toHaveKey('ssl/key.pem');
    expect($puts['ssl/full.pem']['Bucket'])->toBe('my-bucket');
    expect($puts['ssl/full.pem']['Body'])->toContain('LEAFPEM')->toContain('CHAINPEM');
    expect($puts['ssl/key.pem']['Body'])->toBe('KEYPEM');
});

test('服务器证书 / 中间证书对象键各写单独内容', function () {
    $puts = [];
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->andReturnUsing(function (array $args) use (&$puts) {
        $puts[$args['Key']] = $args['Body'];
    });

    $deployer = s3DeployerWith(fn () => $client);
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'b',
        'object_key_for_crt_server' => 'leaf.pem',
        'object_key_for_crt_inter' => 'inter.pem',
    ]);

    expect($puts['leaf.pem'])->toContain('LEAFPEM')->not->toContain('CHAINPEM');
    expect($puts['inter.pem'])->toContain('CHAINPEM')->not->toContain('LEAFPEM');
});

test('空对象键跳过、不调 putObject', function () {
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->once()->andReturnUsing(function (array $args) {
        expect($args['Key'])->toBe('ssl/key.pem');
    });

    $deployer = s3DeployerWith(fn () => $client);
    $deployer->bind(s3CertRef(), s3Creds(), [
        'bucket' => 'b',
        'object_key_for_key' => 'ssl/key.pem',
    ]);
});

test('缺 bucket 抛业务错误', function () {
    $deployer = s3DeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(s3CertRef(), s3Creds(), ['object_key_for_crt' => 'x']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
});

test('全部对象键为空抛业务错误', function () {
    $deployer = s3DeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(s3CertRef(), s3Creds(), ['bucket' => 'b']))
        ->toThrow(RuntimeException::class, '至少需配置一个对象键');
});

test('bind 遇 AwsException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $cmd = Mockery::mock(CommandInterface::class);
    $awsEx = new AwsException('upload failed', $cmd, [
        'code' => 'AccessDenied',
        'message' => 'Access Denied for key',
    ]);
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('putObject')->andThrow($awsEx);

    $deployer = s3DeployerWith(fn () => $client);
    try {
        $deployer->bind(s3CertRef(), ['access_key_id' => 'AKIA-LEAK-1234567890', 'secret_access_key' => 'SECRET-LEAK-9', 'region' => 'us-east-1'], [
            'bucket' => 'b', 'object_key_for_crt' => 'x.pem',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AccessDenied');
        expect($e->getMessage())->not->toContain('SECRET-LEAK-9')->not->toContain('AKIA-LEAK-1234567890');
        expect($e->getPrevious())->toBeNull();
    }
});

test('运行时 Endpoint 指向回环地址被出站策略拦截（不构造 S3Client）', function () {
    app()->instance(
        OutboundDestinationPolicy::class,
        new OutboundDestinationPolicy(
            resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
        ),
    );

    $deployer = new S3Deployer;
    $method = (new ReflectionClass(S3Deployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 's3', [
        'access_key_id' => 'AKIDXXXX', 'secret_access_key' => 'SECRET', 'region' => 'us-east-1',
        'endpoint' => 'http://127.0.0.1:9000',
    ]))->toThrow(OutboundDestinationException::class);
});

test('自定义 S3 Endpoint 的 SDK 传输层钉在策略已审 IP', function () {
    app()->instance(
        OutboundDestinationPolicy::class,
        new OutboundDestinationPolicy(
            resolver: static fn (string $host): array => $host === 's3.example.com'
                ? ['93.184.216.34']
                : [],
        ),
    );

    $deployer = new S3Deployer;
    $method = (new ReflectionClass(S3Deployer::class))->getMethod('makeClient');
    $method->setAccessible(true);
    /** @var S3Client $client */
    $client = $method->invoke($deployer, 's3', [
        'access_key_id' => 'AKIDXXXX',
        'secret_access_key' => 'SECRET',
        'region' => 'us-east-1',
        'endpoint' => 'https://S3.EXAMPLE.COM./api',
    ]);

    $requestOptions = (new ReflectionClass(AwsClient::class))->getProperty('defaultRequestOptions');
    $requestOptions->setAccessible(true);
    /** @var array<string,mixed> $http */
    $http = $requestOptions->getValue($client);
    expect((string) $client->getEndpoint())->toBe('https://s3.example.com/api')
        ->and($http['curl'][CURLOPT_RESOLVE] ?? null)
        ->toBe(['s3.example.com:443:93.184.216.34']);
});

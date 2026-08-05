<?php

use AlibabaCloud\Oss\V2\Exception\OperationException;
use AlibabaCloud\Oss\V2\Exception\ServiceException;
use AlibabaCloud\Oss\V2\Models\PutCnameRequest;
use AlibabaCloud\Oss\V2\Models\PutCnameResult;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunOssDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

// OSS endpoint 由 region 派生：stub 策略放行公网 host，注入场景由授权测试覆盖
beforeEach(function () {
    app()->instance(OutboundDestinationPolicy::class, new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
    ));
});

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock client（不 new 真实 SDK client，不触网）。
 */
function aliyunOssDeployerWith(callable $clientFactory): AliyunOssDeployer
{
    return new class($clientFactory) extends AliyunOssDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('阿里云 OSS 直传，不走证书服务', function () {
    $deployer = new AliyunOssDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('oss');
    // configSchema 覆盖 bind 实际读取的 bucket + domain + region
    expect(array_column($deployer->configSchema(), 'key'))->toContain('bucket')->toContain('domain')->toContain('region');
});

test('bind 调 putCname 直灌 PEM（Certificate=cert+chain、PrivateKey=key、Force=true）', function () {
    $captured = null;
    $mock = Mockery::mock();
    $mock->shouldReceive('putCname')
        ->once()
        ->andReturnUsing(function (PutCnameRequest $req) use (&$captured) {
            $captured = $req;

            return new PutCnameResult;
        });

    $deployer = aliyunOssDeployerWith(fn (string $kind) => $kind === 'oss' ? $mock : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['bucket' => 'my-bucket', 'domain' => 'oss.example.com', 'region' => 'cn-hangzhou'],
    );

    expect($captured)->toBeInstanceOf(PutCnameRequest::class);
    expect($captured->bucket)->toBe('my-bucket');
    // 嵌套结构 BucketCnameConfiguration → Cname(domain, CertificateConfiguration)
    $cname = $captured->bucketCnameConfiguration->cname;
    expect($cname->domain)->toBe('oss.example.com');
    $cert = $cname->certificateConfiguration;
    expect($cert->certificate)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($cert->privateKey)->toBe('KEYPEM');
    expect($cert->force)->toBeTrue();
});

test('makeClient 按 region 构造 endpoint（region 进 credentials）', function () {
    $seenCredentials = null;
    $mock = Mockery::mock();
    $mock->shouldReceive('putCname')->once()->andReturn(new PutCnameResult);

    $deployer = aliyunOssDeployerWith(function (string $kind, array $credentials) use (&$seenCredentials, $mock) {
        $seenCredentials = $credentials;

        return $mock;
    });
    $deployer->bind(
        ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['bucket' => 'b', 'domain' => 'd.example.com', 'region' => 'cn-shanghai'],
    );

    // bind 把 region 透传进 makeClient 的 credentials（供真实 makeClient 计算 endpoint/region）
    expect($seenCredentials['region'])->toBe('cn-shanghai');
});

test('缺 bucket / domain / region 配置抛业务错误', function () {
    $deployer = aliyunOssDeployerWith(fn () => new stdClass);

    // 缺 bucket
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'd', 'region' => 'r']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
    // 缺 domain
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['bucket' => 'b', 'region' => 'r']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
    // 缺 region
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['bucket' => 'b', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
});

test('OSS ServiceException（服务端错误体）脱敏：取错误码+描述，不含 AK/SK/请求 URI', function () {
    // OSS 服务端结构化错误：details 是响应 + 请求快照，request_target 含请求 URI（潜在泄露面）
    $mock = Mockery::mock();
    $mock->shouldReceive('putCname')->andThrow(new ServiceException([
        'status_code' => 403,
        'code' => 'AccessDenied',
        'message' => 'You do not have permission.',
        'request_id' => 'req-oss-1',
        // 危险字段：请求快照/目标含签名 URI，必须不被回传
        'request_target' => 'PUT https://my-bucket.oss-cn-hangzhou.aliyuncs.com/?cname&OSSAccessKeyId=AK-LEAK-OSS&Signature=SIG-LEAK-OSS',
        'snapshot' => 'OSSAccessKeyId=AK-LEAK-OSS&Signature=SIG-LEAK-OSS',
    ]));

    $deployer = aliyunOssDeployerWith(fn () => $mock);

    try {
        $deployer->bind(
            ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
            ['access_key_id' => 'AK-LEAK-OSS', 'access_key_secret' => 'SK-LEAK-OSS'],
            ['bucket' => 'my-bucket', 'domain' => 'oss.example.com', 'region' => 'cn-hangzhou'],
        );
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        // 安全文案：服务端 code + message
        expect($e->getMessage())->toContain('AccessDenied')->toContain('You do not have permission.');
        // 脱敏：无凭证、无签名 URI、不挂 previous（trace 不带 SDK/Guzzle 帧）
        expect($e->getMessage())->not->toContain('AK-LEAK-OSS')->not->toContain('SIG-LEAK-OSS');
        expect($e->getMessage())->not->toContain('Signature');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-OSS')->not->toContain('SIG-LEAK-OSS');
    }
});

test('OSS OperationException（底层 Guzzle 链入 previous，message 含签名 URI）脱敏只暴露类名', function () {
    // OperationException 把底层异常链入 $previous 并拼进 getMessage()（含签名 URI）—— 必须挡住
    $guzzleLike = new RuntimeException('cURL error 7: Failed to connect to oss-cn-hangzhou.aliyuncs.com/?OSSAccessKeyId=AK-LEAK-NET&Signature=SIG-LEAK-NET');
    $mock = Mockery::mock();
    $mock->shouldReceive('putCname')->andThrow(new OperationException('PutCname', $guzzleLike));

    $deployer = aliyunOssDeployerWith(fn () => $mock);

    try {
        $deployer->bind(
            ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
            ['access_key_id' => 'AK-LEAK-NET', 'access_key_secret' => 'SK'],
            ['bucket' => 'my-bucket', 'domain' => 'oss.example.com', 'region' => 'cn-hangzhou'],
        );
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-NET')->not->toContain('SIG-LEAK-NET');
        expect($e->getMessage())->not->toContain('Signature');
        expect($e->getMessage())->toContain('阿里云调用失败');
        // 重建的干净异常不挂 previous，trace 不带含凭证的 Guzzle 帧
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK-NET')->not->toContain('SIG-LEAK-NET');
    }
});

test('region 含 URL 分隔符时即使目标解析为公网也被拒绝', function () {
    $deployer = new AliyunOssDeployer;
    $method = (new ReflectionClass(AliyunOssDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 'oss', [
        'access_key_id' => 'AK', 'access_key_secret' => 'SK', 'region' => 'public.example:443/path',
    ]))->toThrow(OutboundDestinationException::class);
});

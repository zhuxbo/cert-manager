<?php

use AlibabaCloud\SDK\Live\V20161101\Live;
use AlibabaCloud\SDK\Live\V20161101\Models\SetLiveDomainCertificateRequest;
use AlibabaCloud\SDK\Live\V20161101\Models\SetLiveDomainCertificateResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunLiveDeployer;
use Tests\TestCase;

uses(TestCase::class);

function aliyunLiveDeployerWith(callable $clientFactory): AliyunLiveDeployer
{
    return new class($clientFactory) extends AliyunLiveDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('阿里云直播 直传，不走证书服务（CertType=upload）', function () {
    $deployer = new AliyunLiveDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('live');
});

test('bind 调 setLiveDomainCertificate 带完整链 + key + 唯一 CertName（CertType=upload）', function () {
    $captured = null;
    $live = Mockery::mock(Live::class);
    $live->shouldReceive('setLiveDomainCertificate')
        ->once()
        ->andReturnUsing(function (SetLiveDomainCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new SetLiveDomainCertificateResponse;
        });

    $deployer = aliyunLiveDeployerWith(fn (string $kind) => $kind === 'live' ? $live : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ['domain' => 'live.example.com'],
    );

    expect($captured)->toBeInstanceOf(SetLiveDomainCertificateRequest::class);
    expect($captured->domainName)->toBe('live.example.com');
    expect($captured->certType)->toBe('upload');
    expect($captured->SSLProtocol)->toBe('on');
    expect($captured->SSLPub)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured->SSLPri)->toBe('KEYPEM');
    expect($captured->certName)->toStartWith('clouddeploy_');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(
        ['cert' => 'C', 'key' => 'K', 'chain' => 'CH'],
        ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        [],
    ))->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('SDK 抛 TeaError 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $live = Mockery::mock(Live::class);
    $live->shouldReceive('setLiveDomainCertificate')->andThrow(new TeaError([
        'code' => 'InvalidDomain.NotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'InvalidDomain.NotFound', 'Message' => 'the domain does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunLiveDeployerWith(fn () => $live);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidDomain.NotFound')->toContain('the domain does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('SDK 抛网络类异常（message 含签名 URI）时脱敏只暴露类名', function () {
    $live = Mockery::mock(Live::class);
    $live->shouldReceive('setLiveDomainCertificate')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://live.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunLiveDeployerWith(fn () => $live);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
    }
});

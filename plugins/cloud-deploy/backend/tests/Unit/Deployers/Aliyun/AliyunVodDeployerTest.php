<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Vod\V20170321\Models\SetVodDomainSSLCertificateRequest;
use AlibabaCloud\SDK\Vod\V20170321\Models\SetVodDomainSSLCertificateResponse;
use AlibabaCloud\SDK\Vod\V20170321\Vod;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunVodDeployer;
use Tests\TestCase;

uses(TestCase::class);

function aliyunVodDeployerWith(callable $clientFactory): AliyunVodDeployer
{
    return new class($clientFactory) extends AliyunVodDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function vodCasDetailResponse(string $name): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'name' => $name,
    ])]);
}

test('阿里云 VOD 走证书服务（CAS）', function () {
    $deployer = new AliyunVodDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('vod');
});

test('bind 反查 CertName + 拆 CertIdentifier 调 vod.SetVodDomainSSLCertificate（CertType=cas、CertId、CertName、CertRegion）', function () {
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return vodCasDetailResponse('clouddeploy_1700000000000');
        });

    $captured = null;
    $vod = Mockery::mock(Vod::class);
    $vod->shouldReceive('setVodDomainSSLCertificate')
        ->once()
        ->andReturnUsing(function (SetVodDomainSSLCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new SetVodDomainSSLCertificateResponse;
        });

    $deployer = aliyunVodDeployerWith(fn (string $kind) => match ($kind) {
        'cas' => $cas,
        'vod' => $vod,
        default => new stdClass,
    });
    $deployer->bind('555-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'vod.example.com']);

    // CertName 反查：按 certId（int）+ certFilter
    expect($detailReq->certId)->toBe(555);
    expect($detailReq->certFilter)->toBeTrue();
    // vod 绑定参数
    expect($captured->domainName)->toBe('vod.example.com');
    expect($captured->certType)->toBe('cas');
    expect($captured->certId)->toBe(555);
    expect($captured->certId)->toBeInt();
    expect($captured->certName)->toBe('clouddeploy_1700000000000');
    expect($captured->certRegion)->toBe('cn-hangzhou');
    expect($captured->SSLProtocol)->toBe('on');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunVodDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind 无效 CertIdentifier 抛业务错误（不调 SDK）', function () {
    $deployer = aliyunVodDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('garbage', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'v.example.com']))
        ->toThrow(RuntimeException::class, 'CertIdentifier');
});

test('bind SDK 抛 TeaError 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('getUserCertificateDetail')->andReturn(vodCasDetailResponse('clouddeploy_1'));
    $vod = Mockery::mock(Vod::class);
    $vod->shouldReceive('setVodDomainSSLCertificate')->andThrow(new TeaError([
        'code' => 'InvalidDomain.NotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'InvalidDomain.NotFound', 'Message' => 'domain not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunVodDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : $vod);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK', 'access_key_secret' => 'SK-LEAK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidDomain.NotFound')->toContain('domain not exist');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-LEAK')->not->toContain('SK-LEAK');
    }
});

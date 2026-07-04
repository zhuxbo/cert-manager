<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentWafDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Waf\V20180125\Models\ModifySpartaProtectionRequest;
use TencentCloud\Waf\V20180125\Models\ModifySpartaProtectionResponse;
use TencentCloud\Waf\V20180125\WafClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回对应 mock（ssl/waf），第三参 region。 */
function tencentWafDeployerWith(callable $clientFactory): TencentWafDeployer
{
    return new class($clientFactory) extends TencentWafDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function wafUploadCertResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

test('腾讯云 WAF 走证书服务（storeKind=tencent_ssl）+ 基本元信息', function () {
    $deployer = new TencentWafDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('waf');
    expect($deployer->label())->toBe('腾讯云 WAF');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('instance_id')->toContain('domain')->toContain('domain_id')->toContain('region');
});

test('WAF uploader.upload 调 ssl.UploadCertificate 返回 certId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')
        ->once()
        ->andReturnUsing(fn (UploadCertificateRequest $req) => wafUploadCertResponse('cert-waf'));

    $deployer = tencentWafDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : new stdClass);
    $id = $deployer->certUploader()->upload('CERT', 'KEY', 'CHAIN', ['secret_id' => 'AK', 'secret_key' => 'SK']);

    expect($id)->toBe('cert-waf');
});

test('WAF bind 用 certId 调 waf.ModifySpartaProtection 设 InstanceID/Domain/DomainId/CertType=2/SSLId（大写拼写）', function () {
    $captured = null;
    $waf = Mockery::mock(WafClient::class);
    $waf->shouldReceive('ModifySpartaProtection')
        ->once()
        ->andReturnUsing(function (ModifySpartaProtectionRequest $req) use (&$captured) {
            $captured = $req;

            return new ModifySpartaProtectionResponse;
        });

    $deployer = tencentWafDeployerWith(fn (string $kind) => $kind === 'waf' ? $waf : new stdClass);
    $deployer->bind('cert-waf', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'instance_id' => 'waf-inst-1',
        'domain' => 'waf.example.com',
        'domain_id' => 'waf-domain-1',
        'region' => 'ap-guangzhou',
    ]);

    // InstanceID 大写 ID（区别于 DescribeDomainDetailsSaas 的 InstanceId）
    expect($captured->InstanceID)->toBe('waf-inst-1');
    expect($captured->Domain)->toBe('waf.example.com');
    expect($captured->DomainId)->toBe('waf-domain-1');
    // CertType=2 托管证书，整数
    expect($captured->CertType)->toBe(2);
    expect($captured->CertType)->toBeInt();
    // SSLId 大写 SSL（托管证书 id）
    expect($captured->SSLId)->toBe('cert-waf');
});

test('WAF bind 把 region 透传进 waf client（按 region 实例化）', function () {
    $seenRegion = null;
    $waf = Mockery::mock(WafClient::class);
    $waf->shouldReceive('ModifySpartaProtection')->andReturn(new ModifySpartaProtectionResponse);

    $deployer = tencentWafDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $waf) {
        if ($kind === 'waf') {
            $seenRegion = $region;

            return $waf;
        }

        return new stdClass;
    });
    $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'instance_id' => 'waf-x', 'domain' => 'waf.example.com', 'domain_id' => 'd-x', 'region' => 'ap-beijing',
    ]);

    expect($seenRegion)->toBe('ap-beijing');
});

test('WAF 缺 instance_id 配置抛业务错误', function () {
    $deployer = tencentWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'domain' => 'waf.example.com', 'domain_id' => 'd-x', 'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 instance_id');
});

test('WAF 缺 domain 配置抛业务错误', function () {
    $deployer = tencentWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'instance_id' => 'waf-x', 'domain_id' => 'd-x', 'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('WAF 缺 domain_id 配置抛业务错误', function () {
    $deployer = tencentWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'instance_id' => 'waf-x', 'domain' => 'waf.example.com', 'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 domain_id');
});

test('WAF 缺 region 配置抛业务错误（region 必填，client 构造需要）', function () {
    $deployer = tencentWafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'instance_id' => 'waf-x', 'domain' => 'waf.example.com', 'domain_id' => 'd-x',
    ]))->toThrow(RuntimeException::class, '缺少配置 region');
});

test('WAF bind SDK 抛 TencentCloudSDKException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $waf = Mockery::mock(WafClient::class);
    $waf->shouldReceive('ModifySpartaProtection')->andThrow(new TencentCloudSDKException('FailedOperation', 'modify sparta failed', 'req-1'));

    $deployer = tencentWafDeployerWith(fn () => $waf);

    try {
        $deployer->bind('cert-waf', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'instance_id' => 'waf-x', 'domain' => 'waf.example.com', 'domain_id' => 'd-x', 'region' => 'ap-guangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation')->toContain('modify sparta failed');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
    }
});

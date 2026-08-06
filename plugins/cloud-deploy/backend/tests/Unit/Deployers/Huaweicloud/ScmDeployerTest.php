<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Plugins\CloudDeploy\Deployers\Huaweicloud\ScmDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

// scmHost 内做 host 校验：stub 策略放行公网 host，注入场景由授权测试覆盖
beforeEach(function () {
    app()->instance(OutboundDestinationPolicy::class, new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
    ));
});

/**
 * 测试子类：override makeClient（3 参，含 region）注入缝，按 $kind 返回 mock（scm）。
 * uploader 经 certUploader() 复用同一 makeClient('scm')，故 mock scm 即覆盖上传路径。
 */
function hwScmDeployerWith(callable $clientFactory): ScmDeployer
{
    return new class($clientFactory) extends ScmDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function hwScmCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('华为云 SCM：纯上传端点（usesRemoteCertStore + storeKind huawei_scm + bind no-op）', function () {
    $deployer = new ScmDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('huawei_scm');
    expect($deployer->provider())->toBe('huaweicloud');
    expect($deployer->product())->toBe('scm');

    // bind 是 no-op：注入会抛异常的 client，bind 仍不触碰它，不抛异常。
    $neverCalled = hwScmDeployerWith(fn () => Mockery::mock()->shouldReceive('post')->never()->getMock());
    $neverCalled->bind('scm-cert-1', hwScmCreds(), []);
    expect(true)->toBeTrue();
});

test('uploader.upload 调 POST /v3/scm/certificates/import 返回 certificate_id（certificate=完整链, private_key=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $path, ?array $body) use (&$captured) {
            $captured = compact('path', 'body');

            return ['certificate_id' => 'scm-abc-001'];
        });

    $deployer = hwScmDeployerWith(fn (string $kind) => $kind === 'scm' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', hwScmCreds());

    expect($id)->toBe('scm-abc-001');
    expect($captured['path'])->toBe('/v3/scm/certificates/import');
    $b = $captured['body'];
    expect($b['name'])->toStartWith('clouddeploy_');
    expect($b['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($b['private_key'])->toBe('KEYPEM');
    // 未传企业项目 → body 不含 enterprise_project_id
    expect($b)->not->toHaveKey('enterprise_project_id');
});

test('uploader.upload 凭证含 enterprise_project_id 时透传', function () {
    $captured = null;
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andReturnUsing(function (string $path, ?array $body) use (&$captured) {
        $captured = $body;

        return ['certificate_id' => 'scm-1'];
    });

    $deployer = hwScmDeployerWith(fn () => $client);
    $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'secret_access_key' => 'SK', 'enterprise_project_id' => 'ep-9']);

    expect($captured['enterprise_project_id'])->toBe('ep-9');
});

test('upload 未返回 certificate_id 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andReturn(['foo' => 'bar']);

    $deployer = hwScmDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', hwScmCreds()))
        ->toThrow(RuntimeException::class, 'certificate_id');
});

test('upload SDK 抛 HuaweicloudApiException 时脱敏（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new HuaweicloudApiException('SCM.0001', '证书重复'));

    $deployer = hwScmDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('SCM.0001')->toContain('证书重复');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('upload SDK 抛网络类异常（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException('cURL error 7: connect https://scm.cn-north-4.myhuaweicloud.com/ with AK-LEAK'));

    $deployer = hwScmDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('华为云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('scm.cn-north-4.myhuaweicloud.com');
        expect($e->getPrevious())->toBeNull();
    }
});

test('certUploader 据 config.region 构造 SCM client host（区域生效）', function () {
    $capturedRegion = null;
    $client = Mockery::mock(HuaweicloudRestClient::class);
    $client->shouldReceive('post')->andReturn(['certificate_id' => 'scm-1']);

    $deployer = hwScmDeployerWith(function (string $kind, array $creds, string $region) use (&$capturedRegion, $client) {
        $capturedRegion = $region;

        return $client;
    });

    $deployer->certUploader(['region' => 'cn-east-3'])->upload('C', 'K', 'CH', hwScmCreds());
    expect($capturedRegion)->toBe('cn-east-3');
});

test('scmHost region 含 URL 分隔符时即使目标解析为公网也被拒绝', function () {
    $deployer = new ScmDeployer;
    $method = (new ReflectionClass(ScmDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 'scm', hwScmCreds(), 'public.example:443/path'))
        ->toThrow(OutboundDestinationException::class);
});

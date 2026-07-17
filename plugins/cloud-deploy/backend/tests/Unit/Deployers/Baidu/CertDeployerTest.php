<?php

use BaiduBce\Exception\BceClientException;
use BaiduBce\Exception\BceServiceException;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduCertDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cert）。
 * uploader 经 certUploader() 复用同一 makeClient('cert')，故 mock cert 即覆盖上传路径。
 */
function baiduCertDeployerWith(callable $clientFactory): BaiduCertDeployer
{
    return new class($clientFactory) extends BaiduCertDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baiduCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('百度证书中心：纯上传端点（usesRemoteCertStore + storeKind baidu_cert + bind no-op）', function () {
    $deployer = new BaiduCertDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('baidu_cert');
    expect($deployer->provider())->toBe('baidu');
    expect($deployer->product())->toBe('cert');
    expect($deployer->configSchema())->toBe([]);

    // bind 是 no-op：不调用任何 SDK（注入会抛异常的 client，bind 仍不触碰它），不抛异常。
    $neverCalled = baiduCertDeployerWith(fn () => Mockery::mock()->shouldReceive('request')->never()->getMock());
    $neverCalled->bind('cert-123', baiduCreds(), []);
    expect(true)->toBeTrue();
});

test('uploader.upload 调 POST /v1/certificate 返回 certId（请求体 certName/certServerData/certPrivateData）', function () {
    $captured = null;
    $client = Mockery::mock();
    $client->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$captured) {
            $captured = compact('method', 'path', 'body', 'params');

            return (object) ['certId' => 'cert-abc-001', 'certName' => 'clouddeploy_x'];
        });

    $deployer = baiduCertDeployerWith(fn (string $kind) => $kind === 'cert' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', baiduCreds());

    expect($id)->toBe('cert-abc-001');
    expect($captured['method'])->toBe('POST');
    expect($captured['path'])->toBe('/v1/certificate');
    expect($captured['body']['certServerData'])->toBe('CERTPEM');
    expect($captured['body']['certPrivateData'])->toBe('KEYPEM');
    expect($captured['body']['certName'])->toStartWith('clouddeploy_');
    // 对齐 certimate：CreateCert 不外发中间证书（无 certLinkData 键）。
    expect($captured['body'])->not->toHaveKey('certLinkData');
});

test('upload 未返回 certId 时抛明确异常（非 TypeError）', function () {
    $client = Mockery::mock();
    $client->shouldReceive('request')->andReturn((object) ['certName' => 'x']);

    $deployer = baiduCertDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', baiduCreds()))
        ->toThrow(RuntimeException::class, 'certId');
});

test('upload SDK 抛 BceServiceException 时脱敏（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock();
    $client->shouldReceive('request')->andThrow(new BceServiceException('req-1', 'AccessDenied', 'permission denied', 403));

    $deployer = baiduCertDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AccessDenied');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('upload SDK 抛网络类 BceClientException（含 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock();
    $client->shouldReceive('request')->andThrow(new BceClientException('cURL error 7: connect https://certificate.baidubce.com/v1/certificate'));

    $deployer = baiduCertDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', baiduCreds());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('百度智能云调用失败');
        expect($e->getMessage())->not->toContain('certificate.baidubce.com');
        expect($e->getPrevious())->toBeNull();
    }
});

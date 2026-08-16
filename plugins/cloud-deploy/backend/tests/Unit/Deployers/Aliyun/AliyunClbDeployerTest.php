<?php

use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeDomainExtensionsRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeLoadBalancerAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeLoadBalancerHTTPSListenerAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeLoadBalancerListenersRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\SetDomainExtensionAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\SetLoadBalancerHTTPSListenerAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\SetLoadBalancerHTTPSListenerAttributeResponse;
use AlibabaCloud\SDK\Slb\V20140515\Models\UploadServerCertificateRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\UploadServerCertificateResponse;
use AlibabaCloud\SDK\Slb\V20140515\Models\UploadServerCertificateResponseBody;
use AlibabaCloud\SDK\Slb\V20140515\Slb;
use AlibabaCloud\Tea\Exception\TeaError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasUploader;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunClbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunSlbUploader;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Models\CloudDeployRemoteCert;
use Plugins\CloudDeploy\Services\RemoteCertStore;
use Tests\TestCase;

// RefreshDatabase 必须文件级声明：插件测试不在主 Pest.php 的 ->in(...) 目录绑定范围内，
// 且 store_kind 隔离用例写 cloud_deploy_remote_certs，需事务回滚防跨用例/跨运行污染。
uses(TestCase::class, RefreshDatabase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（slb）。
 * uploader 经 certUploader($config) 复用同一 makeClient('slb', cred, region)，故 mock slb 即覆盖上传 + 绑定。
 * 注：makeClient 带第三个 region 参数（上传与绑定都按 region 实例化），闭包按需接收。
 */
function aliyunClbDeployerWith(callable $clientFactory): AliyunClbDeployer
{
    return new class($clientFactory) extends AliyunClbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function slbUploadResponse(string $serverCertId): UploadServerCertificateResponse
{
    return new UploadServerCertificateResponse(['body' => new UploadServerCertificateResponseBody([
        'serverCertificateId' => $serverCertId,
    ])]);
}

test('阿里云 CLB 走证书服务（SLB 服务证书）+ 基本元信息', function () {
    $deployer = new AliyunClbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('clb');
    expect($deployer->label())->toBe('阿里云传统型负载均衡 CLB');
    // configSchema 覆盖 bind 实际读取的三项
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('deploy_target')->toContain('load_balancer_id')->toContain('listener_port')->toContain('domain')->toContain('region');
});

test('bind loadbalancer：列 HTTPS 监听端口并批量更新主证书', function () {
    $listReq = null;
    $updated = [];
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('describeLoadBalancerAttribute')->once()->with(Mockery::on(fn (DescribeLoadBalancerAttributeRequest $r) => $r->loadBalancerId === 'lb-1'))->andReturn(new stdClass);
    $slb->shouldReceive('describeLoadBalancerListeners')->once()->andReturnUsing(function (DescribeLoadBalancerListenersRequest $req) use (&$listReq) {
        $listReq = $req;

        return (object) ['body' => (object) ['listeners' => [
            (object) ['listenerPort' => 443],
            (object) ['listenerPort' => 8443],
        ]]];
    });
    $slb->shouldReceive('describeLoadBalancerHTTPSListenerAttribute')->twice()->with(Mockery::type(DescribeLoadBalancerHTTPSListenerAttributeRequest::class))->andReturn((object) ['body' => (object) ['serverCertificateId' => 'old']]);
    $slb->shouldReceive('setLoadBalancerHTTPSListenerAttribute')->twice()->andReturnUsing(function (SetLoadBalancerHTTPSListenerAttributeRequest $req) use (&$updated) {
        $updated[] = $req->listenerPort;

        return new SetLoadBalancerHTTPSListenerAttributeResponse;
    });

    $deployer = aliyunClbDeployerWith(fn (string $kind) => $kind === 'slb' ? $slb : new stdClass);
    $deployer->bind('cert-new', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-hangzhou', 'deploy_target' => 'loadbalancer', 'load_balancer_id' => 'lb-1',
    ]);

    expect($listReq->listenerProtocol)->toBe('https');
    expect($listReq->maxResults)->toBe(100);
    expect($updated)->toBe([443, 8443]);
});

test('bind SNI：仅替换精确匹配扩展域名且证书不同的条目', function () {
    $captured = null;
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('describeLoadBalancerHTTPSListenerAttribute')
        ->once()
        ->with(Mockery::type(DescribeLoadBalancerHTTPSListenerAttributeRequest::class))
        ->andReturn((object) ['body' => (object) ['serverCertificateId' => 'listener-default']]);
    $slb->shouldReceive('describeDomainExtensions')
        ->once()
        ->with(Mockery::on(fn (DescribeDomainExtensionsRequest $r) => $r->loadBalancerId === 'lb-1' && $r->listenerPort === 443))
        ->andReturn((object) ['body' => (object) ['domainExtensions' => (object) ['domainExtension' => [
            (object) ['domain' => 'api.example.com', 'serverCertificateId' => 'old-cert', 'domainExtensionId' => 'ext-1'],
            (object) ['domain' => 'www.example.com', 'serverCertificateId' => 'old-cert', 'domainExtensionId' => 'ext-2'],
            (object) ['domain' => 'api.example.com', 'serverCertificateId' => 'cert-new', 'domainExtensionId' => 'ext-3'],
        ]]]]);
    $slb->shouldReceive('setDomainExtensionAttribute')
        ->once()
        ->andReturnUsing(function (SetDomainExtensionAttributeRequest $req) use (&$captured) {
            $captured = $req;

            return new stdClass;
        });

    $deployer = aliyunClbDeployerWith(fn () => $slb);
    $deployer->bind('cert-new', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'cn-hangzhou', 'load_balancer_id' => 'lb-1', 'listener_port' => 443,
        'domain' => 'api.example.com',
    ]);

    expect($captured->regionId)->toBe('cn-hangzhou');
    expect($captured->domainExtensionId)->toBe('ext-1');
    expect($captured->serverCertificateId)->toBe('cert-new');
});

test('certUploader 是 SLB 上传器，storeKind 含 region（隔离不同 region 标识空间）', function () {
    $deployer = new AliyunClbDeployer;
    $uploader = $deployer->certUploader(['region' => 'cn-shanghai']);
    expect($uploader)->toBeInstanceOf(AliyunSlbUploader::class);
    expect($uploader->storeKind())->toBe('slb:cn-shanghai');

    // 不同 region → 不同 store_kind
    expect($deployer->certUploader(['region' => 'cn-hangzhou'])->storeKind())->toBe('slb:cn-hangzhou');
    // 区别于 CAS 系（storeKind=cas）
    expect($uploader->storeKind())->not->toBe('cas');
});

test('uploader.upload 调 slb.UploadServerCertificate（完整链+key+RegionId+唯一名）返回裸 ServerCertificateId', function () {
    $uploadReq = null;
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('uploadServerCertificate')
        ->once()
        ->andReturnUsing(function (UploadServerCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return slbUploadResponse('cert-svr-abc123');
        });

    $deployer = aliyunClbDeployerWith(fn (string $kind) => $kind === 'slb' ? $slb : new stdClass);
    $id = $deployer->certUploader(['region' => 'cn-hangzhou'])
        ->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    // 返回裸 ServerCertificateId（region 已进 store_kind，不编码进 id）
    expect($id)->toBe('cert-svr-abc123');
    // 上传：完整链（cert+chain）+ key + region + 唯一名
    expect($uploadReq->serverCertificate)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->privateKey)->toBe('KEYPEM');
    expect($uploadReq->regionId)->toBe('cn-hangzhou');
    expect($uploadReq->serverCertificateName)->toStartWith('clouddeploy_');
});

test('upload 未返回 ServerCertificateId 时抛明确异常（非 TypeError）', function () {
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('uploadServerCertificate')
        ->andReturn(new UploadServerCertificateResponse(['body' => new UploadServerCertificateResponseBody([])]));

    $deployer = aliyunClbDeployerWith(fn () => $slb);

    expect(fn () => $deployer->certUploader(['region' => 'cn-hangzhou'])
        ->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']))
        ->toThrow(RuntimeException::class, 'ServerCertificateId');
});

test('bind 调 slb.SetLoadBalancerHTTPSListenerAttribute（LoadBalancerId+ListenerPort(int)+ServerCertificateId+RegionId）', function () {
    $captured = null;
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('describeLoadBalancerHTTPSListenerAttribute')
        ->once()
        ->andReturn((object) ['body' => (object) ['serverCertificateId' => 'old-cert']]);
    $slb->shouldReceive('setLoadBalancerHTTPSListenerAttribute')
        ->once()
        ->andReturnUsing(function (SetLoadBalancerHTTPSListenerAttributeRequest $req) use (&$captured) {
            $captured = $req;

            return new SetLoadBalancerHTTPSListenerAttributeResponse;
        });

    $deployer = aliyunClbDeployerWith(fn (string $kind) => $kind === 'slb' ? $slb : new stdClass);
    $deployer->bind('cert-svr-xyz', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'load_balancer_id' => 'lb-bp1abc',
        'listener_port' => '443',
        'region' => 'cn-hangzhou',
    ]);

    expect($captured->loadBalancerId)->toBe('lb-bp1abc');
    expect($captured->listenerPort)->toBe(443);
    expect($captured->listenerPort)->toBeInt(); // 端口规整为 int
    expect($captured->serverCertificateId)->toBe('cert-svr-xyz');
    expect($captured->regionId)->toBe('cn-hangzhou');
});

test('bind 把 region 透传进 slb client endpoint（按 region 实例化）', function () {
    $seenRegion = null;
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('describeLoadBalancerHTTPSListenerAttribute')
        ->once()
        ->andReturn((object) ['body' => (object) ['serverCertificateId' => 'old-cert']]);
    $slb->shouldReceive('setLoadBalancerHTTPSListenerAttribute')->andReturn(new SetLoadBalancerHTTPSListenerAttributeResponse);

    $deployer = aliyunClbDeployerWith(function (string $kind, array $cred, string $region) use (&$seenRegion, $slb) {
        if ($kind === 'slb') {
            $seenRegion = $region;

            return $slb;
        }

        return new stdClass;
    });
    $deployer->bind('cert-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'load_balancer_id' => 'lb-x',
        'listener_port' => 443,
        'region' => 'ap-southeast-1',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
});

test('缺 load_balancer_id 配置抛业务错误', function () {
    $deployer = aliyunClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'listener_port' => 443, 'region' => 'cn-hangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 load_balancer_id');
});

test('缺 listener_port 配置抛业务错误', function () {
    $deployer = aliyunClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'load_balancer_id' => 'lb-x', 'region' => 'cn-hangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 listener_port');
});

test('缺 region 配置抛业务错误', function () {
    $deployer = aliyunClbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'load_balancer_id' => 'lb-x', 'listener_port' => 443,
    ]))->toThrow(RuntimeException::class, '缺少配置 region');
});

test('bind SDK 抛 TeaError（API 错误）时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('describeLoadBalancerHTTPSListenerAttribute')
        ->once()
        ->andReturn((object) ['body' => (object) ['serverCertificateId' => 'old-cert']]);
    $slb->shouldReceive('setLoadBalancerHTTPSListenerAttribute')->andThrow(new TeaError([
        'code' => 'ListenerNotFound',
        'message' => 'code: 404 request id: req-1',
        'data' => ['Code' => 'ListenerNotFound', 'Message' => 'the listener does not exist', 'RequestId' => 'req-1'],
    ]));

    $deployer = aliyunClbDeployerWith(fn (string $kind) => $kind === 'slb' ? $slb : new stdClass);

    try {
        $deployer->bind('cert-1', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'load_balancer_id' => 'lb-x', 'listener_port' => 443, 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ListenerNotFound')->toContain('the listener does not exist');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('upload SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名（TeaError extends RuntimeException 不透传）', function () {
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('uploadServerCertificate')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://slb.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunClbDeployerWith(fn () => $slb);

    try {
        $deployer->certUploader(['region' => 'cn-hangzhou'])
            ->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| store_kind 隔离（SLB vs CAS）：用真实 AliyunSlbUploader + AliyunCasUploader 走 RemoteCertStore，
| 验证同 access + 同证书但不同 store_kind 各自上传、互不复用（ServerCertificateId 与 CAS CertId
| 是不同标识空间）。这是 Phase 2 批 3 引入第二类上传器的核心安全不变量。
|--------------------------------------------------------------------------
*/
test('store_kind 隔离：真实 SLB 与 CAS 上传器同 access+证书不跨空间复用', function () {
    // SLB 上传器（storeKind=slb:cn-hangzhou）：mock client 返回 SLB 风格 id
    $slb = Mockery::mock(Slb::class);
    $slb->shouldReceive('uploadServerCertificate')->once()->andReturn(slbUploadResponse('slb-server-cert-1'));
    $slbUploader = new AliyunSlbUploader(fn (array $c): object => $slb, 'cn-hangzhou');

    // CAS 上传器（storeKind=cas）：用一个最小 fake CAS（不触网，仅返回固定 CertIdentifier）
    $casUploader = new class implements CertUploaderInterface
    {
        public function storeKind(): string
        {
            return 'cas';
        }

        public function upload(string $c, string $k, string $ch, array $cred): string
        {
            return '777-cn-hangzhou';
        }
    };

    $store = new RemoteCertStore;
    $slbId = $store->ensure($slbUploader, accessId: 50, userId: 1, certId: 100, fingerprint: 'AA:BB', certPem: 'C', keyPem: 'K', chainPem: 'CH', credentials: ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);
    $casId = $store->ensure($casUploader, accessId: 50, userId: 1, certId: 100, fingerprint: 'AA:BB', certPem: 'C', keyPem: 'K', chainPem: 'CH', credentials: ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($slbId)->toBe('slb-server-cert-1');
    expect($casId)->toBe('777-cn-hangzhou'); // 不被 SLB 行命中复用（store_kind 不同）

    // 落两行：slb:cn-hangzhou 与 cas 各一，互不串
    expect(CloudDeployRemoteCert::where('access_id', 50)->where('fingerprint', 'AA:BB')->count())->toBe(2);
    expect(CloudDeployRemoteCert::where('access_id', 50)->where('store_kind', 'slb:cn-hangzhou')->value('remote_cert_id'))->toBe('slb-server-cert-1');
    expect(CloudDeployRemoteCert::where('access_id', 50)->where('store_kind', 'cas')->value('remote_cert_id'))->toBe('777-cn-hangzhou');
});

test('store_kind 跨 region 隔离：同证书部署到两 region 各落一行、各持本 region 的 ServerCertificateId', function () {
    $slbHz = Mockery::mock(Slb::class);
    $slbHz->shouldReceive('uploadServerCertificate')->once()->andReturn(slbUploadResponse('cert-hz'));
    $slbSh = Mockery::mock(Slb::class);
    $slbSh->shouldReceive('uploadServerCertificate')->once()->andReturn(slbUploadResponse('cert-sh'));

    $store = new RemoteCertStore;
    $idHz = $store->ensure(new AliyunSlbUploader(fn (array $c): object => $slbHz, 'cn-hangzhou'), 60, 1, 100, 'CC:DD', 'C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);
    $idSh = $store->ensure(new AliyunSlbUploader(fn (array $c): object => $slbSh, 'cn-shanghai'), 60, 1, 100, 'CC:DD', 'C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    // region 进 store_kind → 不误把杭州的 cert id 复用给上海
    expect($idHz)->toBe('cert-hz');
    expect($idSh)->toBe('cert-sh');
    expect(CloudDeployRemoteCert::where('access_id', 60)->where('fingerprint', 'CC:DD')->count())->toBe(2);
});

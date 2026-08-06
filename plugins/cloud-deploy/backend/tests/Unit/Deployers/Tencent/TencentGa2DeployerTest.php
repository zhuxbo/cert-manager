<?php

use Mockery\MockInterface;
use Plugins\CloudDeploy\Deployers\Tencent\TencentGa2Deployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUploader;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use TencentCloud\Common\CommonClient;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificateResponse;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（ga2 / ssl kind）。 */
function tencentGa2DeployerWith(callable $clientFactory): TencentGa2Deployer
{
    return new class($clientFactory) extends TencentGa2Deployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ga2Creds(): array
{
    return ['secret_id' => 'AK', 'secret_key' => 'SK'];
}

/** 构造一个返回指定 SAN 的 SslClient mock（DescribeCertificate）。 */
function ga2SslMockWithSans(array $sansByCertId): MockInterface
{
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('DescribeCertificate')->andReturnUsing(function (DescribeCertificateRequest $req) use ($sansByCertId) {
        $resp = new DescribeCertificateResponse;
        $resp->deserialize(['SubjectAltName' => $sansByCertId[$req->CertificateId] ?? [], 'RequestId' => 'r']);

        return $resp;
    });

    return $ssl;
}

test('腾讯云 GA2 为证书服务型（复用 TencentSslUploader）+ 元信息', function () {
    $deployer = new TencentGa2Deployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('ga2');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(TencentSslUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('tencent_ssl');
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('accelerator_id')->toContain('listener_id')->toContain('endpoint');
});

test('uploader 走 SSL UploadCertificate 返回 CertId', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->once()->andReturnUsing(function (UploadCertificateRequest $req) {
        $resp = new UploadCertificateResponse;
        $resp->deserialize(['CertificateId' => 'cert-new', 'RequestId' => 'r']);

        return $resp;
    });

    $deployer = tencentGa2DeployerWith(fn (string $kind) => $ssl);
    $id = $deployer->certUploader()->upload('LEAF', 'KEY', 'CHAIN', ga2Creds());
    expect($id)->toBe('cert-new');
});

test('bind：监听器无证书时直接绑新证书', function () {
    $calls = [];
    $ga2 = Mockery::mock(CommonClient::class);
    $ga2->shouldReceive('callJson')->andReturnUsing(function (string $action, array $body) use (&$calls) {
        $calls[$action] = $body;
        if ($action === 'DescribeListeners') {
            return ['ListenerSet' => [['ServerCertificates' => []]]];
        }

        return ['ListenerId' => $body['ListenerId']];
    });
    $ssl = ga2SslMockWithSans(['cert-new' => ['a.example.com']]);

    $deployer = tencentGa2DeployerWith(fn (string $kind) => $kind === 'ga2' ? $ga2 : $ssl);
    $deployer->bind('cert-new', ga2Creds(), ['accelerator_id' => 'ga-1', 'listener_id' => 'lsn-1']);

    expect($calls['DescribeListeners']['GlobalAcceleratorId'])->toBe('ga-1');
    expect($calls['DescribeListeners']['Filters'][0])->toBe(['Name' => 'listener-id', 'Values' => ['lsn-1']]);
    expect($calls['ModifyListener']['ServerCertificates'])->toBe(['cert-new']);
    expect($calls['ModifyListener']['ListenerId'])->toBe('lsn-1');
});

test('bind：同 SAN 旧证书被替换、不同 SAN 旧证书保留', function () {
    $modifyBody = null;
    $ga2 = Mockery::mock(CommonClient::class);
    $ga2->shouldReceive('callJson')->andReturnUsing(function (string $action, array $body) use (&$modifyBody) {
        if ($action === 'DescribeListeners') {
            return ['ListenerSet' => [['ServerCertificates' => ['cert-old-same', 'cert-old-diff']]]];
        }
        $modifyBody = $body;

        return [];
    });
    // 新证书 SAN = [a]; cert-old-same 同为 [a] → 替换；cert-old-diff = [b] → 保留
    $ssl = ga2SslMockWithSans([
        'cert-new' => ['a.example.com'],
        'cert-old-same' => ['a.example.com'],
        'cert-old-diff' => ['b.example.com'],
    ]);

    $deployer = tencentGa2DeployerWith(fn (string $kind) => $kind === 'ga2' ? $ga2 : $ssl);
    $deployer->bind('cert-new', ga2Creds(), ['accelerator_id' => 'ga-1', 'listener_id' => 'lsn-1']);

    expect($modifyBody['ServerCertificates'])->toBe(['cert-old-diff', 'cert-new']);
});

test('bind：新证书已绑定 → 幂等不调 ModifyListener', function () {
    $ga2 = Mockery::mock(CommonClient::class);
    $ga2->shouldReceive('callJson')->once()->with('DescribeListeners', Mockery::any())
        ->andReturn(['ListenerSet' => [['ServerCertificates' => ['cert-new']]]]);
    $ga2->shouldNotReceive('callJson')->with('ModifyListener', Mockery::any());
    $ssl = ga2SslMockWithSans([]);

    $deployer = tencentGa2DeployerWith(fn (string $kind) => $kind === 'ga2' ? $ga2 : $ssl);
    $deployer->bind('cert-new', ga2Creds(), ['accelerator_id' => 'ga-1', 'listener_id' => 'lsn-1']);
});

test('bind：监听器不存在抛业务错误', function () {
    $ga2 = Mockery::mock(CommonClient::class);
    $ga2->shouldReceive('callJson')->with('DescribeListeners', Mockery::any())->andReturn(['ListenerSet' => []]);
    $ssl = ga2SslMockWithSans([]);

    $deployer = tencentGa2DeployerWith(fn (string $kind) => $kind === 'ga2' ? $ga2 : $ssl);
    expect(fn () => $deployer->bind('cert-new', ga2Creds(), ['accelerator_id' => 'ga-1', 'listener_id' => 'lsn-x']))
        ->toThrow(RuntimeException::class, '未找到监听器 lsn-x');
});

test('缺 accelerator_id / listener_id 抛业务错误', function () {
    $deployer = tencentGa2DeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-new', ga2Creds(), ['listener_id' => 'lsn-1']))
        ->toThrow(RuntimeException::class, '缺少配置 accelerator_id');
    expect(fn () => $deployer->bind('cert-new', ga2Creds(), ['accelerator_id' => 'ga-1']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_id');
});

test('bind SDK 抛异常时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $ga2 = Mockery::mock(CommonClient::class);
    $ga2->shouldReceive('callJson')->andThrow(new TencentCloudSDKException('InvalidParameter', 'bad listener', 'req-1'));
    $ssl = ga2SslMockWithSans([]);

    $deployer = tencentGa2DeployerWith(fn (string $kind) => $kind === 'ga2' ? $ga2 : $ssl);
    try {
        $deployer->bind('cert-new', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'accelerator_id' => 'ga-1', 'listener_id' => 'lsn-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidParameter');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});

test('运行时 endpoint 仅允许腾讯 GA2 官方端点', function () {
    $deployer = new TencentGa2Deployer;
    $method = (new ReflectionClass(TencentGa2Deployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    foreach ([
        '127.0.0.1:6443',
        'edge.example.com',
        'ga2.intl.tencentcloudapi.com.attacker.example',
        'evilintl.tencentcloudapi.com',
    ] as $endpoint) {
        expect(fn () => $method->invoke($deployer, 'ga2', [
            'secret_id' => 'AK', 'secret_key' => 'SK', 'endpoint' => $endpoint,
        ]))->toThrow(OutboundDestinationException::class);
    }

    expect($method->invoke($deployer, 'ga2', [
        'secret_id' => 'AK', 'secret_key' => 'SK', 'endpoint' => 'ga2.tencentcloudapi.com',
    ]))->toBeInstanceOf(CommonClient::class);
    expect($method->invoke($deployer, 'ga2', [
        'secret_id' => 'AK', 'secret_key' => 'SK', 'endpoint' => 'ga2.intl.tencentcloudapi.com',
    ]))->toBeInstanceOf(CommonClient::class);
    expect($method->invoke($deployer, 'ssl', [
        'secret_id' => 'AK', 'secret_key' => 'SK', 'endpoint' => 'ga2.intl.tencentcloudapi.com',
    ]))->toBeInstanceOf(SslClient::class);
});

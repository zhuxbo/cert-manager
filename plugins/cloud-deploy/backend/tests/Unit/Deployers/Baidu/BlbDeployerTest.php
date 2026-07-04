<?php

use BaiduBce\Exception\BceServiceException;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduBlbDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient（3 参带 region）注入缝，按 $kind 返回 mock（cert / blb）。
 * uploader 经 certUploader() 复用同一 makeClient('cert')，故 mock cert 即覆盖上传路径。
 */
function baiduBlbDeployerWith(callable $clientFactory): BaiduBlbDeployer
{
    return new class($clientFactory) extends BaiduBlbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function baiduBlbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('百度 BLB：证书服务型（usesRemoteCertStore + storeKind baidu_cert）', function () {
    $deployer = new BaiduBlbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('baidu_cert');
    expect($deployer->provider())->toBe('baidu');
    expect($deployer->product())->toBe('blb');
});

test('listener 目标 + HTTPS 监听（无 SNI）：describe-all 定位类型 → 更新 HTTPS 设 certIds', function () {
    $calls = [];
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$calls) {
        $calls[] = compact('method', 'path', 'body', 'params');
        // GET .../listener?listenerPort=443 → 该端口是 HTTPS
        if ($method === 'GET' && str_ends_with($path, '/listener')) {
            return (object) ['listenerList' => [(object) ['listenerPort' => 443, 'listenerType' => 'HTTPS']]];
        }
        // GET .../HTTPSlistener → 既有监听（无扩展域名）
        if ($method === 'GET' && str_ends_with($path, '/HTTPSlistener')) {
            return (object) ['listenerList' => [(object) ['listenerPort' => 443, 'certIds' => ['old-cert'], 'additionalCertDomains' => []]]];
        }

        return new stdClass; // PUT 返回空
    });

    $deployer = baiduBlbDeployerWith(fn (string $kind) => $kind === 'blb' ? $blb : new stdClass);
    $deployer->bind('cert-NEW', baiduBlbCreds(), [
        'region' => 'bj', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_port' => '443',
    ]);

    // 路径前缀 /v1/blb、host region 维度（mock 不校验 host，校验 path/body/query）
    $describeAll = collect($calls)->firstWhere('path', '/v1/blb/lb-1/listener');
    expect($describeAll)->not->toBeNull();
    expect($describeAll['params'])->toBe(['listenerPort' => 443]);

    $put = collect($calls)->first(fn ($c) => $c['method'] === 'PUT' && $c['path'] === '/v1/blb/lb-1/HTTPSlistener');
    expect($put)->not->toBeNull();
    expect($put['body']['listenerPort'])->toBe(443);
    expect($put['body']['certIds'])->toBe(['cert-NEW']);
    // BLB 的 HTTPS 更新不带 scheduler（区别于 AppBLB）
    expect($put['body'])->not->toHaveKey('scheduler');
    expect($put['params']['listenerPort'])->toBe(443);
    expect($put['params']['clientToken'])->toBeString()->not->toBe('');
});

test('listener 目标 + HTTPS 监听 + SNI：只替换匹配 host 的 additionalCertDomains，保留主证书', function () {
    $putBody = null;
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$putBody) {
        if ($method === 'GET' && str_ends_with($path, '/listener')) {
            return (object) ['listenerList' => [(object) ['listenerPort' => 443, 'listenerType' => 'HTTPS']]];
        }
        if ($method === 'GET' && str_ends_with($path, '/HTTPSlistener')) {
            return (object) ['listenerList' => [(object) [
                'listenerPort' => 443,
                'certIds' => ['main-cert'],
                'additionalCertDomains' => [
                    (object) ['host' => 'a.example.com', 'certId' => 'cert-a'],
                    (object) ['host' => 'sni.example.com', 'certId' => 'cert-old'],
                ],
            ]]];
        }
        if ($method === 'PUT') {
            $putBody = $body;
        }

        return new stdClass;
    });

    $deployer = baiduBlbDeployerWith(fn () => $blb);
    $deployer->bind('cert-NEW', baiduBlbCreds(), [
        'region' => 'bj', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_port' => '443',
        'domain' => 'sni.example.com',
    ]);

    // SNI 模式：主证书保持既有，扩展域名里仅 sni.example.com 换成新证书
    expect($putBody['certIds'])->toBe(['main-cert']);
    expect($putBody['additionalCertDomains'])->toBe([
        ['host' => 'a.example.com', 'certId' => 'cert-a'],
        ['host' => 'sni.example.com', 'certId' => 'cert-NEW'],
    ]);
});

test('loadbalancer 目标：实例详情取全部 HTTPS/SSL 监听 → 各自更新（HTTPS 设 certIds、SSL 设 certIds）', function () {
    $puts = [];
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$puts) {
        // GET /v1/blb/lb-1（详情）
        if ($method === 'GET' && $path === '/v1/blb/lb-1') {
            return (object) ['listener' => [
                (object) ['port' => '443', 'type' => 'HTTPS'],
                (object) ['port' => '8443', 'type' => 'SSL'],
                (object) ['port' => '80', 'type' => 'HTTP'], // 非 HTTPS/SSL，应被忽略
            ]];
        }
        if ($method === 'GET' && str_ends_with($path, '/HTTPSlistener')) {
            return (object) ['listenerList' => [(object) ['listenerPort' => 443, 'certIds' => ['x'], 'additionalCertDomains' => []]]];
        }
        if ($method === 'PUT') {
            $puts[] = compact('path', 'body', 'params');
        }

        return new stdClass;
    });

    $deployer = baiduBlbDeployerWith(fn () => $blb);
    $deployer->bind('cert-NEW', baiduBlbCreds(), [
        'region' => 'gz', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
    ]);

    // 应对 HTTPS:443 与 SSL:8443 各发一个 PUT；HTTP:80 被忽略
    $httpsPut = collect($puts)->firstWhere('path', '/v1/blb/lb-1/HTTPSlistener');
    $sslPut = collect($puts)->firstWhere('path', '/v1/blb/lb-1/SSLlistener');
    expect($httpsPut)->not->toBeNull();
    expect($httpsPut['body']['certIds'])->toBe(['cert-NEW']);
    expect($sslPut)->not->toBeNull();
    expect($sslPut['body']['listenerPort'])->toBe(8443);
    expect($sslPut['body']['certIds'])->toBe(['cert-NEW']);
    expect(collect($puts)->pluck('path')->filter(fn ($p) => str_contains($p, 'listener')))->toHaveCount(2);
});

test('listener 目标端口无 HTTPS/SSL 监听时抛业务错误', function () {
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andReturn((object) ['listenerList' => []]);

    $deployer = baiduBlbDeployerWith(fn () => $blb);
    expect(fn () => $deployer->bind('cert-1', baiduBlbCreds(), [
        'region' => 'bj', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1', 'listener_port' => '8888',
    ]))->toThrow(RuntimeException::class, '8888');
});

test('不支持的 deploy_target 抛业务错误（且不调用 SDK）', function () {
    $deployer = baiduBlbDeployerWith(fn () => Mockery::mock()->shouldReceive('request')->never()->getMock());
    expect(fn () => $deployer->bind('cert-1', baiduBlbCreds(), [
        'region' => 'bj', 'deploy_target' => 'unknown', 'loadbalancer_id' => 'lb-1',
    ]))->toThrow(RuntimeException::class, 'deploy_target');
});

test('缺 region / loadbalancer_id 抛业务错误', function () {
    $deployer = baiduBlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', baiduBlbCreds(), ['deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('cert-1', baiduBlbCreds(), ['region' => 'bj', 'deploy_target' => 'loadbalancer']))
        ->toThrow(RuntimeException::class, '缺少配置 loadbalancer_id');
});

test('listener 目标缺 listener_port 抛业务错误', function () {
    $deployer = baiduBlbDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', baiduBlbCreds(), ['region' => 'bj', 'deploy_target' => 'listener', 'loadbalancer_id' => 'lb-1']))
        ->toThrow(RuntimeException::class, '缺少配置 listener_port');
});

test('bind SDK 抛 BceServiceException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andThrow(new BceServiceException('req-1', 'NoSuchLB', 'lb not found', 404));

    $deployer = baiduBlbDeployerWith(fn () => $blb);

    try {
        $deployer->bind('cert-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'region' => 'bj', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'lb-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NoSuchLB');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

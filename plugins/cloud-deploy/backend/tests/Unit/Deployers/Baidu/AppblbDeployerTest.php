<?php

use BaiduBce\Exception\BceServiceException;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduAppblbDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（3 参带 region）注入缝，按 $kind 返回 mock（cert / blb）。 */
function baiduAppblbDeployerWith(callable $clientFactory): BaiduAppblbDeployer
{
    return new class($clientFactory) extends BaiduAppblbDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function baiduAppblbCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('百度 AppBLB：证书服务型（usesRemoteCertStore + storeKind baidu_cert）', function () {
    $deployer = new BaiduAppblbDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('baidu_cert');
    expect($deployer->provider())->toBe('baidu');
    expect($deployer->product())->toBe('appblb');
});

test('AppBLB 路径前缀为 /v1/appblb，HTTPS 更新回填 scheduler（区别于 BLB）', function () {
    $calls = [];
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$calls) {
        $calls[] = compact('method', 'path', 'body', 'params');
        if ($method === 'GET' && str_ends_with($path, '/listener')) {
            return (object) ['listenerList' => [(object) ['listenerPort' => 443, 'listenerType' => 'HTTPS']]];
        }
        if ($method === 'GET' && str_ends_with($path, '/HTTPSlistener')) {
            return (object) ['listenerList' => [(object) [
                'listenerPort' => 443, 'scheduler' => 'RoundRobin', 'certIds' => ['old'], 'additionalCertDomains' => [],
            ]]];
        }

        return new stdClass;
    });

    $deployer = baiduAppblbDeployerWith(fn (string $kind) => $kind === 'blb' ? $blb : new stdClass);
    $deployer->bind('cert-NEW', baiduAppblbCreds(), [
        'region' => 'bj', 'deploy_target' => 'listener', 'loadbalancer_id' => 'app-lb-1', 'listener_port' => '443',
    ]);

    // describe-all 路径用 appblb 前缀
    expect(collect($calls)->firstWhere('path', '/v1/appblb/app-lb-1/listener'))->not->toBeNull();

    $put = collect($calls)->first(fn ($c) => $c['method'] === 'PUT' && $c['path'] === '/v1/appblb/app-lb-1/HTTPSlistener');
    expect($put)->not->toBeNull();
    expect($put['body']['certIds'])->toBe(['cert-NEW']);
    // AppBLB 关键差异：HTTPS 更新带回既有 scheduler
    expect($put['body']['scheduler'])->toBe('RoundRobin');
});

test('AppBLB SSL 监听更新走 /v1/appblb/{id}/SSLlistener', function () {
    $puts = [];
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$puts) {
        if ($method === 'GET' && str_ends_with($path, '/listener')) {
            return (object) ['listenerList' => [(object) ['listenerPort' => 8443, 'listenerType' => 'SSL']]];
        }
        if ($method === 'PUT') {
            $puts[] = compact('path', 'body');
        }

        return new stdClass;
    });

    $deployer = baiduAppblbDeployerWith(fn () => $blb);
    $deployer->bind('cert-NEW', baiduAppblbCreds(), [
        'region' => 'bj', 'deploy_target' => 'listener', 'loadbalancer_id' => 'app-lb-1', 'listener_port' => '8443',
    ]);

    $sslPut = collect($puts)->firstWhere('path', '/v1/appblb/app-lb-1/SSLlistener');
    expect($sslPut)->not->toBeNull();
    expect($sslPut['body']['certIds'])->toBe(['cert-NEW']);
    expect($sslPut['body']['listenerPort'])->toBe(8443);
});

test('bind SDK 抛 BceServiceException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $blb = Mockery::mock();
    $blb->shouldReceive('request')->andThrow(new BceServiceException('req-1', 'NoSuchLB', 'lb not found', 404));

    $deployer = baiduAppblbDeployerWith(fn () => $blb);

    try {
        $deployer->bind('cert-1', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], [
            'region' => 'bj', 'deploy_target' => 'loadbalancer', 'loadbalancer_id' => 'app-lb-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('NoSuchLB');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});

test('region 含 URL 分隔符时在调用客户端前被拒绝', function () {
    $deployer = new BaiduAppblbDeployer;
    $method = (new ReflectionClass(BaiduAppblbDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 'blb', baiduAppblbCreds(), 'public.example:443/path'))
        ->toThrow(OutboundDestinationException::class);
});

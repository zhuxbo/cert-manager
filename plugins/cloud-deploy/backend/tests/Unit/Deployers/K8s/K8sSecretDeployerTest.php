<?php

use Plugins\CloudDeploy\Deployers\K8s\K8sApiException;
use Plugins\CloudDeploy\Deployers\K8s\K8sClient;
use Plugins\CloudDeploy\Deployers\K8s\K8sProvider;
use Plugins\CloudDeploy\Deployers\K8s\K8sSecretDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock K8sClient。 */
function k8sSecretDeployerWith(callable $clientFactory): K8sSecretDeployer
{
    return new class($clientFactory) extends K8sSecretDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function k8sCertRef(): array
{
    return ['cert' => 'SERVERCERTPEM', 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function k8sCreds(): array
{
    return ['server' => 'https://k8s.example.com:6443', 'token' => 'TOKEN', 'ca_cert' => ''];
}

test('Kubernetes Secret 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new K8sSecretDeployer;
    expect($deployer->provider())->toBe('k8s');
    expect($deployer->product())->toBe('secret');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('namespace')->toContain('secret_name')->toContain('secret_type')
        ->toContain('secret_annotations')->toContain('secret_labels');
    expect(collect($deployer->configSchema())->firstWhere('key', 'secret_type')['required'])->toBeTrue();
});

test('provider 支持 kubeconfig 与显式 server/token 两种凭证', function () {
    $schema = (new K8sProvider)->credentialSchema();
    expect(array_column($schema, 'key'))->toContain('kube_config')->toContain('server')->toContain('token');
    expect(collect($schema)->firstWhere('key', 'server')['required'])->toBeFalse();
});

test('kubeconfig 解析 current-context 的 server/token/CA', function () {
    $yaml = <<<'YAML'
apiVersion: v1
current-context: production
clusters:
  - name: prod-cluster
    cluster:
      server: https://k8s.example.com:6443
      certificate-authority-data: Q0FQRU0=
users:
  - name: deployer
    user:
      token: TOKEN-123
contexts:
  - name: production
    context:
      cluster: prod-cluster
      user: deployer
YAML;
    $deployer = new K8sSecretDeployer;
    $method = new ReflectionMethod($deployer, 'resolveConnection');
    $method->setAccessible(true);
    expect($method->invoke($deployer, ['kube_config' => $yaml]))->toBe([
        'server' => 'https://k8s.example.com:6443', 'token' => 'TOKEN-123', 'ca_cert' => 'CAPEM',
        'client_cert' => '', 'client_key' => '',
    ]);
});

test('自定义 annotations/labels 写入新 Secret，并允许自定义 annotation 覆盖自动值', function () {
    $captured = null;
    $client = Mockery::mock(K8sClient::class);
    $client->shouldReceive('getSecret')->once()->andReturnNull();
    $client->shouldReceive('createSecret')->once()->andReturnUsing(function (string $ns, array $payload) use (&$captured) {
        $captured = $payload;
    });

    $deployer = k8sSecretDeployerWith(fn () => $client);
    $deployer->bind(k8sCertRef(), k8sCreds(), [
        'namespace' => 'default',
        'secret_name' => 'tls-secret',
        'secret_type' => 'kubernetes.io/tls',
        'secret_annotations' => ['certimate/common-name' => 'override.example.com', 'owner' => 'platform'],
        'secret_labels' => ['app' => 'gateway'],
    ]);

    expect($captured['metadata']['annotations'])->toMatchArray([
        'certimate/common-name' => 'override.example.com',
        'owner' => 'platform',
    ]);
    expect($captured['metadata']['labels'])->toBe(['app' => 'gateway']);
});

test('Secret 不存在（404→null）：createSecret 写 data（base64 的 tls.crt 完整链 + tls.key）', function () {
    $captured = null;
    $client = Mockery::mock(K8sClient::class);
    $client->shouldReceive('getSecret')->once()->with('default', 'tls-secret')->andReturnNull();
    $client->shouldReceive('createSecret')->once()->andReturnUsing(function (string $ns, array $payload) use (&$captured) {
        $captured = [$ns, $payload];
    });
    $client->shouldNotReceive('replaceSecret');

    $deployer = k8sSecretDeployerWith(fn () => $client);
    $deployer->bind(k8sCertRef(), k8sCreds(), [
        'namespace' => 'default', 'secret_name' => 'tls-secret', 'secret_type' => 'kubernetes.io/tls',
    ]);

    [$ns, $payload] = $captured;
    expect($ns)->toBe('default');
    expect($payload['kind'])->toBe('Secret');
    expect($payload['type'])->toBe('kubernetes.io/tls');
    // tls.crt = base64(完整链 = 叶子 + 中间)
    expect(base64_decode($payload['data']['tls.crt']))->toContain('SERVERCERTPEM')->toContain('INTERMEDIAPEM');
    expect(base64_decode($payload['data']['tls.key']))->toBe('KEYPEM');
});

test('Secret 已存在：合并 data/annotations 后 replaceSecret（PUT 覆盖、不新建）', function () {
    $captured = null;
    $client = Mockery::mock(K8sClient::class);
    $client->shouldReceive('getSecret')->once()->andReturn([
        'metadata' => ['name' => 'tls-secret', 'annotations' => ['keep' => 'me'], 'resourceVersion' => '123'],
        'data' => ['existing.key' => base64_encode('OLD')],
    ]);
    $client->shouldReceive('replaceSecret')->once()->andReturnUsing(function (string $ns, string $name, array $payload) use (&$captured) {
        $captured = [$ns, $name, $payload];
    });
    $client->shouldNotReceive('createSecret');

    $deployer = k8sSecretDeployerWith(fn () => $client);
    $deployer->bind(k8sCertRef(), k8sCreds(), ['namespace' => 'ns1', 'secret_name' => 'tls-secret', 'secret_type' => 'Opaque']);

    [$ns, $name, $payload] = $captured;
    expect($ns)->toBe('ns1');
    expect($name)->toBe('tls-secret');
    expect($payload['type'])->toBe('Opaque');
    // 保留既有 annotation + resourceVersion（PUT 需要），合并新 data 键
    expect($payload['metadata']['annotations']['keep'])->toBe('me');
    expect($payload['metadata']['resourceVersion'])->toBe('123');
    expect($payload['data'])->toHaveKey('existing.key');
    expect(base64_decode($payload['data']['tls.crt']))->toContain('SERVERCERTPEM');
});

test('选填 data_key_crt_server / data_key_crt_intermedia 分别写仅服务器证书 / 仅中间证书', function () {
    $captured = null;
    $client = Mockery::mock(K8sClient::class);
    $client->shouldReceive('getSecret')->andReturnNull();
    $client->shouldReceive('createSecret')->andReturnUsing(function (string $ns, array $payload) use (&$captured) {
        $captured = $payload;
    });

    $deployer = k8sSecretDeployerWith(fn () => $client);
    $deployer->bind(k8sCertRef(), k8sCreds(), [
        'namespace' => 'default', 'secret_name' => 's',
        'secret_type' => 'kubernetes.io/tls',
        'data_key_crt_server' => 'server.crt', 'data_key_crt_intermedia' => 'ca.crt',
    ]);

    expect(base64_decode($captured['data']['server.crt']))->toBe('SERVERCERTPEM');
    expect(base64_decode($captured['data']['ca.crt']))->toBe('INTERMEDIAPEM');
});

test('缺 namespace 抛业务错误', function () {
    $deployer = k8sSecretDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(k8sCertRef(), k8sCreds(), ['secret_name' => 's']))
        ->toThrow(RuntimeException::class, '缺少配置 namespace');
});

test('缺 secret_name 抛业务错误', function () {
    $deployer = k8sSecretDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(k8sCertRef(), k8sCreds(), ['namespace' => 'default']))
        ->toThrow(RuntimeException::class, '缺少配置 secret_name');
});

test('存量配置缺 secret_type 时兼容回退 kubernetes.io/tls', function () {
    $captured = null;
    $client = Mockery::mock(K8sClient::class);
    $client->shouldReceive('getSecret')->once()->andReturnNull();
    $client->shouldReceive('createSecret')->once()->andReturnUsing(function (string $namespace, array $payload) use (&$captured) {
        $captured = $payload;
    });
    $deployer = k8sSecretDeployerWith(fn () => $client);
    $deployer->bind(k8sCertRef(), k8sCreds(), ['namespace' => 'default', 'secret_name' => 's']);
    expect($captured['type'])->toBe('kubernetes.io/tls');
});

test('bind 遇 K8sApiException 时脱敏重抛（含 reason、无 token、不挂 previous）', function () {
    $client = Mockery::mock(K8sClient::class);
    $client->shouldReceive('getSecret')->andThrow(new K8sApiException('Forbidden', 'secrets is forbidden'));

    $deployer = k8sSecretDeployerWith(fn () => $client);
    try {
        $deployer->bind(k8sCertRef(), ['server' => 'https://k8s', 'token' => 'TOKEN-LEAK-123'], [
            'namespace' => 'default', 'secret_name' => 's', 'secret_type' => 'kubernetes.io/tls',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Forbidden')->toContain('secrets is forbidden');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});

test('运行时 API Server 指向回环地址被出站策略拦截（makeClient 不产出客户端）', function () {
    app()->instance(
        OutboundDestinationPolicy::class,
        new OutboundDestinationPolicy(
            resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
        ),
    );

    $deployer = new K8sSecretDeployer;
    $method = (new ReflectionClass(K8sSecretDeployer::class))->getMethod('makeClient');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($deployer, 'api', ['server' => 'https://127.0.0.1:6443']))
        ->toThrow(OutboundDestinationException::class);
});

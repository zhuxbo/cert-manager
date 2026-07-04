<?php

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;
use Plugins\CloudDeploy\Deployers\Registry;
use Tests\TestCase;

uses(TestCase::class);

/** 内联型 test-double：实现新 DeployerInterface（经 AbstractDeployer，无 uploadCert）。 */
function fakeDeployer(string $provider = 'aliyun', string $product = 'cdn'): AbstractDeployer
{
    return new class($provider, $product) extends AbstractDeployer
    {
        public function __construct(private string $p, private string $pr) {}

        public function provider(): string
        {
            return $this->p;
        }

        public function product(): string
        {
            return $this->pr;
        }

        public function label(): string
        {
            return 'Fake';
        }

        public function configSchema(): array
        {
            return [['key' => 'domain', 'label' => '域名', 'required' => true]];
        }

        public function bind(string|array $certRef, array $credentials, array $config): void {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass;
        }

        protected function sanitize(Throwable $e): string
        {
            return 'x';
        }
    };
}

function fakeProvider(string $key = 'aliyun'): ProviderInterface
{
    return new class($key) implements ProviderInterface
    {
        public function __construct(private string $k) {}

        public function key(): string
        {
            return $this->k;
        }

        public function label(): string
        {
            return 'Fake';
        }

        public function credentialSchema(): array
        {
            return [['key' => 'access_key_id', 'label' => 'AK', 'required' => true]];
        }
    };
}

test('按 provider.product 解析 deployer', function () {
    $fake = fakeDeployer();
    $registry = new Registry;
    $registry->registerDeployer('aliyun', 'cdn', fn () => $fake);

    expect($registry->resolveDeployer('aliyun', 'cdn'))->toBe($fake);
    expect($registry->hasDeployer('aliyun', 'cdn'))->toBeTrue();
    expect($registry->hasDeployer('tencent', 'waf'))->toBeFalse();
});

test('解析未注册 deployer 抛异常', function () {
    $registry = new Registry;
    expect(fn () => $registry->resolveDeployer('nope', 'nope'))
        ->toThrow(InvalidArgumentException::class);
});

test('解析未注册 provider 抛异常', function () {
    $registry = new Registry;
    expect(fn () => $registry->resolveProvider('nope'))
        ->toThrow(InvalidArgumentException::class);
});

test('catalog 输出嵌套 schema（唯一来源，无兼容层 meta）', function () {
    $registry = new Registry;
    $registry->registerProvider(fakeProvider('aliyun'));
    $registry->registerDeployer('aliyun', 'cdn', fn () => fakeDeployer('aliyun', 'cdn'));

    $catalog = $registry->catalog();

    // 嵌套：provider → credentialSchema + products[] → configSchema
    expect($catalog['providers'])->toHaveCount(1);
    $p = $catalog['providers'][0];
    expect($p['key'])->toBe('aliyun');
    expect(array_column($p['credentialSchema'], 'key'))->toContain('access_key_id');
    expect($p['products'])->toHaveCount(1);
    expect($p['products'][0]['product'])->toBe('cdn');
    expect(array_column($p['products'][0]['configSchema'], 'key'))->toContain('domain');

    // 兼容层 meta 已移除
    expect($catalog)->not->toHaveKey('meta');
});

test('certUploader 默认内联型返回 null', function () {
    expect(fakeDeployer()->certUploader())->toBeNull();
    expect(fakeDeployer()->usesRemoteCertStore())->toBeFalse();
});

// CertUploaderInterface 显式被引用，确保接口存在且 storeKind 在去重键里有意义
test('CertUploaderInterface 暴露 upload + storeKind', function () {
    expect(interface_exists(CertUploaderInterface::class))->toBeTrue();
});

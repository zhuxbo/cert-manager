<?php

namespace Plugins\CloudDeploy\Deployers;

use InvalidArgumentException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployerInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Deployer / Provider 注册表 + catalog 输出。
 * registry/{aliyun,tencent}.php 按 provider 拆分注册（防并行冲突），统一汇入此表。
 */
class Registry
{
    /** @var array<string, ProviderInterface> provider key => provider */
    private array $providers = [];

    /** @var array<string, callable():DeployerInterface> "provider.product" => factory */
    private array $deployers = [];

    public function registerProvider(ProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    /** @param  callable():DeployerInterface  $factory */
    public function registerDeployer(string $provider, string $product, callable $factory): void
    {
        $this->deployers[$this->key($provider, $product)] = $factory;
    }

    public function hasDeployer(string $provider, string $product): bool
    {
        return isset($this->deployers[$this->key($provider, $product)]);
    }

    public function resolveDeployer(string $provider, string $product): DeployerInterface
    {
        $key = $this->key($provider, $product);
        if (! isset($this->deployers[$key])) {
            throw new InvalidArgumentException("未注册的部署器: $key");
        }

        return ($this->deployers[$key])();
    }

    public function hasProvider(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    public function resolveProvider(string $provider): ProviderInterface
    {
        if (! isset($this->providers[$provider])) {
            throw new InvalidArgumentException("未注册的 provider: $provider");
        }

        return $this->providers[$provider];
    }

    /** @return list<array{provider:string,product:string}> */
    public function allDeployers(): array
    {
        return array_map(function (string $key) {
            [$provider, $product] = explode('.', $key, 2);

            return ['provider' => $provider, 'product' => $product];
        }, array_keys($this->deployers));
    }

    /**
     * 前端/服务端校验用目录（schema 唯一来源）：
     * providers 嵌套 schema（provider→credentialSchema + products[]→configSchema），
     * 同时驱动前端表单渲染与后端 schema 校验（ValidatesAgainstSchema）。
     *
     * @return array{
     *     providers: list<array{key:string,label:string,credentialSchema:list<array<string,mixed>>,products:list<array{product:string,label:string,configSchema:list<array<string,mixed>>}>}>
     * }
     */
    public function catalog(): array
    {
        $deployersByProvider = [];
        foreach (array_keys($this->deployers) as $key) {
            [$providerKey] = explode('.', $key, 2);
            $deployer = ($this->deployers[$key])();
            $deployersByProvider[$providerKey][] = [
                'product' => $deployer->product(),
                'label' => $deployer->label(),
                'configSchema' => $deployer->configSchema(),
            ];
        }

        $providers = [];
        foreach ($this->providers as $providerKey => $provider) {
            $providers[] = [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'credentialSchema' => $provider->credentialSchema(),
                'products' => $deployersByProvider[$providerKey] ?? [],
            ];
        }

        return ['providers' => $providers];
    }

    private function key(string $provider, string $product): string
    {
        return "$provider.$product";
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

use GuzzleHttp\Client;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Plugins\CloudDeploy\Support\SafeHttpClientFactory;
use RuntimeException;
use Throwable;

/**
 * Deployer 公共基类：注入缝（makeClient）+ 配置取值（requireConfig 累加 touchedConfigKeys）
 * + SDK 调用脱敏（guardSdk → 重建干净异常，不挂 previous 避免 trace 带出凭证）+ 业务错误统一入口（fail）。
 */
abstract class AbstractDeployer implements DeployerInterface
{
    /**
     * bind() 经 requireConfig 实际读取过的 config key，供 ConfigSchemaContractTest 断言 configSchema ⊇ touchedConfigKeys。
     *
     * @var list<string>
     */
    protected array $touchedConfigKeys = [];

    /**
     * bind() 经 requireConfig 实际读取过的 config key（去重、稳定顺序）。
     * ConfigSchemaContractTest 据此断言 configSchema 的 key 集 ⊇ 实际读取集，防 schema 与实现漂移。
     *
     * @return list<string>
     */
    public function touchedConfigKeys(): array
    {
        return array_values(array_unique($this->touchedConfigKeys));
    }

    /**
     * 默认内联型（不走证书服务）；证书服务型 deployer override 返回具体 uploader。
     *
     * @param  array<string,mixed>  $config  target 部署配置；仅 region 维度的上传器（SLB）需要，其余忽略。
     */
    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return null;
    }

    /** 默认内联型；证书服务型 override 返回 true。 */
    public function usesRemoteCertStore(): bool
    {
        return false;
    }

    /**
     * 从 config 取必填值并记账（touchedConfigKeys）。缺键走 fail() 抛业务错误。
     *
     * @param  array<string,mixed>  $config
     */
    protected function requireConfig(array $config, string $key): mixed
    {
        $this->touchedConfigKeys[] = $key;
        if (! array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
            $this->fail("缺少配置 $key");
        }

        return $config[$key];
    }

    /**
     * 注入缝：子类按 $kind match 实例化对应 SDK client。
     * deployer 内部不得直接 new SDK client，必须经此方法 —— 测试子类 override 按 $kind 返回 Mockery mock。
     *
     * @param  array<string,mixed>  $credentials
     */
    abstract protected function makeClient(string $kind, array $credentials): object;

    /**
     * 为租户可控服务地址创建受插件出站策略约束的客户端。
     *
     * @param  array<string,mixed>  $options
     */
    protected function outboundHttpClient(string $baseUri, array $options = []): Client
    {
        return app(SafeHttpClientFactory::class)->forBaseUri($this->provider(), $baseUri, $options);
    }

    /**
     * 为 Webhook 等绝对地址创建受插件出站策略约束的客户端。
     *
     * @param  array<string,mixed>  $options
     */
    protected function outboundAbsoluteHttpClient(string $url, array $options = []): Client
    {
        return app(SafeHttpClientFactory::class)->forAbsoluteUrl($this->provider(), $url, $options);
    }

    /**
     * 运行时校验租户可控绝对地址（Webhook 等），配合 outboundAbsoluteHttpClient 双层防御：
     * 此处拦截违反策略的地址并抛出可被 guardSdk 归一的异常，客户端构造时再由工厂复检。
     */
    protected function authorizedOutboundUrl(string $url): string
    {
        app(OutboundDestinationPolicy::class)->authorize($this->provider(), $url);

        return $url;
    }

    /**
     * 包裹 SDK 调用：捕获任何 Throwable，先生成安全文案，再由端点按原始异常的结构化字段判断
     * 是否为确定性终态。两条分支都不挂 previous，避免 trace 带出 AK/SK、请求体或签名 URI。
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    protected function guardSdk(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $safeMessage = $this->sanitize($e);

            if ($this->isTerminalSdkError($e)) {
                throw new DeployBusinessException($safeMessage);
            }

            throw new RuntimeException($safeMessage, 0);
        }
    }

    /**
     * 端点级 SDK 终态分类钩子。只检查原始异常的结构化类型、错误码或状态码，不匹配消息文本；
     * 默认返回 false，保证未显式登记的错误仍按可重试异常处理。
     */
    protected function isTerminalSdkError(Throwable $e): bool
    {
        return false;
    }

    /** 业务错误统一入口（区别于网络/SDK 异常）。 */
    protected function fail(string $msg): never
    {
        throw new DeployBusinessException($msg);
    }

    /**
     * 把 SDK 异常脱敏为安全文案：仅保留厂商错误码 + 厂商自带的错误描述，
     * 绝不回传原始 getMessage()（可能含 Guzzle URI 的签名查询串）/ trace / 请求体。
     */
    abstract protected function sanitize(Throwable $e): string;
}

<?php

namespace Plugins\CloudDeploy\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use InvalidArgumentException;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 服务端 schema 校验：以 Registry catalog 为唯一来源，按选中的 provider / product
 * 动态要求 credentials / config 必含对应 schema 的 required 字段，替换笼统的 `required|array`。
 *
 * 渲染端（前端表单）与校验端（这里）共用同一份 schema，杜绝两侧字段定义漂移。
 */
trait ValidatesAgainstSchema
{
    /**
     * 校验 credentials 含 provider credentialSchema 的全部 required 字段（值非空）。
     * provider 未注册时交给 in: 规则报错（这里静默跳过，避免对未知 provider 抛二次错）。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function validateCredentialsSchema(Validator $validator, string $provider, array $credentials): void
    {
        try {
            $schema = app(Registry::class)->resolveProvider($provider)->credentialSchema();
        } catch (InvalidArgumentException) {
            return;
        }

        $this->assertRequiredFields($validator, 'credentials', $schema, $credentials);
        $this->assertNoUnknownFields($validator, 'credentials', $schema, $credentials);
    }

    /**
     * 校验 config 含 (provider, product) deployer configSchema 的全部 required 字段（值非空）。
     * - product 在该 provider 下不存在 → 明确报错（如 aliyun 凭证选了腾讯独有产品）。
     *
     * @param  array<string,mixed>  $config
     */
    protected function validateConfigSchema(Validator $validator, string $provider, string $product, array $config): void
    {
        $registry = app(Registry::class);
        if (! $registry->hasDeployer($provider, $product)) {
            $validator->errors()->add('product', "产品 $product 不属于云厂商 $provider");

            return;
        }

        $schema = $registry->resolveDeployer($provider, $product)->configSchema();
        $this->assertRequiredFields($validator, 'config', $schema, $config);
        $this->assertNoUnknownFields($validator, 'config', $schema, $config);
    }

    /**
     * 逐 required 字段断言存在且非空（null / '' 视为缺失，与 AbstractDeployer::requireConfig 同口径）。
     *
     * @param  list<array{key:string,label?:string,required?:bool}>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertRequiredFields(Validator $validator, string $attribute, array $schema, array $values): void
    {
        foreach ($schema as $field) {
            if (empty($field['required'])) {
                continue;
            }
            $key = $field['key'];
            $label = $field['label'] ?? $key;
            if (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
                $validator->errors()->add("$attribute.$key", "缺少必填项：$label");
            }
        }
    }

    /**
     * 白名单校验（Phase 4 审核建议）：config/credentials 的 key 集必须 ⊆ 对应 schema 的 key 集。
     * 拒绝 schema 外字段，防未声明字段静默落库（credentials 列加密存储、config 列明文存储，
     * 均不应携带前端任意注入的额外键 —— 既污染数据、又可能成为后续读取逻辑的注入面）。
     *
     * @param  list<array{key:string}>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertNoUnknownFields(Validator $validator, string $attribute, array $schema, array $values): void
    {
        $allowed = array_column($schema, 'key');
        foreach (array_keys($values) as $key) {
            if (! in_array($key, $allowed, true)) {
                $validator->errors()->add("$attribute.$key", "不支持的字段：$key");
            }
        }
    }
}

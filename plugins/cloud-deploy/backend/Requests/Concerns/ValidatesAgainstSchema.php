<?php

namespace Plugins\CloudDeploy\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use InvalidArgumentException;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;

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
        $this->assertSelectFields($validator, 'credentials', $schema, $credentials);
        $this->assertNoInvisibleFields($validator, 'credentials', $schema, $credentials);
        $this->assertNoUnknownFields($validator, 'credentials', $schema, $credentials);
        $this->assertDestinationFields($validator, $provider, 'credentials', $schema, $credentials);
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
        $this->assertSelectFields($validator, 'config', $schema, $config);
        $this->assertNoInvisibleFields($validator, 'config', $schema, $config);
        $this->assertDomainMatchFields($validator, $schema, $config);
        $this->assertNoUnknownFields($validator, 'config', $schema, $config);
        $this->assertDestinationFields($validator, $provider, 'config', $schema, $config);
    }

    /**
     * domain_match_pattern 缺省为 exact；exact/wildcard 必须有域名目标，certsan 才允许省略。
     * 这条条件约束无法由 schema 的静态 required 布尔值表达，因此在统一保存入口补充校验。
     *
     * @param  list<array{key:string,label?:string,default?:mixed}>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertDomainMatchFields(Validator $validator, array $schema, array $values): void
    {
        $fields = collect($schema)->keyBy('key');
        if (! $fields->has('domain_match_pattern')) {
            return;
        }

        $patternField = $fields->get('domain_match_pattern');
        $pattern = strtolower((string) ($values['domain_match_pattern'] ?? ($patternField['default'] ?? 'exact')));
        if ($pattern === 'certsan') {
            return;
        }

        $domainKeys = array_values(array_filter(
            ['domains', 'domain'],
            fn (string $key): bool => $fields->has($key) && $this->isVisibleField($fields->get($key), $schema, $values),
        ));
        if ($domainKeys === []) {
            return;
        }

        foreach ($domainKeys as $key) {
            $value = $values[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return;
            }
        }

        $key = $domainKeys[0];
        $field = $fields->get($key);
        $validator->errors()->add("config.$key", '缺少必填项：'.($field['label'] ?? $key));
    }

    /**
     * 逐 required 字段断言存在且非空（null / '' 视为缺失，与 AbstractDeployer::requireConfig 同口径）。
     *
     * @param  list<array{key:string,label?:string,required?:bool,default?:mixed,required_when?:array{key:string,equals:mixed}}>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertRequiredFields(Validator $validator, string $attribute, array $schema, array $values): void
    {
        foreach ($schema as $field) {
            if (! $this->isVisibleField($field, $schema, $values)
                || ! $this->isRequiredField($field, $schema, $values)) {
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
     * 条件必填和前端共用同一语义：未提交选择字段时先采用 schema default，
     * 使存量记录/旧客户端仍落入明确的默认分支。
     *
     * @param  array<string,mixed>  $field
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $values
     */
    private function isRequiredField(array $field, array $schema, array $values): bool
    {
        return ! empty($field['required'])
            || $this->matchesSchemaCondition($field['required_when'] ?? null, $schema, $values);
    }

    /**
     * 后端与前端共用 visible_when 语义。隐藏字段不参与 required；若客户端仍提交
     * 旧值，下面 assertNoInvisibleFields 会拒绝请求，避免绕过 UI 裁剪把陈旧值持久化。
     *
     * @param  array<string,mixed>  $field
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $values
     */
    private function isVisibleField(array $field, array $schema, array $values): bool
    {
        return ! array_key_exists('visible_when', $field)
            || $this->matchesSchemaCondition($field['visible_when'], $schema, $values);
    }

    /**
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $values
     */
    private function matchesSchemaCondition(mixed $condition, array $schema, array $values): bool
    {
        if (! is_array($condition)
            || ! is_string($condition['key'] ?? null)
            || $condition['key'] === ''
            || ! array_key_exists('equals', $condition)) {
            return false;
        }

        $key = $condition['key'];
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') {
            foreach ($schema as $candidate) {
                if (($candidate['key'] ?? null) === $key && array_key_exists('default', $candidate)) {
                    $value = $candidate['default'];

                    break;
                }
            }
        }

        return $value === $condition['equals'];
    }

    /**
     * select 的 options 是 catalog 契约的一部分：default 也必须可选，客户端显式提交
     * 非法值要在保存入口拒绝，不能靠 deployer 运行时才失败。
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertSelectFields(Validator $validator, string $attribute, array $schema, array $values): void
    {
        foreach ($schema as $field) {
            if (($field['type'] ?? null) !== 'select' || ! isset($field['key']) || ! is_array($field['options'] ?? null)) {
                continue;
            }

            $key = $field['key'];
            $value = $values[$key] ?? ($field['default'] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $allowed = array_column($field['options'], 'value');
            if (! in_array($value, $allowed, true)) {
                $validator->errors()->add("$attribute.$key", '不支持的选项：'.$key);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertNoInvisibleFields(Validator $validator, string $attribute, array $schema, array $values): void
    {
        foreach ($schema as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key) || ! array_key_exists($key, $values) || $this->isVisibleField($field, $schema, $values)) {
                continue;
            }

            $validator->errors()->add("$attribute.$key", '当前选项不支持字段：'.$key);
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

    /**
     * schema 标记为 destination 的字段是租户可控出站地址；新增和更新时始终使用严格策略。
     *
     * @param  list<array{key:string,destination?:bool}>  $schema
     * @param  array<string,mixed>  $values
     */
    private function assertDestinationFields(
        Validator $validator,
        string $provider,
        string $attribute,
        array $schema,
        array $values,
    ): void {
        foreach ($schema as $field) {
            if (empty($field['destination'])) {
                continue;
            }

            $key = $field['key'];
            $url = $values[$key] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            // endpoint 类裸 host（腾讯 SDK 自动拼 https://）补默认 scheme，完整 URL 原样校验
            if (! str_contains($url, '://')) {
                $url = 'https://'.$url;
            }

            try {
                app(OutboundDestinationPolicy::class)->authorize($provider, $url);
            } catch (OutboundDestinationException) {
                $validator->errors()->add("$attribute.$key", '部署目标地址不符合出站安全策略');
            }
        }
    }
}

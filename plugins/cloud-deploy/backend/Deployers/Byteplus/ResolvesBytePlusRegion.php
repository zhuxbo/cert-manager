<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

/**
 * 从 config 解析 BytePlus 签名 region（可选 + 默认值）。
 *
 * region 在多数 BytePlus 端点是**可选**配置（缺省回落到端点默认，如证书中心 ap-singapore-1、CDN/直播 region-less
 * 占位 cn-north-1）。不走 requireConfig（那是 required 语义、会记 touchedConfigKeys 并在缺失时 fail）——
 * 直接读 config，空则用默认值。ConfigSchemaContractTest 仅断言 configSchema ⊇ requireConfig 读取集，
 * 故可选 region 不进 touchedConfigKeys，但仍声明在 configSchema 里供前端表单渲染。
 */
trait ResolvesBytePlusRegion
{
    /**
     * @param  array<string,mixed>  $config
     */
    protected function resolveRegion(array $config, string $default): string
    {
        $region = isset($config['region']) ? trim((string) $config['region']) : '';

        return $region !== '' ? $region : $default;
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

/**
 * region 解析助手（region 维度的火山端点共用）。
 *
 * 火山多数端点的 region 是 config 字段（非凭证），且「上传到证书中心」与「绑定资源」须用同一 region。
 * certUploader($config) 与 bind($config) 都从 config 取 region 经 makeClient 第三参透传，避免 makeClient
 * 增加对 config 的隐式依赖（与基类 makeClient(kind, credentials) 签名兼容，仅追加可选 region 参数）。
 *
 * region 列入各端点 configSchema（多为可选 + 代码内默认值），故经 requireConfig 记账亦在 schema 内、
 * 不触发 ConfigSchemaContractTest 漂移；此处用「读但不强制」语义（缺省回落默认 region），故不走 requireConfig。
 */
trait ResolvesVolcRegion
{
    /** 从 config 取 region（记入 touchedConfigKeys 以对齐 schema 契约），缺省回落 $default。 */
    protected function resolveRegion(array $config, string $default): string
    {
        // 记账：region 在 configSchema 内，touchedConfigKeys 据此校验 schema ⊇ 实读集
        $this->touchedConfigKeys[] = 'region';

        $region = $config['region'] ?? null;

        return is_string($region) && $region !== '' ? $region : $default;
    }
}

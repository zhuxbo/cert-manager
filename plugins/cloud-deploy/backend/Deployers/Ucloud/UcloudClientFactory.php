<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

/**
 * UCloud deployer 公共 client 构造（注入缝 makeClient 的默认实现 + 共享 builder）。
 *
 * UCloud 所有服务走单一 REST endpoint + 统一签名，故各 deployer 的 client 类型一致（UcloudRestClient），
 * 仅 region 不同：ucdn/uewaf/pathx 区域无关（region=''），us3/ualb/uclb 区域型（region 取自 config）。
 *
 * - 区域无关 deployer：直接 `use UcloudClientFactory`，默认 makeClient('api') 构造 region-less client。
 * - 区域型 deployer：override makeClient 增加 region 形参，内部调 buildUcloudClient($credentials, $region)。
 *
 * 凭证键对齐 UcloudProvider credentialSchema：public_key / private_key / project_id。
 */
trait UcloudClientFactory
{
    /**
     * 注入缝默认实现：构造 region-less UcloudRestClient。
     * 区域型 deployer 覆盖此方法（带 region 形参）。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => $this->buildUcloudClient($credentials, ''),
        };
    }

    /**
     * 共享 builder：按凭证 + region 构造 UcloudRestClient。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function buildUcloudClient(array $credentials, string $region): UcloudRestClient
    {
        return new UcloudRestClient(
            is_string($credentials['public_key'] ?? null) ? $credentials['public_key'] : '',
            is_string($credentials['private_key'] ?? null) ? $credentials['private_key'] : '',
            is_string($credentials['project_id'] ?? null) ? $credentials['project_id'] : '',
            $region,
        );
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

/**
 * 又拍云域名 HTTPS 证书绑定共用逻辑（cdn / file 两端点一致）。
 *
 * 对齐 certimate upyun-cdn / upyun-file 的 updateDomainCertificate：
 *   1. GetHttpsServiceManager(domain) 拿该域名 HTTPS 配置列表（result[]）。
 *   2. 在列表里找**任一已启用 HTTPS（https==true）**的条目（certimate FindIndexOf item.Https）：
 *      - 未找到（未启用 HTTPS）→ UpdateHttpsCertificateManager 启用 HTTPS + 绑证书（https/force_https=true）。
 *      - 找到但其 certificate_id ≠ 目标 certId → MigrateHttpsDomain 迁移（更换）证书。
 *      - 找到且 certificate_id == 目标 certId → 无操作（已是目标证书）。
 *
 * exact 单域名核心路径（不做 certimate 的 wildcard/certsan 多域名遍历）。
 * 使用方需 use AbstractDeployer 的 makeClient/guardSdk（本 trait 仅被 deployer 引入）。
 */
trait BindsUpyunDomainHttps
{
    /**
     * 把云端证书 certId 绑定到 $domain（按当前 HTTPS 状态选择 enable 或 migrate）。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function bindUpyunDomain(string $certId, string $domain, array $credentials): void
    {
        $this->guardSdk(function () use ($credentials, $certId, $domain) {
            /** @var UpyunRestClient $client */
            $client = $this->makeClient('api', $credentials);

            $configs = $client->getHttpsServiceManager($domain);

            // 找任一已启用 HTTPS 的条目（certimate: FindIndexOf item.Https）
            $enabled = null;
            foreach ($configs as $item) {
                if (($item['https'] ?? false) === true) {
                    $enabled = $item;
                    break;
                }
            }

            if ($enabled === null) {
                // 未启用 HTTPS → 启用并绑证书
                $client->updateHttpsCertificateManager($certId, $domain, true, true);

                return;
            }

            $boundCertId = is_string($enabled['certificate_id'] ?? null) ? $enabled['certificate_id'] : '';
            if ($boundCertId !== $certId) {
                // 已启用但证书不同 → 迁移（更换）证书
                $client->migrateHttpsDomain($certId, $domain);
            }
            // 已启用且证书相同 → 无操作
        });
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use stdClass;

/**
 * BytePlus ALB / CLB 监听器绑定证书的共享逻辑（两者 REST 形态高度一致，差异仅：
 *   - service / version（alb=alb / clb=clb，均 version 2020-04-01）；
 *   - ALB 支持 SNI 扩展域名（DescribeListenerAttributes → DomainExtensions），CLB 不支持。
 *
 * 对齐 certimate byteplus-alb / byteplus-clb（GET 类 OpenAPI，入参摊进 query string）：
 *   - deploy_target=loadbalancer：DescribeLoadBalancerAttributes 校验实例存在 → DescribeListeners(Protocol=HTTPS,
 *     分页) 取全部 HTTPS 监听 → 逐个 ModifyListenerAttributes。
 *   - deploy_target=listener：直接对 config.listener_id ModifyListenerAttributes。
 *   - 无 SNI（domain 空）：ModifyListenerAttributes{ListenerId, CertificateSource=cert_center, CertCenterCertificateId}。
 *   - 有 SNI（domain，仅 ALB）：DescribeListenerAttributes 取 DomainExtensions，过滤 Domain==config.domain，
 *     摊平为 DomainExtensions.N.{DomainExtensionId,Domain,CertificateSource,CertCenterCertificateId,Action=modify}。
 *
 * 宿主 deployer 须提供：
 *   - lbService(): string                          —— alb / clb（签名 service）
 *   - lbApiVersion(): string                       —— 2020-04-01
 *   - supportsSni(): bool                          —— ALB true / CLB false
 *   - makeClient('lb', creds, region): BytePlusRestClient
 *   - requireConfig()/fail()/guardSdk()            —— 来自 AbstractDeployer
 *
 * requireConfig 顺序契约：region → deploy_target 全在任何 SDK 调用之前读取（均为 required），
 * loadbalancer_id 仅在 loadbalancer 分支读取、listener_id 仅在 listener 分支读取（schema 标 optional，
 * 避免「另一目标用不到的 id」成僵尸 required），domain 为可选 SNI（不经 requireConfig）。
 */
trait BytePlusLoadBalancerDeployTrait
{
    /**
     * @param  string  $certRef  remote_cert_id（证书中心 CertId）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{region:string,deploy_target:string,loadbalancer_id?:string,listener_id?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $target = (string) $this->requireConfig($config, 'deploy_target');
        $certId = (string) $certRef;
        // SNI 扩展域名（选填，仅 ALB 生效）：空字符串视为未指定。
        $domain = isset($config['domain']) ? (string) $config['domain'] : '';
        $projectName = (string) ($credentials['project_name'] ?? '');

        switch ($target) {
            case 'loadbalancer':
                $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');
                $this->deployToLoadbalancer($credentials, $region, $projectName, $loadbalancerId, $certId, $domain);
                break;

            case 'listener':
                $listenerId = (string) $this->requireConfig($config, 'listener_id');
                $this->deployToListener($credentials, $region, $listenerId, $certId, $domain);
                break;

            default:
                $this->fail("不支持的部署目标 deploy_target: $target");
        }
    }

    /**
     * loadbalancer 目标：校验实例 → 取全部 HTTPS 监听 → 逐个更新证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToLoadbalancer(array $credentials, string $region, string $projectName, string $loadbalancerId, string $certId, string $domain): void
    {
        $listenerIds = $this->guardSdk(function () use ($credentials, $region, $projectName, $loadbalancerId): array {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('lb', $credentials, $region);

            // 校验实例存在（与 certimate 一致，失败抛 API 错误）。
            $client->openApi('GET', 'DescribeLoadBalancerAttributes', $this->lbApiVersion(), [
                'LoadBalancerId' => $loadbalancerId,
            ]);

            // 分页取全部 HTTPS 监听。
            $ids = [];
            $pageNumber = 1;
            $pageSize = 100;
            while (true) {
                $query = [
                    'LoadBalancerId' => $loadbalancerId,
                    'Protocol' => 'HTTPS',
                    'PageNumber' => $pageNumber,
                    'PageSize' => $pageSize,
                ];
                if ($projectName !== '') {
                    $query['ProjectName'] = $projectName;
                }
                $result = $client->openApi('GET', 'DescribeListeners', $this->lbApiVersion(), $query);

                $listeners = is_array($result->Listeners ?? null) ? $result->Listeners : [];
                foreach ($listeners as $listener) {
                    if ($listener instanceof stdClass && (string) ($listener->ListenerId ?? '') !== '') {
                        $ids[] = (string) $listener->ListenerId;
                    }
                }

                if (count($listeners) < $pageSize) {
                    break;
                }
                $pageNumber++;
            }

            return $ids;
        });

        // 逐个监听更新证书（每个 guardSdk 独立，便于精确脱敏）。
        foreach ($listenerIds as $listenerId) {
            $this->updateListenerCertificate($credentials, $region, $listenerId, $certId, $domain);
        }
    }

    /**
     * listener 目标：直接更新指定监听证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToListener(array $credentials, string $region, string $listenerId, string $certId, string $domain): void
    {
        $this->updateListenerCertificate($credentials, $region, $listenerId, $certId, $domain);
    }

    /**
     * 把 certId 设到指定监听器（无 SNI 直设主证书；有 SNI 且支持时只换匹配扩展域名）。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function updateListenerCertificate(array $credentials, string $region, string $listenerId, string $certId, string $domain): void
    {
        $this->guardSdk(function () use ($credentials, $region, $listenerId, $certId, $domain) {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('lb', $credentials, $region);

            if ($domain === '' || ! $this->supportsSni()) {
                // 无 SNI（或不支持 SNI 的 CLB）：直接把主证书设为新 certId。
                $client->openApi('GET', 'ModifyListenerAttributes', $this->lbApiVersion(), [
                    'ListenerId' => $listenerId,
                    'CertificateSource' => 'cert_center',
                    'CertCenterCertificateId' => $certId,
                ]);

                return;
            }

            // 有 SNI（仅 ALB）：查监听扩展域名，过滤 Domain==config.domain，摊平 DomainExtensions.N.*。
            $attrs = $client->openApi('GET', 'DescribeListenerAttributes', $this->lbApiVersion(), [
                'ListenerId' => $listenerId,
            ]);

            $query = ['ListenerId' => $listenerId];
            $index = 1;
            $extensions = is_array($attrs->DomainExtensions ?? null) ? $attrs->DomainExtensions : [];
            foreach ($extensions as $ext) {
                if (! $ext instanceof stdClass) {
                    continue;
                }
                if ((string) ($ext->Domain ?? '') !== $domain) {
                    continue;
                }
                $prefix = "DomainExtensions.$index.";
                $query[$prefix.'DomainExtensionId'] = (string) ($ext->DomainExtensionId ?? '');
                $query[$prefix.'Domain'] = (string) ($ext->Domain ?? '');
                $query[$prefix.'CertificateSource'] = 'cert_center';
                $query[$prefix.'CertCenterCertificateId'] = $certId;
                $query[$prefix.'Action'] = 'modify';
                $index++;
            }

            if ($index === 1) {
                // 未找到匹配扩展域名 —— 与 certimate 行为一致（modify 空列表为 no-op），不报错。
                return;
            }

            $client->openApi('GET', 'ModifyListenerAttributes', $this->lbApiVersion(), $query);
        });
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\K8s;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Kubernetes provider：支持完整 kubeconfig、显式 API Server/Bearer Token，以及容器内 ServiceAccount。
 *
 * certimate 侧 AccessConfigForKubernetes 存完整 kubeconfig，由 client-go 解析连接与鉴权信息。
 * 本插件用 Symfony YAML 解析 current-context，支持 Bearer Token、客户端证书与容器内
 * ServiceAccount；同时兼容历史 server + token + ca_cert 凭证。
 *
 * - server：Kubernetes API Server 地址（如 https://1.2.3.4:6443），显式凭证模式必填。
 * - token：ServiceAccount Bearer Token（选填，放 Authorization 请求头）。
 * - ca_cert：API Server CA 证书 PEM（选填，校验服务端 TLS）。
 *
 * token / ca_cert 标 secret，前端按密码框渲染、脱敏体系（CredentialScrubber）兜底防泄露。
 */
class K8sProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'k8s';
    }

    public function label(): string
    {
        return 'Kubernetes';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'kube_config', 'label' => 'kubeconfig 文件内容（选填）', 'required' => false, 'secret' => true],
            ['key' => 'server', 'label' => 'API Server 地址（不使用 kubeconfig 时）', 'required' => false, 'destination' => true],
            ['key' => 'token', 'label' => 'Bearer Token（选填）', 'required' => false, 'secret' => true],
            ['key' => 'ca_cert', 'label' => 'API Server CA 证书（PEM，选填）', 'required' => false, 'secret' => true],
        ];
    }
}

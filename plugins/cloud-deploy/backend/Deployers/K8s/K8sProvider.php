<?php

namespace Plugins\CloudDeploy\Deployers\K8s;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Kubernetes provider：凭证为 API Server 地址 + Bearer Token + 可选 CA 证书。
 *
 * certimate 侧 AccessConfigForKubernetes 仅存 kubeConfig（完整 kubeconfig 文件内容），由 client-go
 * 解析出 server/token/CA。本插件为纯 GuzzleHttp REST（无 YAML 解析依赖），改为直接收
 * server + token + ca_cert 三件（即 client-go 从 kubeconfig 解出的等价产物），对齐任务约定
 * 「鉴权 kubeconfig 的 bearer token + CA（或 server+token）」。
 *
 * - server：Kubernetes API Server 地址（如 https://1.2.3.4:6443），必填。
 * - token：ServiceAccount Bearer Token，必填（放 Authorization 请求头）。
 * - ca_cert：API Server CA 证书 PEM（选填，校验服务端 TLS；省略时回落 insecure 跳过校验）。
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
            ['key' => 'server', 'label' => 'API Server 地址', 'required' => true, 'destination' => true],
            ['key' => 'token', 'label' => 'Bearer Token', 'required' => true, 'secret' => true],
            ['key' => 'ca_cert', 'label' => 'API Server CA 证书（PEM，选填）', 'required' => false, 'secret' => true],
        ];
    }
}

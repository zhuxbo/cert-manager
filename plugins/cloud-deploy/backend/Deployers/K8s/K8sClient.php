<?php

namespace Plugins\CloudDeploy\Deployers\K8s;

use GuzzleHttp\ClientInterface;

/**
 * Kubernetes API Server REST 薄客户端（core/v1 Secrets 读/建/改）。
 *
 * 无官方 PHP SDK，照 certimate k8s-secret（Go client-go REST）用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{server}/api/v1。鉴权 Bearer Token（Authorization 请求头）+ 可选 CA（Guzzle `verify`）。
 *
 * 响应体非 2xx 时为 meta.k8s.io/v1 Status 对象 `{kind:"Status", message, reason, code}`——
 * getSecret 对 404 返回 null（视为不存在，走创建），其余非 2xx 归一为 K8sApiException（reason/HTTP 码
 * + message，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部
 * REST 调用，无需打真实 HTTP。
 */
class K8sClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 读取指定命名空间下的 Secret。
     * REF: certimate k8s-secret Secrets.Get —— GET /api/v1/namespaces/{ns}/secrets/{name}
     *
     * @return array<string,mixed>|null 不存在（404）时返回 null
     */
    public function getSecret(string $namespace, string $name): ?array
    {
        $resp = $this->http->request('GET', "namespaces/$namespace/secrets/$name", [
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw $this->toError($status, $json);
        }

        return $json;
    }

    /**
     * 创建 Secret。
     * REF: certimate k8s-secret Secrets.Post —— POST /api/v1/namespaces/{ns}/secrets
     *
     * @param  array<string,mixed>  $payload  完整 Secret 对象
     */
    public function createSecret(string $namespace, array $payload): void
    {
        $this->writeRequest('POST', "namespaces/$namespace/secrets", $payload);
    }

    /**
     * 更新 Secret。
     * REF: certimate k8s-secret Secrets.Put —— PUT /api/v1/namespaces/{ns}/secrets/{name}
     *
     * @param  array<string,mixed>  $payload  完整 Secret 对象
     */
    public function replaceSecret(string $namespace, string $name, array $payload): void
    {
        $this->writeRequest('PUT', "namespaces/$namespace/secrets/$name", $payload);
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>  $payload
     */
    private function writeRequest(string $method, string $path, array $payload): void
    {
        $resp = $this->http->request($method, $path, [
            'json' => $payload,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $json = json_decode((string) $resp->getBody(), true);
        throw $this->toError($status, is_array($json) ? $json : []);
    }

    /**
     * 把 K8s Status 错误体归一为 K8sApiException（reason 优先，回落 HTTP 码；message 取自响应体）。
     *
     * @param  array<string,mixed>  $json
     */
    private function toError(int $status, array $json): K8sApiException
    {
        $reason = is_string($json['reason'] ?? null) && $json['reason'] !== '' ? $json['reason'] : (string) $status;
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "Kubernetes 接口返回 HTTP $status";

        return new K8sApiException($reason, $message);
    }
}

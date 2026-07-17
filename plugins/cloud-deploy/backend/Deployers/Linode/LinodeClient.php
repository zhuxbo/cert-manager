<?php

namespace Plugins\CloudDeploy\Deployers\Linode;

use GuzzleHttp\ClientInterface;

/**
 * Linode API v4 REST 薄客户端（对象存储 TLS/SSL 证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/linode 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token（Authorization 请求头）。base：https://api.linode.com/v4。
 * Linode 失败响应体形如 `{errors:[{field?,reason}]}` —— HTTP 非 2xx 或响应体带 errors 归一为
 * LinodeApiException（错误码=HTTP 状态、描述=拼接 reason，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class LinodeClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 查询对象存储桶是否已配置 SSL 证书。
     * REF: certimate linode GetObjectStorageSSL —— GET /v4/object-storage/buckets/{region}/{bucket}/ssl
     */
    public function getObjectStorageSslEnabled(string $regionId, string $bucket): bool
    {
        $json = $this->request('GET', $this->sslPath($regionId, $bucket), null);

        return ($json['ssl'] ?? null) === true;
    }

    /**
     * 删除对象存储桶的 SSL 证书。
     * REF: certimate linode DeleteObjectStorageSSL —— DELETE /v4/object-storage/buckets/{region}/{bucket}/ssl
     */
    public function deleteObjectStorageSsl(string $regionId, string $bucket): void
    {
        $this->request('DELETE', $this->sslPath($regionId, $bucket), null);
    }

    /**
     * 上传对象存储桶的 SSL 证书。
     * REF: certimate linode UploadObjectStorageSSL —— POST /v4/object-storage/buckets/{region}/{bucket}/ssl
     *
     * @param  array<string,mixed>  $body  certificate / private_key
     */
    public function uploadObjectStorageSsl(string $regionId, string $bucket, array $body): void
    {
        $this->request('POST', $this->sslPath($regionId, $bucket), $body);
    }

    private function sslPath(string $regionId, string $bucket): string
    {
        // 相对路径（无前导 /）：与 base_uri ".../v4/" 合并。region/bucket 编码进路径段。
        return 'object-storage/buckets/'.rawurlencode($regionId).'/'.rawurlencode($bucket).'/ssl';
    }

    /**
     * 发起请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但响应体带 errors」。
     *
     * @param  array<string,mixed>|null  $body  null 时不带请求体（GET/DELETE）
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $options = ['http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $errors = is_array($json['errors'] ?? null) ? $json['errors'] : [];
        if ($status >= 200 && $status < 300 && $errors === []) {
            return $json;
        }

        // 拼接响应体 errors[].reason（Linode 标准错误体，安全：无凭证）
        $reasons = [];
        foreach ($errors as $err) {
            if (! is_array($err)) {
                continue;
            }
            $reason = is_string($err['reason'] ?? null) ? $err['reason'] : '';
            $field = is_string($err['field'] ?? null) && $err['field'] !== '' ? $err['field'] : '';
            if ($reason === '') {
                continue;
            }
            $reasons[] = $field !== '' ? "[$field] $reason" : $reason;
        }
        $code = $status > 0 ? (string) $status : 'LinodeError';
        $message = $reasons !== [] ? implode('; ', $reasons) : "Linode 接口返回 HTTP $status";

        throw new LinodeApiException($code, $message);
    }
}

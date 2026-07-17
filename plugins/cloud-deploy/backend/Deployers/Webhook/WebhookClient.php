<?php

namespace Plugins\CloudDeploy\Deployers\Webhook;

use GuzzleHttp\ClientInterface;

/**
 * Webhook 回调 HTTP 薄客户端（任意 URL + 谓词 + 内容类型）。
 *
 * 无 SDK，照 certimate webhook（Go resty）用 GuzzleHttp（来自主系统 vendor）直调。变量替换 +
 * 内容类型选择由 WebhookDeployer 完成，本类只负责按 method/contentType 组装请求并归一错误：
 *   - GET：data 作查询参数（query）。
 *   - POST/PUT/PATCH/DELETE：
 *       application/json → json body（任意结构）；
 *       application/x-www-form-urlencoded → form_params（扁平 map<string,string>）；
 *       multipart/form-data → multipart（扁平 map<string,string>）。
 *
 * http_errors=false 自行判状态，非 2xx 归一为 WebhookApiException（仅 HTTP 状态码，**不带响应体**——
 * 响应体由用户服务器返回、内容不可控）。
 *
 * 此类是 deployer 的 makeClient('http', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖。
 */
class WebhookClient
{
    public const CONTENT_TYPE_JSON = 'application/json';

    public const CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';

    public const CONTENT_TYPE_MULTIPART = 'multipart/form-data';

    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 发送 Webhook 请求。
     *
     * @param  array<string,string>  $headers  请求标头
     * @param  mixed  $data  GET/form/multipart 时为 map<string,string>，json 时为任意结构
     * @param  int  $timeout  请求超时（秒）
     */
    public function send(string $method, string $url, array $headers, string $contentType, mixed $data, int $timeout = 30): void
    {
        $options = [
            'http_errors' => false,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        if ($method === 'GET') {
            /** @var array<string,string> $data */
            $options['query'] = $data;
        } else {
            switch ($contentType) {
                case self::CONTENT_TYPE_JSON:
                    $options['json'] = $data;
                    break;
                case self::CONTENT_TYPE_FORM:
                    /** @var array<string,string> $data */
                    $options['form_params'] = $data;
                    break;
                case self::CONTENT_TYPE_MULTIPART:
                    /** @var array<string,string> $data */
                    $options['multipart'] = array_map(
                        fn (string $name, string $contents): array => ['name' => $name, 'contents' => $contents],
                        array_keys($data),
                        array_values($data),
                    );
                    break;
            }
        }

        $resp = $this->http->request($method, $url, $options);

        $status = $resp->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        // 非 2xx：仅暴露状态码，不回传响应体（用户服务器返回、内容不可控）
        throw new WebhookApiException((string) $status, "Webhook 回调返回 HTTP $status");
    }
}

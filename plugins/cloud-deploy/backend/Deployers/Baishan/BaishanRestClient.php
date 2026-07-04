<?php

namespace Plugins\CloudDeploy\Deployers\Baishan;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 白山云开放 API REST 薄客户端。
 *
 * 白山云无官方 PHP SDK。本类照 certimate pkg/sdk3rd/baishan（自写 Go SDK）直调 REST。**无签名**——白山云
 * 鉴权仅靠 URL 查询串里的 token={apiToken}（对齐 certimate：resty 客户端 SetQueryParam("token", apiToken)）。
 *
 * base：https://cdn.api.baishan.com（对齐 certimate）。GET 用 query 协议、POST 用 JSON body 协议。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 code 非 0（白山云统一响应体 {code,message,data}）→ 抛 BaishanApiException
 * （携白山云 code + message，均来自响应体、不含 token）。Guzzle 本身失败（连接/超时）→ 异常上抛，由
 * BaishanErrorSanitizer 兜底（仅类名，因请求 URL 含 token 查询串）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class BaishanRestClient
{
    private Client $http;

    private const BASE_URL = 'https://cdn.api.baishan.com';

    public function __construct(
        private readonly string $apiToken,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            // 上游慢/挂时不让 worker 长期阻塞（与 Ksyun/Dogecloud/Baidu 锁外约定一致）。
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * GET 请求（query 协议）。token 自动注入查询串。
     * 用于 cdn.GetDomainConfig（含数组参数 config[]）等查询端点。
     *
     * @param  array<string,scalar>  $query  普通查询参数
     * @param  array<string,list<scalar>>  $arrayQuery  数组查询参数（键自动加 [] 后缀，如 config[]）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function get(string $path, array $query = [], array $arrayQuery = []): array
    {
        return $this->send('GET', $path, $query, $arrayQuery, null);
    }

    /**
     * POST 请求（JSON body 协议）。token 自动注入查询串。
     * 用于 cdn.SetDomainConfig / cdn.UploadDomainCertificate 等写端点。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, [], [], $body);
    }

    /**
     * 发起一次请求并归一响应/错误。
     *
     * @param  array<string,scalar>  $query
     * @param  array<string,list<scalar>>  $arrayQuery
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query, array $arrayQuery, ?array $body): array
    {
        // token 永远进查询串（白山云鉴权方式），与业务查询参数一起编码。
        $queryParts = ['token='.rawurlencode($this->apiToken)];
        foreach ($query as $k => $v) {
            $queryParts[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
        }
        foreach ($arrayQuery as $k => $values) {
            foreach ($values as $v) {
                // 白山云数组参数形如 config[]=https（对齐 certimate QueryParam.Add("config[]", ...)）。
                $queryParts[] = rawurlencode($k.'[]').'='.rawurlencode((string) $v);
            }
        }

        $url = self::BASE_URL.$path.'?'.implode('&', $queryParts);

        $options = ['headers' => ['Accept' => 'application/json']];
        if ($body !== null) {
            $options[RequestOptions::BODY] = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // 白山云统一响应体：{code, message, data}。code 非 0 即业务失败（对齐 certimate doRequestWithResult）。
        if (array_key_exists('code', $decoded)) {
            $code = (int) $decoded['code'];
            if ($code !== 0) {
                throw new BaishanApiException((string) $code, self::message($decoded));
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new BaishanApiException((string) $status, '白山云接口返回 HTTP '.$status);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $decoded */
    private static function message(array $decoded): string
    {
        $msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        return $msg !== '' ? $msg : '白山云接口返回错误';
    }
}

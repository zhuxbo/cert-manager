<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 多吉云开放 API REST 薄客户端（含签名）。
 *
 * 多吉云无官方 PHP SDK。本类照 certimate pkg/sdk3rd/dogecloud（自写 Go SDK）复刻多吉云开放 API 签名
 * （HMAC-SHA1，签名在 Authorization 头）+ Guzzle 直调 REST。逐字节对齐 certimate 的 signer.go：
 *   - stringToSign = path[?排序后的 query] + "\n" + 请求体原文（GET 无 body 时为空串）。
 *   - signature = lowerhex(HMAC-SHA1(stringToSign, secretKey))。
 *   - Authorization = "TOKEN {accessKey}:{signature}"。
 *
 * base：https://api.dogecloud.com（对齐 certimate）。请求/响应均为 JSON。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 code 非 0 且非 200（多吉云统一响应体 {code,msg,data}）→ 抛
 * DogecloudApiException（携多吉云 code + msg，均来自响应体、不含凭证）。Guzzle 本身失败（连接/超时）→
 * 异常上抛，由 DogecloudErrorSanitizer 兜底（仅类名）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class DogecloudRestClient
{
    private Client $http;

    private const BASE_URL = 'https://api.dogecloud.com';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            // 上游慢/挂时不让 worker 长期阻塞（与 Ksyun/Baidu/Aliyun 锁外约定一致）。
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * GET 请求（无 body）。用于 cdn.ListCdnDomain 等查询端点。
     *
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    /**
     * POST 请求（JSON body）。用于 cdn.UploadCdnCert / cdn.BindCdnCert 等写端点。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, $body);
    }

    /**
     * 发起一次签名后的请求并归一响应/错误。
     *
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, ?array $body): array
    {
        // 多吉云 body 为 JSON（与 certimate 一致；签名 payload 即此 JSON 原文）。
        $bodyStr = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $authorization = $this->sign($path, $bodyStr);

        $options = [
            RequestOptions::HEADERS => ['Authorization' => $authorization],
        ];
        if ($body !== null) {
            $options[RequestOptions::BODY] = $bodyStr;
            $options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
        }

        $response = $this->http->request($method, self::BASE_URL.$path, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // 多吉云统一响应体：{code, msg, data}。code 非 0 且非 200 即业务失败（对齐 certimate doRequestWithResult）。
        if (array_key_exists('code', $decoded)) {
            $code = (int) $decoded['code'];
            if ($code !== 0 && $code !== 200) {
                throw new DogecloudApiException((string) $code, self::message($decoded));
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new DogecloudApiException((string) $status, '多吉云接口返回 HTTP '.$status);
        }

        return $decoded;
    }

    /**
     * 计算签名：stringToSign = path[?query] + "\n" + body，HMAC-SHA1(secretKey)，小写 hex，
     * Authorization = "TOKEN {accessKey}:{signature}"（对齐 signer.go）。
     */
    private function sign(string $path, string $bodyStr): string
    {
        // certimate 用 req.URL.Path + "?" + req.URL.Query().Encode()；本类 path 已含 query（如有），
        // 与 Go 的 path+"?"+query 等价（cdn 端点不带 query）。
        $signature = strtolower(hash_hmac('sha1', $path."\n".$bodyStr, $this->secretKey));

        return 'TOKEN '.$this->accessKey.':'.$signature;
    }

    /** @param array<string,mixed> $decoded */
    private static function message(array $decoded): string
    {
        $msg = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : '';

        return $msg !== '' ? $msg : '多吉云接口返回错误';
    }
}

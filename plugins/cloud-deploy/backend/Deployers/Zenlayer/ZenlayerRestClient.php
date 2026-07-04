<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * ZenlayerCloud 开放 API REST 薄客户端（含 ZC2-HMAC-SHA256 签名）。
 *
 * Zenlayer 无官方模块化 PHP SDK。certimate 用 github.com/zenlayer/zenlayercloud-sdk-go（common.Client.ApiCall）；
 * 本类逐字节复刻其 ZC2-HMAC-SHA256 签名 + Guzzle 直调 REST：
 *   - endpoint：console.zenlayer.com，POST /api/v2/{service}，body = JSON（action 不进 body，进请求头）。
 *   - 请求头：x-zc-version / x-zc-service / x-zc-action / x-zc-sdk-version / x-zc-sdk-lang=go / Host /
 *     Content-Type=application/json / x-zc-signature-method=ZC2-HMAC-SHA256 / x-zc-timestamp / Authorization。
 *   - canonicalRequest = "POST\n/\n\ncontent-type:{ct}\nhost:{host}\n\ncontent-type;host\n{sha256hex(body)}"。
 *   - string2sign = "ZC2-HMAC-SHA256\n{unixTimestamp}\n{sha256hex(canonicalRequest)}"。
 *   - signature = bin2hex(HMAC-SHA256(string2sign, secretKeyPassword))。
 *   - Authorization = "ZC2-HMAC-SHA256 Credential={keyId}, SignedHeaders=content-type;host, Signature={sig}"。
 *
 * 每个 service（cdn → x-zc-version=2022-11-20，zga → 2023-07-06）一个实例，绑定 service 名 + 版本。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 code 非空（Zenlayer 统一错误体顶层 {requestId,code,message}）→ 抛
 * ZenlayerApiException（携 code + message，均来自响应体、不含凭证）。Guzzle 本身失败 → 异常上抛，由
 * ZenlayerErrorSanitizer 兜底（仅类名）。成功响应在 `response` 字段下。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class ZenlayerRestClient
{
    private Client $http;

    private const HOST = 'console.zenlayer.com';

    private const ALGORITHM = 'ZC2-HMAC-SHA256';

    /**
     * @param  string  $service  服务名（进 x-zc-service + path /api/v2/{service}，大小写敏感：cdn / zga）
     * @param  string  $version  x-zc-version（cdn=2022-11-20 / zga=2023-07-06）
     * @param  string  $secretKeyId  Zenlayer AccessKeyId
     * @param  string  $secretKeyPassword  Zenlayer AccessKeyPassword（签名密钥）
     */
    public function __construct(
        private readonly string $service,
        private readonly string $version,
        private readonly string $secretKeyId,
        private readonly string $secretKeyPassword,
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
     * 调用一个 action（POST /api/v2/{service}，body 为 JSON 请求结构）。
     *
     * @param  array<string,mixed>  $body  请求体（action 不放 body，放请求头）
     * @return array<string,mixed> 解析后的响应体 `response` 子对象（空响应返回空数组）
     */
    public function call(string $action, array $body = []): array
    {
        // body = JSON 请求结构（对齐 common.Client.ApiCall：json.Marshal(request)）。空对象编为 {}。
        $bodyStr = $body === []
            ? '{}'
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $contentType = 'application/json';
        $timestamp = (string) time();

        // canonicalRequest（canonicalURI 固定 "/"，与 common.signRequest 一致，非 /api/v2/{service}）
        $hashedPayload = hash('sha256', $bodyStr);
        $canonicalHeaders = "content-type:$contentType\nhost:".self::HOST."\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = "POST\n/\n\n$canonicalHeaders\n$signedHeaders\n$hashedPayload";
        $string2sign = self::ALGORITHM."\n$timestamp\n".hash('sha256', $canonicalRequest);
        $signature = bin2hex(hash_hmac('sha256', $string2sign, $this->secretKeyPassword, true));
        $authorization = self::ALGORITHM." Credential=$this->secretKeyId, SignedHeaders=$signedHeaders, Signature=$signature";

        $headers = [
            'x-zc-version' => $this->version,
            'x-zc-service' => $this->service,
            'x-zc-action' => $action,
            'x-zc-sdk-version' => '0.2.45',
            'x-zc-sdk-lang' => 'go',
            'Host' => self::HOST,
            'Content-Type' => $contentType,
            'x-zc-signature-method' => self::ALGORITHM,
            'x-zc-timestamp' => $timestamp,
            'Authorization' => $authorization,
        ];

        $response = $this->http->request('POST', 'https://'.self::HOST.'/api/v2/'.$this->service, [
            RequestOptions::BODY => $bodyStr,
            RequestOptions::HEADERS => $headers,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // Zenlayer 统一错误体顶层 {requestId, code, message}；code 非空即业务失败（对齐 ParseFromHttpResponse）。
        $code = $decoded['code'] ?? null;
        if (is_string($code) && $code !== '') {
            throw new ZenlayerApiException($code, self::message($decoded));
        }

        if ($status < 200 || $status >= 300) {
            throw new ZenlayerApiException((string) $status, 'Zenlayer 接口返回 HTTP '.$status);
        }

        // 成功响应数据在 `response` 子对象。
        return is_array($decoded['response'] ?? null) ? $decoded['response'] : [];
    }

    /** @param array<string,mixed> $decoded */
    private static function message(array $decoded): string
    {
        $msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        return $msg !== '' ? $msg : 'Zenlayer 接口返回错误';
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use GuzzleHttp\Client;
use stdClass;

/**
 * BytePlus REST 薄客户端（火山引擎国际版）。
 *
 * 背景：BytePlus 无官方 PHP SDK（与 certimate 用的 Go SDK 不同）。本类对齐 certimate 引用的
 * `byteplus-sdk-golang` / `byteplus-go-sdk-v2` / sdk3rd/volcengine/tos 的线协议，用主系统的
 * GuzzleHttp\Client + **手写签名**直调 REST，返回 JSON 解析后的 stdClass。
 *
 * 两套签名（BytePlus 与 VolcEngine 完全同算法，仅 host/region/service 不同）：
 *
 * 1. **OpenAPI 签名（HMAC-SHA256，volc/ByteDance v4）** —— cdn/alb/clb/apig/certcenter/medialive
 *    统一网关 host `open.byteplusapi.com`，`Action`/`Version` 在 query string；
 *    POST 类（cdn/apig/certcenter/medialive）带 JSON body，GET 类（alb/clb）把入参摊进 query、body 空。
 *    签 4 个固定头：content-type;host;x-content-sha256;x-date；scope = shortDate/region/service/request；
 *    secret **无前缀**直接入 HMAC 链。响应体形如 `{ResponseMetadata:{RequestId,Error?:{Code,Message}}, Result:{...}}`。
 *    REF: https://docs.byteplus.com/en/docs/byteplus-platform/reference-how-to-calculate-a-signature
 *
 * 2. **TOS 签名（TOS4-HMAC-SHA256，S3 风格）** —— tos
 *    endpoint host `{bucket}.tos-{region}.bytepluses.com`，service 固定 `tos`。
 *    签头含 host + x-tos-date + x-tos-content-sha256（payload sha256）；canonical 与 OpenAPI 同骨架但前缀/scope 不同。
 *    REF: https://docs.byteplus.com/en/docs/tos/reference-signature-mechanism_1
 *
 * 脱敏说明：两套签名都把派生凭证放 **请求头**（Authorization / X-Tos-*），故任何 host/URL/body 都不含
 * AK/SK，签名值也不进异常。错误归一为 BytePlusApiException（仅响应体来源的 code+描述），交 BytePlusErrorSanitizer。
 *
 * 此类是各 deployer 的 makeClient($kind) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class BytePlusRestClient
{
    /** OpenAPI 统一网关 host（BytePlus 国际版）。 */
    public const OPENAPI_HOST = 'open.byteplusapi.com';

    private Client $http;

    /**
     * @param  string  $service  OpenAPI 签名 service（cdn/certificate_service/alb/clb/apig/live）；TOS 模式置 'tos'。
     * @param  string  $region  签名 region（如 ap-singapore-1）。
     * @param  string  $accessKeyId  AccessKey ID。
     * @param  string  $secretAccessKey  SecretAccessKey。
     * @param  string  $host  请求 host：OpenAPI 用 OPENAPI_HOST；TOS 用 {bucket}.tos-{region}.bytepluses.com。
     * @param  Client|null  $http  注入用（测试可传自带 handler 的 Guzzle）；默认带 10s 连接 + 30s 总超时。
     */
    public function __construct(
        private readonly string $service,
        private readonly string $region,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $host = self::OPENAPI_HOST,
        ?Client $http = null,
    ) {
        // 设超时上限（防上游慢/挂时 worker 长期阻塞）；http_errors=false 让我们自行解析错误体（拿 volc 错误码）。
        $this->http = $http ?? new Client([
            'http_errors' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
        ]);
    }

    /**
     * 发起一次 volc/BytePlus OpenAPI 请求并返回响应体的 `Result`（stdClass）。
     *
     * @param  string  $method  HTTP 方法（GET 把 $query 当全部入参；POST 入参在 $body JSON）。
     * @param  string  $action  OpenAPI Action（如 BatchDeployCert / UploadCertificate / ModifyListenerAttributes）。
     * @param  string  $version  OpenAPI Version（如 2021-03-01 / 2021-06-01 / 2020-04-01）。
     * @param  array<string,scalar>  $query  业务 query 参数（GET 类放全部入参；POST 类一般为空）。Action/Version 由本方法补。
     * @param  array<string,mixed>|null  $body  POST JSON body（GET 类传 null）。
     */
    public function openApi(string $method, string $action, string $version, array $query = [], ?array $body = null): stdClass
    {
        $method = strtoupper($method);
        // Action / Version 始终在 query string（与业务 query 合并后参与签名 CanonicalQueryString）。
        $query = array_merge($query, ['Action' => $action, 'Version' => $version]);

        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $contentType = $body === null ? 'application/x-www-form-urlencoded' : 'application/json; charset=utf-8';

        $headers = $this->signOpenApi($method, '/', $query, $payload, $contentType);

        $url = 'https://'.$this->host.'/?'.$this->canonicalQueryString($query);
        // http_errors=false 同时设在客户端默认与每请求选项：注入的 client 即便未关 http_errors，
        // 也由此 per-request 选项兜底（不抛 Guzzle 4xx/5xx 异常，让我们自行解析 volc 错误体拿错误码）。
        $response = $this->http->request($method, $url, [
            'headers' => $headers,
            'body' => $payload,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $json = json_decode($raw);
        $json = $json instanceof stdClass ? $json : new stdClass;

        // volc 网关错误：响应体 ResponseMetadata.Error.{Code,Message}（HTTP 状态码可能仍是 200/4xx/5xx）。
        $error = $json->ResponseMetadata->Error ?? null;
        if (is_object($error) && (($error->Code ?? '') !== '' || ($error->Message ?? '') !== '')) {
            throw new BytePlusApiException(
                (string) ($error->Code ?? 'BytePlusError'),
                (string) ($error->Message ?? 'BytePlus 接口返回错误'),
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new BytePlusApiException((string) $status, 'BytePlus 接口返回 HTTP '.$status);
        }

        $result = $json->Result ?? null;

        return $result instanceof stdClass ? $result : new stdClass;
    }

    /**
     * TOS PutBucketCustomDomain 等：PUT 到 TOS bucket（S3 风格 TOS4 签名），返回解析后的 stdClass。
     *
     * @param  string  $path  资源路径（含查询子资源，如 /?customdomain）。
     * @param  array<string,mixed>|null  $body  JSON body（CustomDomainRule 等）。
     */
    public function tosPut(string $path, ?array $body = null): stdClass
    {
        return $this->tosRequest('PUT', $path, $body);
    }

    /**
     * @param  array<string,mixed>|null  $body
     */
    private function tosRequest(string $method, string $path, ?array $body): stdClass
    {
        $method = strtoupper($method);
        // 拆 path 与 query（TOS 子资源如 ?customdomain）。
        $rawQuery = '';
        if (($pos = strpos($path, '?')) !== false) {
            $rawQuery = substr($path, $pos + 1);
            $path = substr($path, 0, $pos);
        }
        $path = $path === '' ? '/' : $path;

        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = strtolower(hash('sha256', $payload));

        $headers = $this->signTos($method, $path, $rawQuery, $payloadHash);
        if ($payload !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $url = 'https://'.$this->host.$path.($rawQuery === '' ? '' : '?'.$rawQuery);
        $response = $this->http->request($method, $url, [
            'headers' => $headers,
            'body' => $payload,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            // TOS 错误体可能是 JSON（{Code,Message}）或 XML（<Error><Code>..），best-effort 取 Code。
            [$code, $message] = $this->parseTosError($raw, $status);
            throw new BytePlusApiException($code, $message);
        }

        $json = $raw === '' ? null : json_decode($raw);

        return $json instanceof stdClass ? $json : new stdClass;
    }

    // ============================ OpenAPI 签名 ============================

    /**
     * volc/BytePlus OpenAPI HMAC-SHA256 v4 签名，返回需随请求发送的头（含 Authorization）。
     *
     * @param  array<string,scalar>  $query
     * @return array<string,string>
     */
    private function signOpenApi(string $method, string $canonicalUri, array $query, string $payload, string $contentType): array
    {
        $now = gmdate('Ymd\THis\Z');           // 20060102T150405Z（UTC）
        $shortDate = substr($now, 0, 8);        // 20060102
        $payloadHash = strtolower(hash('sha256', $payload));

        // 固定签 4 个头（小写名、trim 值、ASCII 升序）：content-type;host;x-content-sha256;x-date。
        $canonicalHeaders = "content-type:$contentType\n"
            ."host:{$this->host}\n"
            ."x-content-sha256:$payloadHash\n"
            ."x-date:$now\n";
        $signedHeaders = 'content-type;host;x-content-sha256;x-date';

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $this->canonicalQueryString($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "$shortDate/{$this->region}/{$this->service}/request";
        $stringToSign = implode("\n", [
            'HMAC-SHA256',
            $now,
            $credentialScope,
            strtolower(hash('sha256', $canonicalRequest)),
        ]);

        $signature = $this->deriveSignature($stringToSign, $shortDate);
        $authorization = "HMAC-SHA256 Credential={$this->accessKeyId}/$credentialScope, "
            ."SignedHeaders=$signedHeaders, Signature=$signature";

        return [
            'Host' => $this->host,
            'X-Date' => $now,
            'X-Content-Sha256' => $payloadHash,
            'Content-Type' => $contentType,
            'Authorization' => $authorization,
        ];
    }

    // ============================ TOS 签名（S3 风格 TOS4） ============================

    /**
     * TOS4-HMAC-SHA256 签名（S3 风格），返回需随请求发送的头（含 Authorization）。
     *
     * 对齐 sdk3rd/volcengine/tos/signer.go：service 固定 "tos"，算法头 "TOS4-HMAC-SHA256"，
     * 日期头 X-Tos-Date、内容头 X-Tos-Content-Sha256；签 host + x-tos-date + x-tos-content-sha256。
     *
     * @return array<string,string>
     */
    private function signTos(string $method, string $canonicalUri, string $rawQuery, string $payloadHash): array
    {
        $now = gmdate('Ymd\THis\Z');
        $shortDate = substr($now, 0, 8);

        // 签 3 个头（小写名、ASCII 升序）：host;x-tos-content-sha256;x-tos-date。
        $canonicalHeaders = "host:{$this->host}\n"
            ."x-tos-content-sha256:$payloadHash\n"
            ."x-tos-date:$now\n";
        $signedHeaders = 'host;x-tos-content-sha256;x-tos-date';

        $canonicalRequest = implode("\n", [
            $method,
            $this->escapeTosPath($canonicalUri),
            $this->canonicalTosQueryString($rawQuery),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "$shortDate/{$this->region}/tos/request";
        $stringToSign = implode("\n", [
            'TOS4-HMAC-SHA256',
            $now,
            $credentialScope,
            strtolower(hash('sha256', $canonicalRequest)),
        ]);

        $signature = $this->deriveSignature($stringToSign, $shortDate, 'tos');
        $authorization = "TOS4-HMAC-SHA256 Credential={$this->accessKeyId}/$credentialScope, "
            ."SignedHeaders=$signedHeaders, Signature=$signature";

        return [
            'Host' => $this->host,
            'X-Tos-Date' => $now,
            'X-Tos-Content-Sha256' => $payloadHash,
            'Authorization' => $authorization,
        ];
    }

    // ============================ 公共：签名密钥派生 + 编码 ============================

    /**
     * HMAC 链派生最终签名（lowercase hex）。secret **无前缀**（与 AWS 的 "AWS4" 前缀不同）。
     *   kDate = HMAC(secret, shortDate) → kRegion = HMAC(kDate, region)
     *   → kService = HMAC(kRegion, service) → kSigning = HMAC(kService, "request")
     *   signature = hex( HMAC(kSigning, stringToSign) )
     *
     * @param  string|null  $serviceOverride  TOS 用固定 "tos"；OpenAPI 用 $this->service。
     */
    private function deriveSignature(string $stringToSign, string $shortDate, ?string $serviceOverride = null): string
    {
        $service = $serviceOverride ?? $this->service;
        $kDate = hash_hmac('sha256', $shortDate, $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * CanonicalQueryString（OpenAPI）：key 按 ASCII 升序，RFC3986 编码（空格 %20、大写 hex），key=value 用 & 连。
     * 与实际发送的 URL query 必须是同一串。
     *
     * @param  array<string,scalar>  $query
     */
    private function canonicalQueryString(array $query): string
    {
        ksort($query);
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = $this->rfc3986((string) $key).'='.$this->rfc3986((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * CanonicalQueryString（TOS）：从原始 query 串解析 → key 升序 RFC3986 重编码。空查询返回空串。
     */
    private function canonicalTosQueryString(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $params = [];
        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $params[urldecode($k)] = urldecode($v);
        }
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $this->rfc3986((string) $k).'='.$this->rfc3986((string) $v);
        }

        return implode('&', $pairs);
    }

    /** RFC3986 编码（PHP rawurlencode 已合规：空格→%20、大写 hex、不编码 -_.~）。 */
    private function rfc3986(string $value): string
    {
        return rawurlencode($value);
    }

    /** TOS CanonicalURI 路径转义（保留 "/"，其余字符 RFC3986；对齐 signer.go escapePath）。 */
    private function escapeTosPath(string $path): string
    {
        $segments = explode('/', $path);
        $segments = array_map(fn (string $s): string => rawurlencode($s), $segments);

        return implode('/', $segments);
    }

    /**
     * 解析 TOS 错误体（JSON {Code,Message} 或 XML <Error><Code>..</Code><Message>..），best-effort。
     *
     * @return array{0:string,1:string}
     */
    private function parseTosError(string $raw, int $status): array
    {
        $json = json_decode($raw);
        if ($json instanceof stdClass && (($json->Code ?? '') !== '' || ($json->Message ?? '') !== '')) {
            return [(string) ($json->Code ?? (string) $status), (string) ($json->Message ?? 'TOS 接口返回错误')];
        }

        $code = '';
        $message = '';
        if (preg_match('#<Code>(.*?)</Code>#s', $raw, $m) === 1) {
            $code = trim($m[1]);
        }
        if (preg_match('#<Message>(.*?)</Message>#s', $raw, $m) === 1) {
            $message = trim($m[1]);
        }

        return [
            $code !== '' ? $code : (string) $status,
            $message !== '' ? $message : 'TOS 接口返回 HTTP '.$status,
        ];
    }
}

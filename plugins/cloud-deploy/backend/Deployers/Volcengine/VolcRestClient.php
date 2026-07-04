<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 火山引擎（VolcEngine）OpenAPI REST 薄客户端（含签名）。
 *
 * 火山引擎无官方 PHP SDK。本类照 certimate 引用的官方 Go SDK（volcengine-go-sdk / volc-sdk-golang）
 * 复刻火山 OpenAPI 签名（HMAC-SHA256，AWS-SigV4 风格：Credential Scope + Signed Headers + Canonical Request）
 * + Guzzle 直调 REST。逐字节对齐 volc 官方签名实现（algorithm `HMAC-SHA256`、credential scope 末段 `request`、
 * 初始密钥为原始 secretAccessKey、signed headers 固定 `content-type;host;x-content-sha256;x-date`）。
 *
 * 三种调用形态（覆盖全部 11 端点）：
 *   1. callJson(...)  —— POST /?Action=X&Version=Y，JSON body，Content-Type application/json。
 *                        用于 cdn/dcdn/apig/vod/waf/certcenter/live（含 imagex UpdateHttps 的 JSON body 变体经 query 透传 ServiceId）。
 *   2. callQuery(...) —— GET /?Action=X&Version=Y&{flattened params}，无 body。
 *                        用于 alb/clb 的 universal/RPC 协议（结构体平铺进 query：list 用 `Key.1.Field` 1-based 点分）。
 *   3. putTos(...)    —— TOS 对象存储自定义域名，S3 风格签名（TOS4-HMAC-SHA256，service=tos，
 *                        host {bucket}.tos-{region}.volces.com，path /?customdomain）。
 *
 * 鉴权：签名串含 method+canonicalUri+canonicalQuery+canonicalHeaders+signedHeaders+hashedPayload，
 * 其中 hashedPayload=X-Content-Sha256=sha256(发送 body)。故签名用的 body 与实际发送 body 必须是**同一字符串**
 * （本类先 json_encode 一次再分别喂签名头与 Guzzle body）。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 ResponseMetadata.Error 非空 → 抛 VolcApiException（携火山 Error.Code +
 * Error.Message，均来自响应体、不含凭证）。Guzzle 本身失败（连接/超时）→ 异常上抛，由 VolcErrorSanitizer 兜底（仅类名）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class VolcRestClient
{
    /** 通用 OpenAPI 网关 host（cdn/dcdn/alb/clb/apig/vod/waf/certcenter 等 volcengine-go-sdk 服务统一走此 host）。 */
    public const OPEN_HOST = 'open.volcengineapi.com';

    private Client $http;

    /**
     * @param  string  $host  OpenAPI host（通用 open.volcengineapi.com，或 live/imagex 的专属 host）
     * @param  string  $service  签名服务名（进 credential scope，大小写敏感：cdn/dcdn/alb/clb/apig/vod/waf/certificate_service/live/ImageX/tos）
     * @param  string  $region  签名区域（进 credential scope，如 cn-beijing / cn-north-1）
     * @param  string  $accessKeyId  火山 AccessKeyId
     * @param  string  $secretAccessKey  火山 SecretAccessKey
     */
    public function __construct(
        private readonly string $host,
        private readonly string $service,
        private readonly string $region,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            // 上游慢/挂时不让 worker 长期阻塞（与 Baidu/Aliyun 锁外约定一致）。
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * POST /?Action=X&Version=Y，JSON body。火山 universal 协议主形态。
     *
     * @param  array<string,mixed>  $body  请求体（PascalCase 键，与火山 OpenAPI 入参对齐）
     * @param  array<string,string>  $extraQuery  额外 query 参数（如 imagex UpdateHttps 的 ServiceId）
     * @return array<string,mixed> 响应体 Result（无则空数组）
     */
    public function callJson(string $action, string $version, array $body, array $extraQuery = []): array
    {
        $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $query = array_merge(['Action' => $action, 'Version' => $version], $extraQuery);

        return $this->request('POST', $query, $payload, 'application/json');
    }

    /**
     * GET /?Action=X&Version=Y&{flattened params}，无 body。火山 RPC（universal query）协议。
     * 用于 alb/clb：结构体平铺进 query（嵌套 list 用 `Key.1.Field` 1-based 点分，键名 PascalCase）。
     *
     * @param  array<string,mixed>  $params  结构化入参（标量 / list<array<string,scalar>>）
     * @return array<string,mixed> 响应体 Result（无则空数组）
     */
    public function callQuery(string $action, string $version, array $params): array
    {
        $flat = self::flattenQuery($params);
        $query = array_merge(['Action' => $action, 'Version' => $version], $flat);

        return $this->request('GET', $query, '', '');
    }

    /**
     * TOS 自定义域名绑定：PUT https://{bucket}.tos-{region}.volces.com/?customdomain，S3 风格签名。
     * 与 OpenAPI 签名同族但 algorithm=TOS4-HMAC-SHA256、service=tos、签名头为 x-tos-* 体系。
     *
     * @param  array<string,mixed>  $body  PutBucketCustomDomain 请求体
     */
    public function putTos(string $bucket, string $region, array $body): void
    {
        $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tosHost = "$bucket.tos-$region.volces.com";

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $hashedPayload = hash('sha256', $payload);

        // TOS 签名头：host / x-tos-date / x-tos-content-sha256（content-type 仅在含 x-tos-content-sha256 时纳入签名）
        $headers = [
            'Host' => $tosHost,
            'X-Tos-Date' => $now,
            'X-Tos-Content-Sha256' => $hashedPayload,
            'Content-Type' => 'application/json',
        ];

        $signed = self::tosSignedHeaderNames();
        $canonicalHeaders = self::buildCanonicalHeaders($headers, $signed);
        $signedHeaders = implode(';', $signed);

        $canonicalRequest = implode("\n", [
            'PUT',
            '/',
            'customdomain=',
            $canonicalHeaders,
            $signedHeaders,
            $hashedPayload,
        ]);

        $algorithm = 'TOS4-HMAC-SHA256';
        $scope = "$date/$this->region/tos/request";
        $stringToSign = implode("\n", [
            $algorithm,
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = $this->deriveSignature($date, 'tos', $stringToSign);
        $authorization = "$algorithm Credential=$this->accessKeyId/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

        $headers['Authorization'] = $authorization;

        $response = $this->http->request('PUT', "https://$tosHost/?customdomain", [
            RequestOptions::HEADERS => $headers,
            RequestOptions::BODY => $payload,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($raw, true);
            $err = is_array($decoded) ? ($decoded['Message'] ?? $decoded['message'] ?? null) : null;
            $code = is_array($decoded) ? ($decoded['Code'] ?? $decoded['code'] ?? null) : null;
            throw new VolcApiException(
                is_string($code) && $code !== '' ? $code : (string) $status,
                is_string($err) && $err !== '' ? $err : '火山引擎 TOS 接口返回错误',
            );
        }
    }

    /**
     * 发起一次签名后的 OpenAPI 请求并归一响应/错误。
     *
     * @param  array<string,mixed>  $query  query 参数（含 Action/Version；callQuery 已平铺）
     * @return array<string,mixed> Result
     */
    private function request(string $method, array $query, string $payload, string $contentType): array
    {
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $hashedPayload = hash('sha256', $payload);

        $headers = [
            'Host' => $this->host,
            'X-Date' => $now,
            'X-Content-Sha256' => $hashedPayload,
        ];
        if ($contentType !== '') {
            $headers['Content-Type'] = $contentType;
        }

        // 固定 signed headers（火山官方默认集）：有 body 时含 content-type，无 body（GET query）时不含。
        $signed = $contentType !== ''
            ? ['content-type', 'host', 'x-content-sha256', 'x-date']
            : ['host', 'x-content-sha256', 'x-date'];

        $canonicalQuery = self::buildCanonicalQuery($query);
        $canonicalHeaders = self::buildCanonicalHeaders($headers, $signed);
        $signedHeaders = implode(';', $signed);

        $canonicalRequest = implode("\n", [
            $method,
            '/',
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $hashedPayload,
        ]);

        $algorithm = 'HMAC-SHA256';
        $scope = "$date/$this->region/$this->service/request";
        $stringToSign = implode("\n", [
            $algorithm,
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = $this->deriveSignature($date, $this->service, $stringToSign);
        $headers['Authorization'] = "$algorithm Credential=$this->accessKeyId/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

        $url = "https://$this->host/?".self::encodeQuery($query);
        $options = [RequestOptions::HEADERS => $headers];
        if ($payload !== '') {
            $options[RequestOptions::BODY] = $payload;
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // 火山响应体统一 {ResponseMetadata:{..., Error:{Code,Message}}, Result:{...}}；Error 非空即业务失败
        $meta = is_array($decoded['ResponseMetadata'] ?? null) ? $decoded['ResponseMetadata'] : [];
        $error = is_array($meta['Error'] ?? null) ? $meta['Error'] : [];
        if ($error !== []) {
            $code = is_string($error['Code'] ?? null) && $error['Code'] !== '' ? $error['Code'] : (string) $status;
            $msg = is_string($error['Message'] ?? null) && $error['Message'] !== '' ? $error['Message'] : '火山引擎接口返回错误';
            throw new VolcApiException($code, $msg);
        }

        if ($status < 200 || $status >= 300) {
            throw new VolcApiException((string) $status, '火山引擎接口返回 HTTP '.$status);
        }

        return is_array($decoded['Result'] ?? null) ? $decoded['Result'] : [];
    }

    /**
     * 派生签名：kDate=HMAC(secret, date) → kRegion=HMAC(kDate, region) → kService=HMAC(kRegion, service)
     * → kSigning=HMAC(kService, "request") → signature=hex(HMAC(kSigning, stringToSign))。
     * 初始密钥为**原始** secretAccessKey（无任何前缀），末段字面量为 "request"。
     */
    private function deriveSignature(string $date, string $service, string $stringToSign): string
    {
        $kDate = hash_hmac('sha256', $date, $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * Canonical query string：键排序，RFC3986 编码（空格 %20、+ → %20），`key=value&...`。
     *
     * @param  array<string,mixed>  $query
     */
    private static function buildCanonicalQuery(array $query): string
    {
        $keys = array_keys($query);
        sort($keys, SORT_STRING);

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::rfc3986((string) $key).'='.self::rfc3986((string) $query[$key]);
        }

        return implode('&', $parts);
    }

    /**
     * Canonical headers：每行 `lowercasename:trimmedvalue\n`，按 header 名排序，整体含尾换行。
     *
     * @param  array<string,string>  $headers
     * @param  list<string>  $signed  已排序的小写 signed header 名
     */
    private static function buildCanonicalHeaders(array $headers, array $signed): string
    {
        $lines = '';
        foreach ($signed as $name) {
            $value = trim((string) self::headerByLower($headers, $name));
            $lines .= "$name:$value\n";
        }

        return $lines;
    }

    /**
     * TOS signed header 名（小写、已排序）：content-type;host;x-tos-content-sha256;x-tos-date。
     *
     * @return list<string>
     */
    private static function tosSignedHeaderNames(): array
    {
        $names = ['content-type', 'host', 'x-tos-content-sha256', 'x-tos-date'];
        sort($names, SORT_STRING);

        return $names;
    }

    /** 按小写名从（可能混合大小写键的）headers 取值。 */
    private static function headerByLower(array $headers, string $lowerName): string
    {
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $lowerName) {
                return (string) $v;
            }
        }

        return '';
    }

    /**
     * 实际发送 URL 的 query 编码（与 canonical 同规则：RFC3986 + 键排序，保证签名一致）。
     *
     * @param  array<string,mixed>  $query
     */
    private static function encodeQuery(array $query): string
    {
        return self::buildCanonicalQuery($query);
    }

    /**
     * 平铺结构化入参为火山 universal query 键（用于 alb/clb GET 协议）：
     *   - 标量 → `Key=value`
     *   - list<array> → `Key.1.Field=value`、`Key.2.Field=value`（1-based，点分，字段名 PascalCase 原样）
     * 仅支持「标量」与「list of 标量 map」两层（覆盖 alb DomainExtensions / clb 全部入参，对齐 certimate）。
     *
     * @param  array<string,mixed>  $params
     * @return array<string,string>
     */
    private static function flattenQuery(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // list of maps：Key.{i}.Field
                foreach (array_values($value) as $i => $item) {
                    $index = $i + 1;
                    if (is_array($item)) {
                        foreach ($item as $field => $fieldValue) {
                            if ($fieldValue === null) {
                                continue;
                            }
                            $out["$key.$index.$field"] = self::scalar($fieldValue);
                        }
                    } else {
                        // list of 标量：Key.{i}
                        if ($item !== null) {
                            $out["$key.$index"] = self::scalar($item);
                        }
                    }
                }
            } elseif ($value !== null) {
                $out[(string) $key] = self::scalar($value);
            }
        }

        return $out;
    }

    /** 标量归一为字符串（bool → true/false，与火山 query 约定一致）。 */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /** RFC3986 编码（PHP rawurlencode 已符合：空格 %20、不转义 -_.~）。 */
    private static function rfc3986(string $value): string
    {
        return rawurlencode($value);
    }
}

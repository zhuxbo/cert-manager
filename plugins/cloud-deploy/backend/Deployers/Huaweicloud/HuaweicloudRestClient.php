<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 华为云开放 API REST 薄客户端（含 SDK-HMAC-SHA256 签名）。
 *
 * 华为云官方 PHP SDK（huaweicloud-sdk-php-v3）按服务拆成数十个包、且依赖体量大；本插件「禁止 composer」，故照
 * certimate 引用的官方 go-v3 SDK 的 REST endpoint + 参数，**手写华为云 SDK-HMAC-SHA256 签名** + Guzzle 直调 REST。
 *
 * 签名算法（逐字节对齐华为云官方 SDK core/auth/signer，全语言一致；文档 https://support.huaweicloud.com/api-apiexplorer/）：
 *   1. CanonicalRequest = Method\nCanonicalURI\nCanonicalQuery\nCanonicalHeaders\nSignedHeaders\nHexSHA256(body)
 *      - CanonicalURI：path 按 RFC3986 编码（保留 `/`），**必须以 `/` 结尾**。
 *      - CanonicalQuery：键升序，每项 enc(k)=enc(v)（RFC3986，空格 %20），`&` 连接。
 *      - CanonicalHeaders：参与签名的头，name 小写、value trim，按 name 升序，每行 `name:value\n`。
 *      - SignedHeaders：参与签名头名（小写）升序、`;` 连接。固定含 host + x-sdk-date；项目 ID 非空时含 x-project-id。
 *   2. StringToSign = "SDK-HMAC-SHA256\n{X-Sdk-Date}\nHexSHA256(CanonicalRequest)"。
 *   3. signature = HexHMACSHA256(SK, StringToSign)。
 *   4. Authorization = "SDK-HMAC-SHA256 Access={AK}, SignedHeaders={signedHeaders}, Signature={signature}"。
 *   5. X-Sdk-Date 头 = UTC `Ymd\THis\Z`。
 *
 * 服务 host（每个 deployer 的 makeClient 按服务 + region 构造一个实例并绑定 host）：
 *   - 全局服务：cdn.myhuaweicloud.com（CDN）。
 *   - region 服务：{service}.{region}.myhuaweicloud.com（scm/elb/waf/live/apig/iam/aad...）。
 * host 由调用方传入（deployer 决定），本类只管签名 + 传输。
 *
 * 错误归一：HTTP 非 2xx 或 响应体含 error_code（华为云统一错误体 {error_code,error_msg}）→ 抛 HuaweicloudApiException
 * （携华为云 error_code + error_msg，均来自响应体、不含凭证）。Guzzle 本身失败（连接/超时）→ 异常上抛，
 * 由 HuaweicloudErrorSanitizer 兜底（仅类名）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class HuaweicloudRestClient
{
    private Client $http;

    /**
     * @param  string  $host  服务 endpoint host（如 scm.cn-north-4.myhuaweicloud.com / cdn.myhuaweicloud.com）
     * @param  string  $accessKeyId  华为云 AccessKeyId
     * @param  string  $secretAccessKey  华为云 SecretAccessKey
     * @param  string  $projectId  项目 ID（region 服务的 basic auth 需要；置入 X-Project-Id 头，certimate basic.WithProjectId 同义）
     */
    public function __construct(
        private readonly string $host,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $projectId = '',
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            // 上游慢/挂时不让 worker 长期阻塞（与 Baidu/Ksyun/Aliyun 锁外约定一致）。
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * GET 请求（查询端点）。
     *
     * @param  array<string,scalar>  $query  URL 查询参数（标量；bool/int 归一为字符串）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null);
    }

    /**
     * POST 请求（JSON body 端点）。
     *
     * @param  array<string,scalar>  $query
     * @param  array<string,mixed>|null  $body  请求体（json_encode 后参与签名）
     * @return array<string,mixed>
     */
    public function post(string $path, ?array $body = null, array $query = []): array
    {
        return $this->send('POST', $path, $query, $body);
    }

    /**
     * PUT 请求（JSON body 端点，如 ELB/WAF 更新证书）。
     *
     * @param  array<string,scalar>  $query
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    public function put(string $path, ?array $body = null, array $query = []): array
    {
        return $this->send('PUT', $path, $query, $body);
    }

    /**
     * 发起一次签名后的请求并归一响应/错误。
     *
     * @param  array<string,scalar>  $query
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body): array
    {
        $method = strtoupper($method);
        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Host' => $this->host,
            'Content-Type' => 'application/json',
            'X-Sdk-Date' => gmdate('Ymd\THis\Z'),
        ];
        if ($this->projectId !== '') {
            // region 服务的项目隔离头（对齐 certimate basic.NewCredentialsBuilder().WithProjectId）。
            $headers['X-Project-Id'] = $this->projectId;
        }

        $authorization = $this->sign($method, $path, $query, $payload, $headers);
        $headers['Authorization'] = $authorization;

        $url = 'https://'.$this->host.$path;
        $options = [RequestOptions::HEADERS => $headers];
        if ($query !== []) {
            $options[RequestOptions::QUERY] = self::stringifyParams($query);
        }
        if ($body !== null) {
            $options[RequestOptions::BODY] = $payload;
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = self::decodeResponseObject($raw);
        if ($decoded === null) {
            if ($status < 200 || $status >= 300) {
                throw new HuaweicloudApiException((string) $status, '华为云接口返回 HTTP '.$status);
            }

            throw new HuaweicloudApiException('HuaweicloudInvalidResponse', '华为云接口返回无效响应');
        }

        // 华为云统一错误体：{error_code, error_msg}（部分服务用 {error:{code,message}} 或顶层 error_code）。
        $errorCode = self::extractErrorCode($decoded);
        if ($errorCode !== '') {
            throw new HuaweicloudApiException($errorCode, self::extractErrorMessage($decoded));
        }

        if ($status < 200 || $status >= 300) {
            throw new HuaweicloudApiException((string) $status, '华为云接口返回 HTTP '.$status);
        }

        return $decoded;
    }

    /** @return array<string,mixed>|null null 表示非空响应不是合法 JSON 对象 */
    private static function decodeResponseObject(string $raw): ?array
    {
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && str_starts_with(ltrim($raw), '{') ? $decoded : null;
    }

    /**
     * 计算 SDK-HMAC-SHA256 Authorization 头。
     *
     * @param  array<string,scalar>  $query
     * @param  array<string,string>  $headers  待签名头（至少含 Host / X-Sdk-Date）
     */
    private function sign(string $method, string $path, array $query, string $payload, array $headers): string
    {
        $sdkDate = $headers['X-Sdk-Date'];

        $signed = [
            'host' => $this->host,
            'x-sdk-date' => $sdkDate,
        ];
        if (isset($headers['X-Project-Id']) && $headers['X-Project-Id'] !== '') {
            // 对齐华为云 Go SDK v0.1.208：项目隔离头存在时也进入 CanonicalHeaders / SignedHeaders。
            $signed['x-project-id'] = $headers['X-Project-Id'];
        }
        ksort($signed, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }
        $signedHeaders = implode(';', array_keys($signed));

        $canonicalRequest = implode("\n", [
            $method,
            self::canonicalUri($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);

        $stringToSign = implode("\n", [
            'SDK-HMAC-SHA256',
            $sdkDate,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->secretAccessKey);

        return sprintf(
            'SDK-HMAC-SHA256 Access=%s, SignedHeaders=%s, Signature=%s',
            $this->accessKeyId,
            $signedHeaders,
            $signature,
        );
    }

    /**
     * CanonicalURI：path 按 RFC3986 逐段编码（保留 `/`），并保证以 `/` 结尾（华为云签名要求）。
     */
    private static function canonicalUri(string $path): string
    {
        if ($path === '') {
            $path = '/';
        }

        $segments = explode('/', $path);
        $encoded = array_map(static fn (string $seg): string => self::rfc3986($seg), $segments);
        $uri = implode('/', $encoded);

        if (! str_ends_with($uri, '/')) {
            $uri .= '/';
        }

        return $uri;
    }

    /**
     * CanonicalQueryString：键升序，每项 enc(k)=enc(v)（RFC3986），`&` 连接。
     *
     * @param  array<string,scalar>  $query
     */
    private static function canonicalQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $query = self::stringifyParams($query);
        ksort($query, SORT_STRING);

        $parts = [];
        foreach ($query as $k => $v) {
            $parts[] = self::rfc3986((string) $k).'='.self::rfc3986((string) $v);
        }

        return implode('&', $parts);
    }

    /**
     * RFC3986 编码：PHP rawurlencode 已符合（空格→%20、保留 -_.~、其余 %XX 大写），与华为云签名 escape 一致。
     */
    private static function rfc3986(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * 入参标量归一为字符串（bool→"true"/"false"、int/float→十进制），保证 query 编码与签名一致。
     *
     * @param  array<string,scalar>  $params
     * @return array<string,string>
     */
    private static function stringifyParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $out[$k] = $v ? 'true' : 'false';
            } else {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * 提取华为云错误码（多形态兼容：顶层 error_code / 嵌套 error.code / errorCode）。
     *
     * @param  array<string,mixed>  $decoded
     */
    private static function extractErrorCode(array $decoded): string
    {
        if (is_string($decoded['error_code'] ?? null) && $decoded['error_code'] !== '') {
            return $decoded['error_code'];
        }
        if (is_string($decoded['errorCode'] ?? null) && $decoded['errorCode'] !== '') {
            return $decoded['errorCode'];
        }
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        if (is_string($error['code'] ?? null) && $error['code'] !== '') {
            return $error['code'];
        }

        return '';
    }

    /** @param  array<string,mixed>  $decoded */
    private static function extractErrorMessage(array $decoded): string
    {
        foreach (['error_msg', 'errorMsg', 'message'] as $key) {
            if (is_string($decoded[$key] ?? null) && $decoded[$key] !== '') {
                return $decoded[$key];
            }
        }
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        if (is_string($error['message'] ?? null) && $error['message'] !== '') {
            return $error['message'];
        }

        return '华为云接口返回错误';
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Cmcccloud;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 移动云 eCloud 开放 API REST 薄客户端（含 AKSK 签名）。
 *
 * 移动云无官方 PHP SDK。certimate 用 gitlab.ecloud.com/ecloud/ecloudsdkcore（其 auth.AKSKCredential.Sign）；
 * 本类逐字节复刻其 eCloud AKSK 签名 + Guzzle 直调 REST：
 *   - 签名参数集 params = 业务 query 参数 + AccessKey + Timestamp + SignatureMethod=HmacSHA256 +
 *     SignatureVersion=V2.0 + SignatureNonce（uuid 去横线）。
 *     · Timestamp 取 **Asia/Shanghai** 本地时间、格式 Y-m-d\TH:i:s\Z（eCloud SDK 原样写法：本地时间 + 字面 Z）。
 *   - 键升序，canonicalQueryString = join PercentEncode(k)=PercentEncode(v) 用 &
 *     （PercentEncode = url.QueryEscape 且 +→%20、*→%2A、%7E→~）。
 *   - hashString = lowerhex(sha256(canonicalQueryString))。
 *   - stringToSign = METHOD + "\n" + PercentEncode(unescapedPath) + "\n" + hashString
 *     （unescapedPath = url_decode(gatewayPath)，已做路径参数 {x} 替换、无 query）。
 *   - signature = lowerhex(HMAC-SHA256(stringToSign, "BC_SIGNATURE&" + secretKey))。
 *   - 最终 path = unescapedPath + "?" + canonicalQueryString + "&Signature=" + PercentEncode(signature)。
 *
 * endpoint 由资源池 ID 决定（cmcdn 固定 CIDC-CORE-00 → https://ecloud.10086.cn；vlb 按 poolId 映射，
 * 默认回落 https://ecloud.10086.cn）。POST/PUT body 为 JSON。Pool-Id 请求头透传资源池 ID。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 state ∈ {ERROR,EXCEPTION,FORBIDDEN}（eCloud 统一响应体
 * {state,errorCode,errorMessage,body}）→ 抛 CmcccloudApiException（携 errorCode + errorMessage，
 * 均来自响应体、不含凭证）。Guzzle 本身失败 → 异常上抛，由 CmcccloudErrorSanitizer 兜底（仅类名）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class CmcccloudRestClient
{
    private Client $http;

    /** 资源池 ID → endpoint host 映射（对齐 ecloudsdkcmcdn/ecloudsdkvlb client initRegions；缺失回落默认）。 */
    private const REGION_ENDPOINTS = [
        'CIDC-CORE-00' => 'https://ecloud.10086.cn',
        'CIDC-RP-25' => 'https://console-wuxi-1.cmecloud.cn:8443',
        'CIDC-RP-26' => 'https://console-dongguan-1.cmecloud.cn:8443',
        'CIDC-RP-27' => 'https://console-yaan-1.cmecloud.cn:8443',
        'CIDC-RP-28' => 'https://console-zhengzhou-1.cmecloud.cn:8443',
        'CIDC-RP-29' => 'https://console-beijing-2.cmecloud.cn:8443',
        'CIDC-RP-31' => 'https://console-jinan-1.cmecloud.cn:8443',
        'CIDC-RP-33' => 'https://console-shanghai-1.cmecloud.cn:8443',
        'CIDC-RP-34' => 'https://console-chongqing-1.cmecloud.cn:8443',
        'CIDC-RP-36' => 'https://console-tianjin-1.cmecloud.cn:8443',
    ];

    private const DEFAULT_ENDPOINT = 'https://ecloud.10086.cn';

    private string $endpoint;

    /**
     * @param  string  $accessKey  移动云 AccessKeyId
     * @param  string  $secretKey  移动云 AccessKeySecret
     * @param  string  $poolId  资源池 ID（决定 endpoint + Pool-Id 头）
     */
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $poolId,
        ?Client $http = null,
    ) {
        $this->endpoint = self::REGION_ENDPOINTS[$poolId] ?? self::DEFAULT_ENDPOINT;
        $this->http = $http ?? new Client([
            // 上游慢/挂时不让 worker 长期阻塞（与 Ksyun/Dogecloud/Baidu 锁外约定一致）。
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * 调用一个 eCloud 网关端点。
     *
     * @param  string  $method  HTTP 方法（GET/POST/PUT）
     * @param  string  $gatewayPath  网关 URI（含 {pathParam} 占位）
     * @param  array<string,string>  $pathParams  路径参数（替换 {x}）
     * @param  array<string,scalar>  $query  query 参数（参与签名）
     * @param  array<string,mixed>|null  $body  请求体（POST/PUT 时 json_encode）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function call(string $method, string $gatewayPath, array $pathParams = [], array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);

        // 1) 路径参数替换 + 规范化（对齐 BuildPathParamsString：去尾斜杠、补前导斜杠）
        $path = $gatewayPath;
        foreach ($pathParams as $name => $value) {
            $path = str_replace('{'.$name.'}', $value, $path);
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $path = rtrim($path, '/');

        // 2) 签名参数集（query + 5 个签名字段）
        $signParams = [];
        foreach ($query as $k => $v) {
            $signParams[$k] = self::stringify($v);
        }
        $signParams['AccessKey'] = $this->accessKey;
        // Timestamp：Asia/Shanghai 本地时间 + 字面 Z（对齐 ecloudsdkcore AKSKCredential.Sign）
        $signParams['Timestamp'] = self::shanghaiTimestamp();
        $signParams['SignatureMethod'] = 'HmacSHA256';
        $signParams['SignatureVersion'] = 'V2.0';
        $signParams['SignatureNonce'] = self::nonce();

        // 3) canonicalQueryString（键升序 + PercentEncode）
        $canonicalQueryString = self::canonical($signParams);

        // 4) hashString + stringToSign + signature
        $hashString = strtolower(hash('sha256', $canonicalQueryString));
        $stringToSign = $method."\n".self::percentEncode($path)."\n".$hashString;
        $signature = strtolower(hash_hmac('sha256', $stringToSign, 'BC_SIGNATURE&'.$this->secretKey));

        // 5) 最终 URL
        $url = $this->endpoint.$path.'?'.$canonicalQueryString.'&Signature='.self::percentEncode($signature);

        $options = [RequestOptions::HEADERS => ['Pool-Id' => $this->poolId]];
        if ($body !== null) {
            $options[RequestOptions::BODY] = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // eCloud 统一响应体：{state, errorCode, errorMessage, body}。state 非 OK 即业务失败。
        $state = is_string($decoded['state'] ?? null) ? $decoded['state'] : '';
        if (in_array($state, ['ERROR', 'EXCEPTION', 'FORBIDDEN', 'ALARM'], true)) {
            throw new CmcccloudApiException(self::errorCode($decoded, $status), self::errorMessage($decoded));
        }

        if ($status < 200 || $status >= 300) {
            throw new CmcccloudApiException((string) $status, '移动云接口返回 HTTP '.$status);
        }

        return $decoded;
    }

    /** Asia/Shanghai 本地时间，格式 Y-m-d\TH:i:s\Z（eCloud SDK 原样写法：本地时间 + 字面 Z）。 */
    private static function shanghaiTimestamp(): string
    {
        $dt = new \DateTime('now', new \DateTimeZone('Asia/Shanghai'));

        return $dt->format('Y-m-d\TH:i:s\Z');
    }

    /** uuid v4 去横线（对齐 ecloudsdkcore utils.Nonce）。 */
    private static function nonce(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return bin2hex($bytes);
    }

    /**
     * 键升序 + PercentEncode(k)=PercentEncode(v) 用 & 连接（对齐 AKSKCredential.Sign）。
     *
     * @param  array<string,string>  $params
     */
    private static function canonical(array $params): string
    {
        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = self::percentEncode((string) $k).'='.self::percentEncode((string) $v);
        }

        return implode('&', $parts);
    }

    /**
     * eCloud PercentEncode：url.QueryEscape 后 +→%20、*→%2A、%7E→~（对齐 utils.PercentEncode）。
     * PHP urlencode 与 Go url.QueryEscape 一致（保留 -_.、转义 ~ 为 %7E、空格为 +），故 urlencode +
     * 这三步替换即等价。
     */
    private static function percentEncode(string $value): string
    {
        $encoded = urlencode($value);
        $encoded = str_replace('+', '%20', $encoded);
        $encoded = str_replace('*', '%2A', $encoded);

        return str_replace('%7E', '~', $encoded);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /** @param array<string,mixed> $decoded */
    private static function errorCode(array $decoded, int $status): string
    {
        $code = is_string($decoded['errorCode'] ?? null) ? $decoded['errorCode'] : '';

        return $code !== '' ? $code : (string) $status;
    }

    /** @param array<string,mixed> $decoded */
    private static function errorMessage(array $decoded): string
    {
        $msg = is_string($decoded['errorMessage'] ?? null) ? $decoded['errorMessage'] : '';

        return $msg !== '' ? $msg : '移动云接口返回错误';
    }
}

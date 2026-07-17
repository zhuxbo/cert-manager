<?php

namespace Plugins\CloudDeploy\Deployers\Qingcloud;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 青云 IaaS 开放 API REST 薄客户端（含 QY 签名）。
 *
 * 青云 LB 走老版 IaaS OpenAPI。certimate 用 yunify/qingcloud-sdk-go（其 request/signer.go + builder.go）；
 * 本类逐字节复刻其 QY 签名（HMAC-SHA256，签名在 query/form 的 signature 字段）+ Guzzle 直调 REST：
 *   - 参数集 = 入参（数组按 key.N 平铺，N 从 1 起）+ action + zone + access_key_id +
 *     signature_method=HmacSHA256 + signature_version=1 + time_stamp（ISO8601 UTC，2006-01-02T15:04:05Z）。
 *   - 键升序，urlParams = join PercentEncode(k)=PercentEncode(v) 用 &（PercentEncode = urlencode 且 +→%20）。
 *   - stringToSign = METHOD + "\n" + "/iaas" + "\n" + urlParams。
 *   - signature = url_encode(base64(HMAC-SHA256(stringToSign, secretAccessKey)))。
 *   - GET：urlParams + "&signature=" + signature 作为 query；POST：作为 x-www-form-urlencoded body。
 *
 * endpoint：https://api.qingcloud.com:443/iaas（对齐 certimate 默认 config）。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 ret_code 非 0（青云 IaaS 统一响应体 {ret_code,message}）→ 抛
 * QingcloudApiException（携 ret_code + message，均来自响应体、不含凭证）。Guzzle 本身失败 → 异常上抛，
 * 由 QingcloudErrorSanitizer 兜底（仅类名，因 GET 请求 URL 含签名查询串）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class QingcloudRestClient
{
    private Client $http;

    private const ENDPOINT = 'https://api.qingcloud.com:443/iaas';

    /** 签名串里的 canonical path（对齐 certimate：requestURI 折叠斜杠后为 /iaas）。 */
    private const SIGN_PATH = '/iaas';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $zone,
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
     * GET 请求（Describe* 等查询端点）。
     *
     * @param  array<string,scalar|list<scalar>>  $params  入参（值可为标量或数组；数组按 key.N 平铺）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function get(string $action, array $params = []): array
    {
        return $this->send('GET', $action, $params);
    }

    /**
     * POST 请求（Create/Associate 等写端点，x-www-form-urlencoded）。
     *
     * @param  array<string,scalar|list<scalar>>  $params
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function post(string $action, array $params = []): array
    {
        return $this->send('POST', $action, $params);
    }

    /**
     * 发起一次签名后的请求并归一响应/错误。
     *
     * @param  array<string,scalar|list<scalar>>  $params
     * @return array<string,mixed>
     */
    private function send(string $method, string $action, array $params): array
    {
        $method = strtoupper($method);

        // 1) 入参平铺（数组 → key.N，N 从 1 起，对齐 builder.go parseRequestParams）
        $flat = self::flatten($params);
        // 2) 追加固定签名/路由参数
        $flat['action'] = $action;
        $flat['zone'] = $this->zone;
        $flat['access_key_id'] = $this->accessKeyId;
        $flat['signature_method'] = 'HmacSHA256';
        $flat['signature_version'] = '1';
        $flat['time_stamp'] = gmdate('Y-m-d\TH:i:s\Z');

        // 3) 排序 + canonical（对齐 signer.go BuildStringToSignByValues）
        $urlParams = self::canonical($flat);
        $stringToSign = $method."\n".self::SIGN_PATH."\n".$urlParams;
        // 4) 签名：base64(HMAC-SHA256) → url_encode（对齐 signer.go BuildSignature）
        $signature = rawurlencode(base64_encode(hash_hmac('sha256', $stringToSign, $this->secretAccessKey, true)));

        $options = [];
        if ($method === 'GET') {
            $url = self::ENDPOINT.'?'.$urlParams.'&signature='.$signature;
        } else {
            $url = self::ENDPOINT;
            $options[RequestOptions::BODY] = $urlParams.'&signature='.$signature;
            $options[RequestOptions::HEADERS] = ['Content-Type' => 'application/x-www-form-urlencoded'];
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // 青云 IaaS 统一响应体：{ret_code, message, ...}。ret_code 非 0 即业务失败。
        if (array_key_exists('ret_code', $decoded)) {
            $retCode = (int) $decoded['ret_code'];
            if ($retCode !== 0) {
                throw new QingcloudApiException((string) $retCode, self::message($decoded));
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new QingcloudApiException((string) $status, '青云接口返回 HTTP '.$status);
        }

        return $decoded;
    }

    /**
     * 入参平铺：标量原样，数组（list）按 key.N（N 从 1 起）展开，bool→"true"/"false"，其余转字符串。
     *
     * @param  array<string,scalar|list<scalar>>  $params
     * @return array<string,string>
     */
    private static function flatten(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $index = 1;
                foreach ($value as $item) {
                    $out["$key.$index"] = self::stringify($item);
                    $index++;
                }
            } else {
                $out[$key] = self::stringify($value);
            }
        }

        return $out;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * 键升序 + PercentEncode(k)=PercentEncode(v) 用 & 连接（对齐 signer.go）。
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
     * QY PercentEncode：url.QueryEscape 后 +→%20（对齐 signer.go value 编码）。
     * PHP urlencode 把空格编为 +、保留 -_.、转义 ~ 为 %7E、* 为 %2A —— 与 Go QueryEscape 一致，
     * 故 urlencode + +→%20 即等价。
     */
    private static function percentEncode(string $value): string
    {
        return str_replace('+', '%20', urlencode($value));
    }

    /** @param array<string,mixed> $decoded */
    private static function message(array $decoded): string
    {
        $msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        return $msg !== '' ? $msg : '青云接口返回错误';
    }
}

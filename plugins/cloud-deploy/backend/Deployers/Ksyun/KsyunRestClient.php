<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 金山云开放 API REST 薄客户端（含签名）。
 *
 * 金山云无官方 PHP SDK。本类照 certimate pkg/sdk3rd/ksyun（自写 Go SDK）复刻金山云开放 API 签名
 * （HMAC-SHA256，query/body 参数签名，非 AWS-SigV4 头签名）+ Guzzle 直调 REST。逐字节对齐 certimate
 * 的 signer.go：
 *   - 收集签名参数 params = query 参数 ∪ （非 GET 时）JSON body 的键值对（body 值在 Go SDK 里**全为字符串**）。
 *   - 追加签名字段：Accesskey / Service / Timestamp（UTC `Y-m-d\TH:i:sZ`）/ SignatureVersion=1.0 / SignatureMethod=HMAC-SHA256。
 *   - 键升序排序，逐项 `escapeQuery(k)=escapeQuery(v)` 用 `&` 连接为 stringToSign（escapeQuery = urlencode 且 `+`→`%20`）。
 *   - signature = strtolower(hex(HMAC-SHA256(stringToSign, secretAccessKey)))。
 *   - GET：signature 追加进 query（`req.URL.RawQuery = query.Encode() + "&Signature=" + signature`）。
 *   - 非 GET：Action/Version 从 body 移到 query，其余参数（含 Accesskey/Service/Timestamp/SignatureVersion/SignatureMethod/Signature）作 JSON body。
 *
 * service 进签名串（每个服务一个实例，绑定 service 名 + endpoint host）：cdn → cdn.api.ksyun.com、kcm → kcm.api.ksyun.com。
 *
 * 错误归一：HTTP 非 2xx 或 响应体含 Error 对象（金山云统一错误体 {Error:{Code,Message}}）→ 抛 KsyunApiException
 * （携金山云 Error.Code + Error.Message，均来自响应体、不含凭证）。Guzzle 本身失败（连接/超时）→ 异常上抛，
 * 由 KsyunErrorSanitizer 兜底（仅类名，因 GET 请求 URL 含签名查询串）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class KsyunRestClient
{
    private Client $http;

    /**
     * @param  string  $service  签名服务名（进签名串，大小写敏感：cdn / kcm）
     * @param  string  $endpoint  服务 endpoint host（如 cdn.api.ksyun.com）
     * @param  string  $accessKeyId  金山云 AccessKeyId
     * @param  string  $secretAccessKey  金山云 SecretAccessKey
     */
    public function __construct(
        private readonly string $service,
        private readonly string $endpoint,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            // 上游慢/挂时不让 worker 长期阻塞（与 Baidu/Volc/Aliyun 锁外约定一致）。
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * GET 请求（query 协议）：参数平铺进 query，签名后 Signature 追加进 query。
     * 用于 cdn.GetCdnDomains / kcm.ListUserCertificates / kcm.DescribeCertificates 等查询端点。
     *
     * @param  array<string,scalar>  $params  业务参数（含 Action/Version；标量，bool/int 归一为字符串）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function get(string $path, array $params): array
    {
        return $this->send('GET', $path, $params);
    }

    /**
     * POST 请求（JSON body 协议）：Action/Version 进 query，其余参数 + 签名字段作 JSON body。
     * 用于 cdn.ConfigCertificate / cdn.SetCertificate / kcm.UploadCertificate / kcm.CreateCertificate / kcm.ModifyCertificate 等写端点。
     *
     * @param  array<string,scalar>  $params  业务参数（含 Action/Version；标量，bool/int 归一为字符串）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function post(string $path, array $params): array
    {
        return $this->send('POST', $path, $params);
    }

    /**
     * 发起一次签名后的请求并归一响应/错误。
     *
     * @param  array<string,scalar>  $params
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $params): array
    {
        $method = strtoupper($method);
        // 金山云 Go SDK 把所有入参先转成字符串再进 body/query（含 bool→"true"/"false"、int→十进制）。
        $params = self::stringifyParams($params);

        $signParams = $params;
        $signParams['Accesskey'] = $this->accessKeyId;
        $signParams['Service'] = $this->service;
        $signParams['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
        $signParams['SignatureVersion'] = '1.0';
        $signParams['SignatureMethod'] = 'HMAC-SHA256';

        $signature = $this->sign($signParams);

        $base = 'https://'.$this->endpoint.$path;
        $options = [];

        if ($method === 'GET') {
            // GET：全部参数（业务 + 签名字段）进 query，末尾追加 Signature。
            $query = self::encodeQuery($signParams);
            $url = $base.'?'.$query.'&Signature='.$signature;
        } else {
            // 非 GET：Action/Version 进 query，其余（含签名字段 + Signature）作 JSON body。
            $query = [];
            $body = $signParams;
            foreach (['Action', 'Version'] as $k) {
                if (array_key_exists($k, $body)) {
                    $query[$k] = $body[$k];
                    unset($body[$k]);
                }
            }
            $body['Signature'] = $signature;

            $url = $base;
            if ($query !== []) {
                $url .= '?'.self::encodeQuery($query);
            }
            $options[RequestOptions::BODY] = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[RequestOptions::HEADERS] = ['Content-Type' => 'application/json'];
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        // 金山云统一错误体：{RequestId, Error:{Type?,Code,Message}}。Error 非空即业务失败。
        $error = is_array($decoded['Error'] ?? null) ? $decoded['Error'] : [];
        if ($error !== []) {
            throw new KsyunApiException(self::errorCode($error, $status), self::errorMessage($error));
        }

        if ($status < 200 || $status >= 300) {
            throw new KsyunApiException((string) $status, '金山云接口返回 HTTP '.$status);
        }

        return $decoded;
    }

    /**
     * 计算签名：键升序，逐项 escapeQuery(k)=escapeQuery(v) 用 & 连接，HMAC-SHA256(secretAccessKey)，小写 hex。
     *
     * @param  array<string,string>  $params
     */
    private function sign(array $params): string
    {
        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = self::escapeQuery((string) $k).'='.self::escapeQuery((string) $v);
        }
        $stringToSign = implode('&', $parts);

        return strtolower(hash_hmac('sha256', $stringToSign, $this->secretAccessKey));
    }

    /**
     * 实际发送 URL 的 query 编码（与签名同规则：键升序 + escapeQuery，保证一致；空格 %20、+ → %20）。
     *
     * @param  array<string,string>  $params
     */
    private static function encodeQuery(array $params): string
    {
        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = self::escapeQuery((string) $k).'='.self::escapeQuery((string) $v);
        }

        return implode('&', $parts);
    }

    /**
     * 金山云 escapeQuery：url.QueryEscape 后把 `+` 替换为 `%20`（对齐 signer.go escapeQuery）。
     * PHP urlencode 把空格编为 `+`、其余与 Go QueryEscape 同（`*` → %2A、不转义 -_.，但转义 ~ 为 %7E）。
     * Go url.QueryEscape 同样转义 `~`→%7E、保留 `-_.`，故 urlencode + `+`→`%20` 与之一致。
     */
    private static function escapeQuery(string $value): string
    {
        return str_replace('+', '%20', urlencode($value));
    }

    /**
     * 入参标量归一为字符串（bool→"true"/"false"、int/float→十进制），对齐 Go SDK NewRequest 的 paramsMap 构建。
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
     * 提取错误码：优先 Error.Code（含 Type 前缀时拼 `Type_Code`，对齐 cdn sdkAPIError.Error()），否则 HTTP 状态码。
     *
     * @param  array<string,mixed>  $error
     */
    private static function errorCode(array $error, int $status): string
    {
        $code = is_string($error['Code'] ?? null) ? $error['Code'] : '';
        $type = is_string($error['Type'] ?? null) ? $error['Type'] : '';
        if ($code !== '') {
            return $type !== '' ? "{$type}_{$code}" : $code;
        }

        return (string) $status;
    }

    /** @param  array<string,mixed>  $error */
    private static function errorMessage(array $error): string
    {
        $msg = is_string($error['Message'] ?? null) ? $error['Message'] : '';

        return $msg !== '' ? $msg : '金山云接口返回错误';
    }
}

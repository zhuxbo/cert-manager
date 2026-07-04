<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 天翼云开放平台（EOP）REST 薄客户端（含签名）。
 *
 * 天翼云无官方 PHP SDK。本类照 certimate pkg/sdk3rd/ctyun/zz-shared-common（自写 Go SDK）逐字节复刻天翼云
 * EOP API 签名（HMAC-SHA256 三级派生密钥 + 头签名，非 query/body 签名）+ Guzzle 直调 REST。逐字节对齐
 * signer.go：
 *   - eopDate = UTC `Ymd\THis\Z`（如 20240101T080000Z）；eopReqId = 32 字符随机串（[0-9A-Za-z]）。
 *   - queryStr = url.Values.Encode()（键升序 + QueryEscape，空格编为 `+`）；非 GET 且有 body 时 payloadStr = 原始 body 串，
 *     否则空串；payloadHashHex = lower(hex(sha256(payloadStr)))。
 *   - 三级派生：kTime = HMAC(secretAccessKey, eopDate)；kAk = HMAC(kTime, accessKeyId)；kDate = HMAC(kAk, `Ymd`)。
 *   - stringToSign = "ctyun-eop-request-id:{eopReqId}\neop-date:{eopDate}\n\n{queryStr}\n{payloadHashHex}"。
 *   - signature = base64(HMAC(kDate, stringToSign))。
 *   - 头：ctyun-eop-request-id / eop-date / eop-authorization="{accessKeyId} Headers=ctyun-eop-request-id;eop-date Signature={signature}"。
 *
 * 每个服务一个实例（绑定 baseUrl host + 成功码集）：
 *   - ao   → accessone-global.ctapi.ctyun.cn（成功码 100000）
 *   - cdn  → ctcdn-global.ctapi.ctyun.cn（成功码 100000）
 *   - cms  → ccms-global.ctapi.ctyun.cn（成功码 200）
 *   - elb  → ctelb-global.ctapi.ctyun.cn（成功码 200/800，且 error ∈ {"", "SUCCESS"}）
 *   - faas → cf-global.ctapi.ctyun.cn（成功码 0；用 PUT/GET，regionId 走请求头）
 *   - icdn → icdn-global.ctapi.ctyun.cn（成功码 100000）
 *   - lvdn → ctlvdn-global.ctapi.ctyun.cn（成功码 100000）
 *
 * 错误归一（对齐各 client.go 的 doRequestWithResult）：响应体 statusCode 非成功码、或 error 非空（cms/elb：error
 * 非空即失败，elb 放行 "SUCCESS"），或 HTTP 非 2xx → 抛 CtcccloudApiException（携 statusCode/error/HTTP 状态码
 * + message/errorMessage/description，均来自响应体、不含凭证）。Guzzle 本身失败（连接/超时）→ 异常上抛，由
 * CtcccloudErrorSanitizer 兜底（仅类名）。
 *
 * 此类是各 deployer/uploader 的 makeClient(...) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class CtcccloudRestClient
{
    private Client $http;

    /**
     * @param  string  $endpoint  服务 endpoint host（如 ctcdn-global.ctapi.ctyun.cn）
     * @param  string  $accessKeyId  天翼云 AccessKeyId
     * @param  string  $secretAccessKey  天翼云 SecretAccessKey
     * @param  list<string>  $successCodes  视为成功的 statusCode 集合（默认 ['100000']）
     * @param  bool  $treatNonEmptyErrorAsFailure  是否「error 非空即失败」（cms/elb=true，其余=false）
     * @param  list<string>  $allowedErrors  当 $treatNonEmptyErrorAsFailure=true 时仍放行的 error 值（如 elb 的 'SUCCESS'）
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly array $successCodes = ['100000'],
        private readonly bool $treatNonEmptyErrorAsFailure = false,
        private readonly array $allowedErrors = [],
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
     * GET 请求（query 协议，无 body）。
     *
     * @param  array<string,scalar>  $query  URL 查询参数（标量，bool/int 归一为字符串）
     * @param  array<string,string>  $headers  附加请求头（如 faas 的 regionId）
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->send('GET', $path, $query, null, $headers);
    }

    /**
     * POST 请求（JSON body 协议）。
     *
     * @param  array<string,mixed>  $body  请求体（json_encode 后作 payload；进签名的 payloadHash）
     * @param  array<string,scalar>  $query  URL 查询参数
     * @param  array<string,string>  $headers  附加请求头
     * @return array<string,mixed> 解析后的响应体（空响应返回空数组）
     */
    public function post(string $path, array $body, array $query = [], array $headers = []): array
    {
        return $this->send('POST', $path, $query, $body, $headers);
    }

    /**
     * PUT 请求（JSON body 协议；faas 更新自定义域名用）。
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,scalar>  $query
     * @param  array<string,string>  $headers
     * @return array<string,mixed>
     */
    public function put(string $path, array $body, array $query = [], array $headers = []): array
    {
        return $this->send('PUT', $path, $query, $body, $headers);
    }

    /**
     * 发起一次签名后的请求并归一响应/错误。
     *
     * @param  array<string,scalar>  $query
     * @param  array<string,mixed>|null  $body
     * @param  array<string,string>  $headers
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body, array $headers): array
    {
        $method = strtoupper($method);

        // payload：GET/无 body → 空串；否则 JSON 编码（与 signer.go 对 req.Body 读取的原始串一致）。
        $payload = '';
        if ($method !== 'GET' && $body !== null) {
            // JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE：与 Go json.Marshal（不转义 / 与非 ASCII）默认行为对齐。
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // queryStr：键升序 + QueryEscape（空格 `+`），与 Go url.Values.Encode() 对齐。
        $queryStr = self::encodeQuery(self::stringifyParams($query));

        $signHeaders = $this->sign($queryStr, $payload);

        $url = 'https://'.$this->endpoint.$path;
        if ($queryStr !== '') {
            $url .= '?'.$queryStr;
        }

        $options = [
            RequestOptions::HEADERS => array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ], $headers, $signHeaders),
        ];
        if ($payload !== '') {
            $options[RequestOptions::BODY] = $payload;
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $this->assertSuccess($decoded, $status);

        return $decoded;
    }

    /**
     * 计算 EOP 三级派生密钥签名，返回需附加的请求头。
     *
     * @return array{'ctyun-eop-request-id':string,'eop-date':string,'eop-authorization':string}
     */
    private function sign(string $queryStr, string $payload): array
    {
        $now = $this->currentTime();
        $eopDate = gmdate('Ymd\THis\Z', $now);
        $eopReqId = $this->requestId();

        $payloadHashHex = hash('sha256', $payload);

        // 三级派生（raw binary HMAC，逐级用上一级输出作 key）。
        $kTime = hash_hmac('sha256', $eopDate, $this->secretAccessKey, true);
        $kAk = hash_hmac('sha256', $this->accessKeyId, $kTime, true);
        $kDate = hash_hmac('sha256', gmdate('Ymd', $now), $kAk, true);

        $stringToSign = "ctyun-eop-request-id:{$eopReqId}\neop-date:{$eopDate}\n\n{$queryStr}\n{$payloadHashHex}";
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $kDate, true));

        return [
            'ctyun-eop-request-id' => $eopReqId,
            'eop-date' => $eopDate,
            'eop-authorization' => "{$this->accessKeyId} Headers=ctyun-eop-request-id;eop-date Signature={$signature}",
        ];
    }

    /**
     * 业务成功判定（对齐各 client.go 的 doRequestWithResult），不通过即抛 CtcccloudApiException。
     *
     * @param  array<string,mixed>  $decoded
     */
    private function assertSuccess(array $decoded, int $status): void
    {
        $statusCode = self::scalarToString($decoded['statusCode'] ?? null);
        $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : '';

        // statusCode 非空且不在成功码集 → 失败（对齐 `rStatusCode != "" && rStatusCode != "100000"`）。
        $statusCodeFail = $statusCode !== '' && ! in_array($statusCode, $this->successCodes, true);

        // cms/elb：error 非空即失败（elb 放行 'SUCCESS'）。
        $errorFail = $this->treatNonEmptyErrorAsFailure
            && $error !== ''
            && ! in_array($error, $this->allowedErrors, true);

        if ($statusCodeFail || $errorFail) {
            // 错误码取「真正触发失败」的信号：statusCode 自身不合格则用之；否则（statusCode 合格但 error 触发）用 error。
            $code = $statusCodeFail ? $statusCode : ($error !== '' ? $error : (string) $status);
            throw new CtcccloudApiException($code, self::errorMessage($decoded));
        }

        // 兜底：响应体无 statusCode 但 HTTP 非 2xx（Guzzle http_errors=false 不抛）→ 失败。
        if ($statusCode === '' && ($status < 200 || $status >= 300)) {
            throw new CtcccloudApiException((string) $status, '天翼云接口返回 HTTP '.$status);
        }
    }

    /**
     * 实际发送 URL 的 query 编码（与签名同规则：键升序 + QueryEscape，空格 `+`），对齐 Go url.Values.Encode()。
     *
     * @param  array<string,string>  $params
     */
    private static function encodeQuery(array $params): string
    {
        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $k => $v) {
            // urlencode 与 Go url.QueryEscape 对齐：空格→`+`、保留 `-_.`、转义 `~`→%7E。
            $parts[] = urlencode((string) $k).'='.urlencode((string) $v);
        }

        return implode('&', $parts);
    }

    /**
     * 入参标量归一为字符串（bool→"true"/"false"、int/float→十进制）。
     *
     * @param  array<string,scalar>  $params
     * @return array<string,string>
     */
    private static function stringifyParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        return $out;
    }

    /** statusCode 可能是字符串或数字（Go 用 json.RawMessage 兼容），统一取字符串。 */
    private static function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            // 与 Go strconv.FormatFloat(f, 'f', -1, 64) 对齐：整数值不带小数点。
            return (string) (is_float($value) && $value == (int) $value ? (int) $value : $value);
        }

        return '';
    }

    /** 错误描述：优先 message，其次 errorMessage，再次 description，兜底通用文案。 */
    private static function errorMessage(array $decoded): string
    {
        foreach (['message', 'errorMessage', 'description'] as $key) {
            $val = $decoded[$key] ?? null;
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }

        return '天翼云接口返回错误';
    }

    /** 当前 Unix 时间戳（测试可 override 固定为 KAT 已知值）。 */
    protected function currentTime(): int
    {
        return time();
    }

    /** 32 字符请求 id（测试可 override 固定为 KAT 已知值）。 */
    protected function requestId(): string
    {
        return self::randomString(32);
    }

    /**
     * 32 字符随机串（[0-9A-Za-z]），对齐 certimate security.RandomString。
     * 仅用作请求 id（防重放/追踪），非密码学密钥；用 random_int 取每字符。
     */
    private static function randomString(int $length): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}

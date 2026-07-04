<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use BaiduBce\Auth\BceV1Signer;
use BaiduBce\Exception\BceClientException;
use BaiduBce\Exception\BceServiceException;
use BaiduBce\Http\HttpContentTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Util\DateUtils;
use BaiduBce\Util\HttpUtils;
use DateTime;
use GuzzleHttp\Client;
use stdClass;
use Throwable;

/**
 * 百度智能云 BCE REST 薄客户端。
 *
 * 背景：已安装的 PHP SDK（baidubce/bce-sdk-php）仅内置 Bos/Lss/Media/Sts/Vod 服务 client，
 * **没有** cert / cdn / blb / appblb 的服务 client（与 certimate 用的 Go SDK 不同）。故对齐 certimate
 * 引用的官方 Go SDK 的 REST endpoint + 参数，复用 SDK 自带的 BCE V1 签名（BceV1Signer）直调 REST，
 * 返回 JSON 解析后的 stdClass。
 *
 * HTTP 走主系统 Guzzle 7（GuzzleHttp\Client），**不用** SDK 自带的 BceHttpClient —— 后者底层是古董
 * Guzzle 3.x（`guzzle/guzzle ~3.9`），会拖入 `symfony/event-dispatcher 2.x` 与主系统 7.x 跨大版本冲突，
 * 加上 SDK 的 `psr/log ^1.0.0` 与主系统 Monolog/psr-log 3.x 冲突，在 PHP-FPM 多 worker 下污染主系统类
 * （Monolog::emergency 签名不兼容 → 全站 500）。故插件 composer.json 用 `replace` 把 psr/log、guzzle/guzzle、
 * symfony/event-dispatcher 三个古董挡在插件 vendor 之外；签名/URL 编码仍 100% 复用 SDK 的 BceV1Signer +
 * HttpUtils + DateUtils（纯工具，零第三方依赖），保证 BCE 线协议一致。BceHttpClient/LogFactory 成为不被
 * 加载的死代码（约束见插件「不携带与主系统冲突的共享依赖」）。
 *
 * 每个 deployer 的 makeClient($kind) 返回一个绑定到对应服务 endpoint 的本类实例：
 *   - cert    → certificate.baidubce.com（region-less）
 *   - cdn     → cdn.baidubce.com（region-less）
 *   - blb     → blb.{region}.baidubce.com（region 维度）
 *   - appblb  → blb.{region}.baidubce.com（region 维度，与 blb 同 host 不同 path 前缀）
 *
 * 测试通过 override deployer::makeClient 注入「带同名 request() 方法的 mock」，无需真实网络/签名。
 *
 * 脱敏说明：BCE V1 签名写入 **Authorization 请求头**（非 URL 查询串、非 body），故任何 endpoint host /
 * URL / body 都不含 AK/SK；BceServiceException（结构化 API 错误）字段取自响应体；BceClientException（网络
 * 错误）包底层 Guzzle message（含请求 URL，但 URL 无凭证）。脱敏交给 BaiduErrorSanitizer。
 */
class BaiduRestClient
{
    /** @var array{accessKeyId:string,secretAccessKey:string} BceV1Signer 期望的凭证命名。 */
    private array $credentials;

    private BceV1Signer $signer;

    private Client $http;

    /**
     * @param  string  $endpoint  服务 endpoint host（如 certificate.baidubce.com / blb.{region}.baidubce.com）
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials  插件凭证数组（键名 = BaiduProvider credentialSchema）
     */
    public function __construct(
        private string $endpoint,
        array $credentials,
    ) {
        // BceV1Signer 接受 accessKeyId/secretAccessKey 命名；把插件凭证（access_key_id/secret_access_key）映射过去。
        $this->credentials = [
            'accessKeyId' => $credentials['access_key_id'] ?? '',
            'secretAccessKey' => $credentials['secret_access_key'] ?? '',
        ];
        $this->signer = new BceV1Signer;
        // 设 socket 超时上限（避免上游慢/挂时 worker 长期阻塞）；http_errors=false 自行解析 BCE 结构化错误体。
        $this->http = new Client([
            'connect_timeout' => 10,
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * 发起一次 BCE REST 请求并返回解析后的 JSON（stdClass，空响应体返回空 stdClass）。
     *
     * @param  string  $method  HTTP 方法（GET/POST/PUT/DELETE）
     * @param  string  $path  资源路径（含版本前缀，如 /v1/certificate、/v2/{domain}/certificates）
     * @param  array<string,mixed>|null  $body  请求体（非 null 时 json_encode 并置 Content-Type: application/json）
     * @param  array<string,mixed>  $params  URL 查询串（值为 null 表示无值参数，如 ?certificate）
     *
     * @throws BceServiceException 服务端 4xx/5xx 结构化错误（requestId/errorCode/statusCode 取自响应）
     * @throws BceClientException 本地/网络错误
     */
    public function request(string $method, string $path, ?array $body = null, array $params = []): stdClass
    {
        $encodedBody = $body === null ? null : json_encode($body);
        $contentLength = $encodedBody === null ? 0 : strlen($encodedBody);

        $now = new DateTime;
        $now->setTimezone(DateUtils::$UTC_TIMEZONE);

        // 参与 BCE V1 签名的 header：默认签 host / content-length / content-type / content-md5 + 所有 x-bce-* 前缀
        // （见 BceV1Signer::isDefaultHeaderToSign）。这些 header 必须与实际发送的一致，否则服务端验签失败。
        $headers = [
            HttpHeaders::HOST => $this->endpoint,
            HttpHeaders::CONTENT_TYPE => HttpContentTypes::JSON,
            HttpHeaders::CONTENT_LENGTH => (string) $contentLength,
            HttpHeaders::BCE_DATE => DateUtils::formatAlternateIso8601Date($now),
        ];
        $headers[HttpHeaders::AUTHORIZATION] = $this->signer->sign(
            $this->credentials,
            $method,
            $path,
            $headers,
            $params,
        );

        // URL：path 走 BCE 编码（urlEncodeExceptSlash）、query 走 canonical 排序，与签名口径一致。
        $url = 'https://'.$this->endpoint.HttpUtils::urlEncodeExceptSlash($path);
        $queryString = HttpUtils::getCanonicalQueryString($params, false);
        if ($queryString !== '') {
            $url .= "?$queryString";
        }

        try {
            // 显式传 body（空体传 ''，确保发出 Content-Length: 0 与签名一致）；headers 含我们签过的全部 header。
            $response = $this->http->request($method, $url, [
                'headers' => $headers,
                'body' => $encodedBody ?? '',
            ]);
        } catch (Throwable $e) {
            // 网络/传输层错误：包装为 BceClientException（BaiduErrorSanitizer 仅按类名脱敏，不回传 message）。
            throw new BceClientException($e->getMessage());
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        // 非 2xx：解析 BCE 结构化错误体（code/message/requestId），抛 BceServiceException（与原 BceHttpClient 一致）。
        if ($status < 200 || $status >= 300) {
            $requestId = $response->getHeaderLine(HttpHeaders::BCE_REQUEST_ID);
            $message = $response->getReasonPhrase();
            $code = null;
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $message = $decoded['message'] ?? $message;
                $code = $decoded['code'] ?? null;
            }
            throw new BceServiceException($requestId, $code, $message, (string) $status);
        }

        if ($raw === '') {
            return new stdClass;
        }
        $decoded = json_decode($raw);

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }
}

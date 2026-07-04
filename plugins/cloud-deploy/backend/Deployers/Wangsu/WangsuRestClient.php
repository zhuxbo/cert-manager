<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * 网宿云（ChinaNetCenter / Wangsu）REST 薄客户端（证书中心 / CDN / CDN Pro）。
 *
 * 网宿云无官方模块化 PHP SDK（与 certimate 用的自写 Go SDK 不同）。本类对齐 certimate
 * pkg/sdk3rd/wangsu 的线协议 + **CNC-HMAC-SHA256 签名**，用主系统 GuzzleHttp（来自共享 vendor）
 * 直调 REST，不依赖任何官方云 SDK。所有动作走单一 endpoint `https://open.chinanetcenter.com`。
 *
 * 签名算法（逐字节对齐 certimate pkg/sdk3rd/wangsu/zz-shared-common/signer.go）：
 *   canonicalHeaders = "content-type:{lower-trim}\nhost:{lower-trim}\n"
 *   signedHeaders    = "content-type;host"
 *   payloadHash      = sha256(body)            // GET 时 body 为空串
 *   queryStr         = URL 解码后的 RawQuery   // 仅 **非 POST** 计入；POST 恒为空串
 *   canonicalRequest = method\npath\nqueryStr\ncanonicalHeaders\nsignedHeaders\npayloadHash
 *   stringToSign     = "CNC-HMAC-SHA256"\n{timestamp}\n{sha256(canonicalRequest)}
 *   signature        = lower(hex(HMAC-SHA256(secretKey, stringToSign)))
 *   Authorization    = "CNC-HMAC-SHA256 Credential={ak}, SignedHeaders=content-type;host, Signature={sig}"
 * 另置头：Date(RFC1123 GMT) / X-CNC-Auth-Method=AKSK / X-CNC-AccessKey={ak} / X-CNC-Timestamp={unix}。
 *
 * 关键细节：
 *   - 部分接口（证书中心 CreateCertificate、CDN Pro CreateCertificate/CreateDeploymentTask）从响应的
 *     **Location 响应头** 解析云端资源 id（响应体不含 id），故 request() 同时返回 body(JSON) 与 location。
 *   - CDN Pro 创建/更新证书时网宿要求 **X-CNC-Timestamp 与签名时间戳一致**（certimate 显式 SetHeader），
 *     本类用同一 $timestamp 既签名又置头，二者天然一致。
 *
 * 脱敏说明：签名写入 Authorization 请求头（非 URL 查询串、非 body），故任何 host/URL/body 都不含
 * AK/SK/apiKey，签名值也不进异常。错误归一为 WangsuApiException（仅响应体来源的 code+message），
 * 交 WangsuErrorSanitizer。底层网络异常（GuzzleException）由调用方 catch 后交 sanitizer 只暴露类名。
 *
 * 此类是各 deployer 的 makeClient($kind) 唯一产物 —— 测试 override makeClient 返回 mock（或注入
 * mock ClientInterface 捕获外发请求验证签名）即覆盖全部 REST 调用，无需打真实 HTTP。
 */
class WangsuRestClient
{
    private const BASE_URL = 'https://open.chinanetcenter.com';

    private const SIGN_ALGORITHM = 'CNC-HMAC-SHA256';

    private ClientInterface $http;

    /**
     * @param  string  $accessKey  网宿 AccessKeyId（作为 Credential / X-CNC-AccessKey 外发）
     * @param  string  $secretKey  网宿 AccessKeySecret（仅参与签名计算，绝不外发）
     * @param  ClientInterface|null  $http  注入用于测试；生产用默认 Guzzle Client（10s 连接 + 30s 总超时）
     */
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        ?ClientInterface $http = null,
    ) {
        // 设 socket 超时上限（默认无限），避免上游慢/挂时 worker 长期阻塞；http_errors=false 自行解析错误体。
        $this->http = $http ?? new Client([
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    // ============================ 证书中心（certificatemanagement） ============================

    /**
     * 新增证书到网宿证书中心。
     * REF: certimate certificate/api_create_certificate.go —— POST /api/certificate
     *   {name, certificate, privateKey, comment} → 证书 id 在响应 Location 头（/api/certificate/{id}）。
     *
     * @return string 云端证书数字 id（解析自 Location 头；空串表示未返回）
     */
    public function createCertificate(string $name, string $certificate, string $privateKey, string $comment = ''): string
    {
        $location = $this->request('POST', '/api/certificate', [
            'name' => $name,
            'certificate' => $certificate,
            'privateKey' => $privateKey,
            'comment' => $comment,
        ])['location'];

        return $this->matchId($location, '#/certificate/([0-9]+)#');
    }

    /**
     * 更新证书中心已有证书内容。
     * REF: certimate certificate/api_update_certificate.go —— PUT /api/certificate/{id}
     *   {name, certificate, privateKey, comment}。
     */
    public function updateCertificate(string $certificateId, string $name, string $certificate, string $privateKey, string $comment = ''): void
    {
        $this->request('PUT', '/api/certificate/'.rawurlencode($certificateId), [
            'name' => $name,
            'certificate' => $certificate,
            'privateKey' => $privateKey,
            'comment' => $comment,
        ]);
    }

    // ============================ CDN（融合 CDN，证书批量配置） ============================

    /**
     * 批量修改加速域名的证书配置。
     * REF: certimate cdn/api_batch_update_certificate_config.go —— PUT /api/config/certificate/batch
     *   {certificateId(int64), domainNames[]}。
     *
     * @param  list<string>  $domainNames
     */
    public function batchUpdateCertificateConfig(int $certificateId, array $domainNames): void
    {
        $this->request('PUT', '/api/config/certificate/batch', [
            'certificateId' => $certificateId,
            'domainNames' => array_values($domainNames),
        ]);
    }

    // ============================ CDN Pro（证书 + 部署任务） ============================

    /**
     * 查询 CDN Pro 加速域名详情（确认域名存在 / 当前生产环境配置）。
     * REF: certimate cdnpro/api_get_hostname_detail.go —— GET /cdn/hostnames/{hostname}。
     *
     * @return array<string,mixed> 响应体 JSON（含 hostname / propertyInProduction 等）
     */
    public function getCdnProHostnameDetail(string $hostname): array
    {
        return $this->request('GET', '/cdn/hostnames/'.rawurlencode($hostname))['body'];
    }

    /**
     * 创建 CDN Pro 证书（newVersion 内含证书 + AES 加密后的私钥）。
     * REF: certimate cdnpro/api_create_certificate.go —— POST /cdn/certificates
     *   {name, autoRenew, newVersion:{certificate, privateKey(加密)}} + X-CNC-Timestamp 头。
     *   证书 URL（含 id）在响应 Location 头：/cdn/certificates/{objectId}。新建版本恒为 1。
     *
     * @param  array<string,mixed>  $newVersion
     * @return array{certId:string,version:int} 解析自 Location 头的对象 id + 版本号（创建恒 1）
     */
    public function createCdnProCertificate(string $name, array $newVersion, int $timestamp): array
    {
        $location = $this->request('POST', '/cdn/certificates', [
            'name' => $name,
            'autoRenew' => 'Off',
            'newVersion' => $newVersion,
        ], $timestamp)['location'];

        return [
            'certId' => $this->matchId($location, '#/certificates/([a-zA-Z0-9-]+)#'),
            'version' => 1,
        ];
    }

    /**
     * 更新 CDN Pro 证书（追加新版本）。
     * REF: certimate cdnpro/api_update_certificate.go —— PATCH /cdn/certificates/{id}
     *   {name, autoRenew, newVersion:{...}} + X-CNC-Timestamp 头。
     *   返回 Location 含对象 id + 版本号：/cdn/certificates/{id}/versions/{n}。
     *
     * @param  array<string,mixed>  $newVersion
     * @return array{certId:string,version:int} 解析自 Location 头的对象 id + 版本号（缺省回落 1）
     */
    public function updateCdnProCertificate(string $certificateId, string $name, array $newVersion, int $timestamp): array
    {
        $location = $this->request('PATCH', '/cdn/certificates/'.rawurlencode($certificateId), [
            'name' => $name,
            'autoRenew' => 'Off',
            'newVersion' => $newVersion,
        ], $timestamp)['location'];

        $verStr = $this->matchId($location, '#/versions/([0-9]+)#');

        return [
            'certId' => $this->matchId($location, '#/certificates/([a-zA-Z0-9-]+)#'),
            'version' => $verStr === '' ? 1 : (int) $verStr,
        ];
    }

    /**
     * 创建 CDN Pro 部署任务（把证书版本部署到指定环境）。
     * REF: certimate cdnpro/api_create_deployment_task.go —— POST /cdn/deploymentTasks
     *   {name, target(环境), actions:[{action:"deploy_cert", certificateId, version}], webhook?}。
     *   任务 id 在响应 Location 头：/cdn/deploymentTasks/{taskId}。
     *
     * @return string 部署任务 id（解析自 Location 头；空串表示未返回）
     */
    public function createCdnProDeploymentTask(string $name, string $target, string $certificateId, int $version, string $webhookId = ''): string
    {
        $body = [
            'name' => $name,
            'target' => $target,
            'actions' => [[
                'action' => 'deploy_cert',
                'certificateId' => $certificateId,
                'version' => $version,
            ]],
        ];
        if ($webhookId !== '') {
            $body['webhook'] = $webhookId;
        }

        $location = $this->request('POST', '/cdn/deploymentTasks', $body)['location'];

        return $this->matchId($location, '#/deploymentTasks/([a-zA-Z0-9-]+)#');
    }

    /**
     * 查询 CDN Pro 部署任务详情（轮询任务状态）。
     * REF: certimate cdnpro/api_get_deployment_task_detail.go —— GET /cdn/deploymentTasks/{id}。
     *
     * @return array{status:string,finishTime:string} 任务状态 + 完成时间（缺失回空串）
     */
    public function getCdnProDeploymentTaskDetail(string $deploymentTaskId): array
    {
        $body = $this->request('GET', '/cdn/deploymentTasks/'.rawurlencode($deploymentTaskId))['body'];

        return [
            'status' => is_string($body['status'] ?? null) ? $body['status'] : '',
            'finishTime' => is_string($body['finishTime'] ?? null) ? $body['finishTime'] : '',
        ];
    }

    // ============================ 底层：签名 + 发送 + 错误归一 ============================

    /**
     * 发起一次带 CNC-HMAC-SHA256 签名的网宿 REST 请求。
     *
     * @param  string  $method  HTTP 方法（GET/POST/PUT/PATCH）
     * @param  string  $path  资源路径（如 /api/certificate）
     * @param  array<string,mixed>|null  $body  请求体（非 GET 时 json_encode；GET 传 null）
     * @param  int|null  $timestamp  显式时间戳（CDN Pro 证书接口需签名时间戳与 X-CNC-Timestamp 头一致）
     * @return array{body:array<string,mixed>,location:string} 解析后的响应体 JSON + Location 响应头
     */
    private function request(string $method, string $path, ?array $body = null, ?int $timestamp = null): array
    {
        $method = strtoupper($method);
        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = $timestamp ?? time();

        $headers = $this->sign($method, $path, $payload, $ts);

        $response = $this->http->request($method, self::BASE_URL.$path, [
            RequestOptions::HEADERS => $headers,
            RequestOptions::BODY => $payload,
            RequestOptions::HTTP_ERRORS => false,
        ]);

        return $this->ensureOk($response);
    }

    /**
     * 计算 CNC-HMAC-SHA256 签名头（逐字节对齐 certimate signer.go）。
     *
     * @return array<string,string>
     */
    private function sign(string $method, string $path, string $payload, int $timestamp): array
    {
        $contentType = 'application/json';

        // canonicalHeaders / signedHeaders：固定签 content-type + host（小写、trim）。
        $host = strtolower(trim(parse_url(self::BASE_URL, PHP_URL_HOST) ?: ''));
        $canonicalHeaders = "content-type:$contentType\nhost:$host\n";
        $signedHeaders = 'content-type;host';

        // payloadHash：GET 视为空 body；其余对实际 body 取 sha256。
        $payloadForHash = $method === 'GET' ? '' : $payload;
        $payloadHash = strtolower(hash('sha256', $payloadForHash));

        // queryStr：仅非 POST 计入（本类各 path 均无 query，恒为空串；保留逻辑与 certimate 对齐）。
        $queryStr = '';

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $queryStr,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $canonicalRequestHash = strtolower(hash('sha256', $canonicalRequest));

        $timestampStr = (string) $timestamp;
        $stringToSign = implode("\n", [self::SIGN_ALGORITHM, $timestampStr, $canonicalRequestHash]);
        $signature = strtolower(hash_hmac('sha256', $stringToSign, $this->secretKey));

        $authorization = self::SIGN_ALGORITHM
            ." Credential={$this->accessKey}, SignedHeaders=$signedHeaders, Signature=$signature";

        return [
            'Accept' => 'application/json',
            'Content-Type' => $contentType,
            'Authorization' => $authorization,
            'Date' => gmdate('D, d M Y H:i:s \G\M\T', $timestamp),
            'X-CNC-Auth-Method' => 'AKSK',
            'X-CNC-AccessKey' => $this->accessKey,
            'X-CNC-Timestamp' => $timestampStr,
        ];
    }

    /**
     * HTTP 非 2xx 或 响应体 code≠0 → 抛 WangsuApiException（仅响应体来源的 code + message，无凭证）。
     *
     * @return array{body:array<string,mixed>,location:string}
     */
    private function ensureOk(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : [];
        $location = $response->getHeaderLine('Location');

        // 网宿业务错误：响应体 code 非空且非 "0"（部分接口 HTTP 2xx 但 body.code 体现失败）。
        $bodyCode = $body['code'] ?? null;
        if (is_string($bodyCode) && $bodyCode !== '' && $bodyCode !== '0') {
            $msg = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : '网宿云接口返回错误';
            throw new WangsuApiException($bodyCode, $msg);
        }

        if ($status < 200 || $status >= 300) {
            $msg = is_string($body['message'] ?? null) && $body['message'] !== ''
                ? $body['message']
                : '网宿云接口返回 HTTP '.$status;
            throw new WangsuApiException($status > 0 ? (string) $status : 'WangsuError', $msg);
        }

        return ['body' => $body, 'location' => $location];
    }

    /** 用正则从 Location 头解析资源 id；未命中返回空串（由调用方判空报错）。 */
    private function matchId(string $location, string $pattern): string
    {
        return preg_match($pattern, $location, $m) === 1 ? $m[1] : '';
    }
}

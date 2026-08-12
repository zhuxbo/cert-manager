<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Closure;
use Qiniu\Auth;
use Qiniu\Http\Client;
use Qiniu\Http\Response;
use RuntimeException;

/**
 * 七牛云 REST 薄客户端（SSL 证书 / 融合 CDN / 对象存储 Kodo / 直播 Pili）。
 *
 * php-sdk（qiniu/php-sdk）只有 Auth + Http\Client，无 certimate 自写 SDK 的高层 SslCertManager /
 * CdnManager / KodoManager / pili.Manager。本类用 php-sdk 的 Auth::authorizationV2（= Go SDK
 * SignRequestV2，输出 `Authorization: Qiniu <token>` + `X-Qiniu-Date` 头）+ Http\Client 做 REST，
 * 方法逐一对齐 certimate pkg/sdk3rd/qiniu 与 go-sdk/pili，供 deployer/uploader 调用。
 *
 * 鉴权：Qiniu V2（管理凭证签名），签名串含 method+path+query+Host+Content-Type+body，故签名用的 body
 * 与实际发送 body 必须是**同一字符串**（本类先 json_encode 一次再分别喂签名与发送）。
 *
 * 错误归一：HTTP 非 2xx 或响应体 code 不在成功码 0/200 → 抛 QiniuApiException（携七牛错误码 +
 * 响应体 error 描述，均来自响应体、不含凭证）。curl 本身失败（statusCode<0）→ 同样抛
 * QiniuApiException 但用通用描述（不回传可能含 URL 的 curl_error，由 sanitizer 再兜底）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部
 * REST 调用，无需打 curl。
 */
class QiniuRestClient
{
    private const API_HOST = 'https://api.qiniu.com';

    private const PILI_HOST = 'https://pili.qiniuapi.com';

    /** @param null|Closure(string,string,string,array<string,string>):Response $requester */
    public function __construct(
        private readonly Auth $auth,
        private readonly ?Closure $requester = null,
    ) {}

    /**
     * 上传 SSL 证书到七牛证书中心。
     * REF: certimate sslcert.go UploadSslCert —— POST /sslcert {name, common_name, ca, pri} → {certID}
     *
     * @return string 云端证书 certID
     */
    public function uploadSslCert(string $name, string $commonName, string $certificate, string $privateKey): string
    {
        $resp = $this->put(self::API_HOST.'/sslcert', [
            'name' => $name,
            'common_name' => $commonName,
            'ca' => $certificate,
            'pri' => $privateKey,
        ], 'POST');

        $json = is_array($resp->json()) ? $resp->json() : [];
        $certId = $json['certID'] ?? null;

        return is_string($certId) ? $certId : '';
    }

    /**
     * 查询融合 CDN 域名信息（含 HTTPS 配置）。
     * REF: certimate cdn.go GetDomainInfo —— GET /domain/{domain}
     *
     * @return array{https:?array{certId?:string,forceHttps?:bool,http2Enable?:bool}}
     */
    public function getCdnDomainInfo(string $domain): array
    {
        $resp = $this->get(self::API_HOST.'/domain/'.$domain);
        $json = is_array($resp->json()) ? $resp->json() : [];

        return [
            'https' => is_array($json['https'] ?? null) ? $json['https'] : null,
        ];
    }

    /**
     * 为未启用 HTTPS 的融合 CDN 域名启用 HTTPS 并绑定证书。
     * REF: certimate cdn.go EnableDomainHttps —— PUT /domain/{domain}/sslize {certId, forceHttps, http2Enable}
     */
    public function enableCdnDomainHttps(string $domain, string $certId, bool $forceHttps, bool $http2Enable): void
    {
        $this->put(self::API_HOST.'/domain/'.$domain.'/sslize', [
            'certId' => $certId,
            'forceHttps' => $forceHttps,
            'http2Enable' => $http2Enable,
        ]);
    }

    /**
     * 修改已启用 HTTPS 的融合 CDN 域名证书配置。
     * REF: certimate cdn.go ModifyDomainHttpsConf —— PUT /domain/{domain}/httpsconf {certId, forceHttps, http2Enable}
     */
    public function modifyCdnDomainHttpsConf(string $domain, string $certId, bool $forceHttps, bool $http2Enable): void
    {
        $this->put(self::API_HOST.'/domain/'.$domain.'/httpsconf', [
            'certId' => $certId,
            'forceHttps' => $forceHttps,
            'http2Enable' => $http2Enable,
        ]);
    }

    /**
     * 绑定对象存储（Kodo）自定义域名证书。
     * REF: certimate kodo.go BindBucketCert —— PUT /cert/bind {certid, domain}
     */
    public function bindKodoBucketCert(string $domain, string $certId): void
    {
        $this->put(self::API_HOST.'/cert/bind', [
            'certid' => $certId,
            'domain' => $domain,
        ]);
    }

    /**
     * 修改直播（Pili）域名证书配置。
     * REF: go-sdk/pili domain.go SetDomainCert —— POST /v2/hubs/{hub}/domains/{domain}/cert {certName}
     */
    public function setPiliDomainCert(string $hub, string $domain, string $certName): void
    {
        $this->put(self::PILI_HOST.'/v2/hubs/'.rawurlencode($hub).'/domains/'.rawurlencode($domain).'/cert', [
            'certName' => $certName,
        ], 'POST');
    }

    /**
     * 发起带 JSON body 的写请求（默认 PUT，Pili/上传用 POST），统一签名 + 错误归一。
     *
     * @param  array<string,mixed>  $body
     */
    private function put(string $url, array $body, string $method = 'PUT'): Response
    {
        $payload = $this->encodeJson($body);
        // 签名用的 body 必须与发送 body 同串（Qiniu V2 签名串含 body）
        $headers = $this->auth->authorizationV2($url, $method, $payload, 'application/json');

        return $this->ensureOk($this->request($method, $url, $payload, $headers));
    }

    private function get(string $url): Response
    {
        $headers = $this->auth->authorizationV2($url, 'GET', '', null);

        return $this->ensureOk($this->request('GET', $url, '', $headers));
    }

    /** @param array<string,mixed> $body */
    private function encodeJson(array $body): string
    {
        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,string> $headers */
    private function request(string $method, string $url, string $payload, array $headers): Response
    {
        if ($this->requester !== null) {
            $response = ($this->requester)($method, $url, $payload, $headers);
            if (! $response instanceof Response) {
                throw new RuntimeException('七牛云请求器未返回有效响应');
            }

            return $response;
        }

        return match ($method) {
            'GET' => Client::get($url, $headers),
            'POST' => Client::post($url, $payload, $headers),
            'PUT' => Client::PUT($url, $payload, $headers),
            default => throw new RuntimeException('不支持的七牛云请求方法'),
        };
    }

    /**
     * HTTP 非 2xx 或响应体 code 不在 0/200 → 抛 QiniuApiException（仅响应体来源的 code + 描述，无凭证/URL）。
     */
    private function ensureOk(Response $resp): Response
    {
        $json = is_array($resp->json()) ? $resp->json() : [];

        // 七牛端点成功码不统一：部分返回 code=0，部分返回 code=200。
        $bodyCode = $json['code'] ?? null;
        if (is_int($bodyCode) && ! in_array($bodyCode, [0, 200], true)) {
            $err = is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : '七牛云接口返回错误';
            throw new QiniuApiException((string) $bodyCode, $err);
        }

        if (! $resp->ok()) {
            // 优先用响应体 error（七牛标准错误体字段，安全）；HTTP 状态码作错误码
            $err = is_string($json['error'] ?? null) && $json['error'] !== ''
                ? $json['error']
                : '七牛云接口返回 HTTP '.$resp->statusCode;
            $code = $resp->statusCode > 0 ? (string) $resp->statusCode : 'QiniuError';
            throw new QiniuApiException($code, $err);
        }

        return $resp;
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 华为云 OBS（对象存储）REST 薄客户端（含 OBS V2 签名）。
 *
 * OBS 用**独立**的签名机制（OBS HMAC-SHA1，类 AWS S3 V2），**不同于**其余华为云服务的 SDK-HMAC-SHA256（见 HuaweicloudRestClient）。
 * 故单独成类，逐字节对齐 certimate pkg/sdk3rd/huaweicloud/obs/signer.go：
 *   stringToSign = METHOD\nContent-MD5\nContent-Type\nDate\nCanonicalizedHeaders + CanonicalizedResource
 *     - CanonicalizedHeaders：x-obs-* 头，name 小写 + escapeQuery(value)，按 name 升序，每行 `name:value\n`（本端点无 x-obs-* 头）。
 *     - CanonicalizedResource：escapePath("/{bucket}/{object}") + 子资源查询（键升序，?k 或 k=v）。
 *   signature = base64(HMAC-SHA1(SK, stringToSign))。
 *   Authorization = "OBS {AK}:{signature}"。
 *   Date 头 = RFC1123 GMT（gmdate('D, d M Y H:i:s \G\M\T')）。Content-MD5 = base64(md5(body))。
 *   escapeQuery：url.QueryEscape 后 %7E→~、%2F→/、%20→+（对齐 signer.go）。escapePath：仅保留 A-Za-z0-9-._~/，其余 %XX 大写。
 *
 * 仅用于 OBS 自定义域名绑证书（PutBucketCustomDomain：PUT /?customdomain={domain}，XML body）。
 * host = {bucket}.obs.{region}.myhuaweicloud.com（虚拟主机式，bucket 进 host）。
 *
 * 错误归一：HTTP 非 2xx → 抛 HuaweicloudApiException（OBS 返回 XML 错误体 {Error:{Code,Message}}，尽力解析；
 * 否则用 HTTP 状态码）。Guzzle 失败上抛，由 HuaweicloudErrorSanitizer 兜底。
 *
 * 此类是 OBS deployer 的 makeClient('obs', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 OBS 调用。
 */
class HuaweiObsClient
{
    private Client $http;

    /**
     * @param  string  $bucket  存储桶名（进虚拟主机式 host + CanonicalizedResource）
     * @param  string  $region  区域（构造 host）
     * @param  string  $accessKeyId  华为云 AccessKeyId
     * @param  string  $secretAccessKey  华为云 SecretAccessKey
     */
    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * 绑定自定义域名证书（PutBucketCustomDomain）。
     *
     * 与 certimate 的偏差（已知，刻意修正）：certimate 同时下发 CertificateId（SCM 托管 id）+ 内联 Certificate/PrivateKey；
     * 但插件证书服务型 bind 只拿到 SCM certificate_id（不传 PEM，见 CloudDeployJob 契约）。华为云 OBS 的 CertificateId
     * 引用 SCM 托管证书时，OBS 自动从 SCM 取证书内容，内联 PEM 可省（官方文档行为）。故仅下发 CertificateId + Name，
     * 既符合契约又达成绑定，避免「证书服务型 bind 强行需要 PEM」的架构破坏。
     *
     * @return array<string,mixed>
     */
    public function putBucketCustomDomain(string $customDomain, string $name, string $certificateId): array
    {
        // XML body（对齐 certimate PutBucketCustomDomainRequest 的 xml tag；引用 SCM 托管 id，省内联 PEM）。
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CustomDomainConfiguration>'
            .'<Name>'.self::xmlEscape($name).'</Name>'
            .'<CertificateId>'.self::xmlEscape($certificateId).'</CertificateId>'
            .'</CustomDomainConfiguration>';

        // 子资源：?customdomain={domain}（域名作子资源的值，进 CanonicalizedResource）。
        $subResource = 'customdomain='.rawurlencode($customDomain);

        return $this->send('PUT', '/', $subResource, $xml, 'application/xml');
    }

    /**
     * 发起一次 OBS 签名请求并归一响应/错误。
     *
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, string $subResource, string $body, string $contentType): array
    {
        $method = strtoupper($method);
        $host = $this->host();

        $contentMd5 = base64_encode(md5($body, true));
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        $authorization = $this->sign($method, $contentMd5, $contentType, $date, $subResource);

        $url = 'https://'.$host.$path.($subResource !== '' ? '?'.$subResource : '');
        $options = [
            RequestOptions::HEADERS => [
                'Host' => $host,
                'Date' => $date,
                'Content-MD5' => $contentMd5,
                'Content-Type' => $contentType,
                'Authorization' => $authorization,
            ],
            RequestOptions::BODY => $body,
        ];

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            [$code, $message] = self::parseXmlError($raw, $status);
            throw new HuaweicloudApiException($code, $message);
        }

        // OBS 成功响应体多为空（XML）；统一返回结构化结果。
        return ['status' => $status, 'body' => $raw];
    }

    /**
     * 计算 OBS V2 签名 Authorization 头（逐字节对齐 certimate obs/signer.go）。
     */
    private function sign(string $method, string $contentMd5, string $contentType, string $date, string $subResource): string
    {
        // 本端点无 x-obs-* 头，CanonicalizedHeaders 为空。
        $canonicalizedHeaders = '';

        // CanonicalizedResource = escapePath("/{bucket}/") + ?子资源（bucket 非空、object 空 → "/{bucket}/"）。
        $canonicalizedResource = self::escapePath('/'.$this->bucket.'/');
        if ($subResource !== '') {
            // 子资源已是 escapeQuery(k)=escapeQuery(v) 形态（单个 customdomain）；OBS 把它作 ?k=v 拼接。
            $canonicalizedResource .= '?'.$subResource;
        }

        $stringToSign = implode("\n", [
            $method,
            $contentMd5,
            $contentType,
            $date,
            $canonicalizedHeaders.$canonicalizedResource,
        ]);

        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretAccessKey, true));

        return 'OBS '.$this->accessKeyId.':'.$signature;
    }

    private function host(): string
    {
        return $this->bucket.'.obs.'.$this->region.'.myhuaweicloud.com';
    }

    /**
     * escapePath：仅保留 A-Za-z0-9-._~/，其余字节 %XX 大写（对齐 signer.go escapePath）。
     */
    private static function escapePath(string $path): string
    {
        $out = '';
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            $c = $path[$i];
            if (preg_match('/[A-Za-z0-9\-._~\/]/', $c) === 1) {
                $out .= $c;
            } else {
                $out .= '%'.strtoupper(bin2hex($c));
            }
        }

        return $out;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * 解析 OBS XML 错误体 {Error:{Code,Message}}；失败回落 HTTP 状态码。
     *
     * @return array{0:string,1:string}
     */
    private static function parseXmlError(string $raw, int $status): array
    {
        $code = (string) $status;
        $message = '华为云 OBS 返回 HTTP '.$status;

        if ($raw !== '' && str_contains($raw, '<Error')) {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if ($xml !== false) {
                $xmlCode = (string) ($xml->Code ?? '');
                $xmlMsg = (string) ($xml->Message ?? '');
                if ($xmlCode !== '') {
                    $code = $xmlCode;
                }
                if ($xmlMsg !== '') {
                    $message = $xmlMsg;
                }
            }
        }

        return [$code, $message];
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 火山引擎 CDN 证书上传器（storeKind=volc_cdn）。
 *
 * 火山 CDN 用自有证书空间（非证书中心），对齐 certimate certmgr/volcengine-cdn：
 *   POST /?Action=AddCertificate&Version=2021-03-01
 *   body {Source:"volc_cert_center", Certificate=完整链, PrivateKey, Desc=证书名}
 *   → Result.CertId
 *
 * 删 certimate 端的查重（ListCertInfo + 对比指纹）—— RemoteCertStore 已按 (access_id, store_kind, fingerprint)
 * 去重，上传器只管上传。证书名 Desc 须符合火山命名规则（字母/数字/下划线/连字符），用 clouddeploy_{毫秒}。
 * CDN 为 region-less（签名 region 固定 cn-north-1，与 certimate 一致），故 storeKind 不带 region。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('cdn', …) 提供 VolcRestClient）。
 */
class VolcCdnUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 VolcRestClient（cn-north-1） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'volc_cdn';
    }

    /**
     * @param  array<string,mixed>  $credentials
     * @return string CDN CertId
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $body = [
            'Source' => 'volc_cert_center',
            'Certificate' => $fullChain,
            'PrivateKey' => $keyPem,
            'Desc' => $certName,
        ];

        try {
            /** @var VolcRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->callJson('AddCertificate', '2021-03-01', $body);
        } catch (Throwable $e) {
            throw new RuntimeException(VolcErrorSanitizer::sanitize($e), 0);
        }

        $certId = $result['CertId'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('火山引擎 AddCertificate 未返回 CertId');
        }

        return $certId;
    }
}

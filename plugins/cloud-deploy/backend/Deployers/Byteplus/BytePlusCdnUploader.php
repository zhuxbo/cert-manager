<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * BytePlus CDN 证书上传器（storeKind=byteplus_cdn）。
 *
 * 对齐 certimate byteplus-cdn 的 certmgr Upload：调 CDN OpenAPI
 *   Action=AddCertificate Version=2021-03-01
 *   body {Certificate, PrivateKey, Source:"cert_center", Desc:"<证书名>"}  →  Result.CertId
 * 返回 CertId 作为 remote_cert_id —— cdn bind 时塞进 BatchDeployCert.CertId。
 *
 * 关键：CDN 证书绑定用的 CertId 来自 CDN 体系的 AddCertificate（与证书中心 UploadCertificate 的
 * InstanceId 是**不同标识空间**），故单独 storeKind=byteplus_cdn 隔离去重，不与 byteplus_certcenter 混用。
 *
 * 与 certimate 对齐的取舍：
 * - certimate 上传前先 ListCertInfo 按 sha1/sha256 指纹查重复用；本类省略（RemoteCertStore 已按
 *   (access_id, store_kind, fingerprint) 去重）。
 * - Source 固定 "cert_center"（与 certimate AddCertificateRequest 一致）。Desc 为证书名（clouddeploy_{毫秒}）。
 * - Certificate 传完整链（cert+chain），PrivateKey 传私钥。
 *
 * 签名 service / region 由 deployer 的 makeClient('cdn', …) 注入（service "CDN" 大写、region ap-singapore-1，
 * 对齐 byteplus-sdk-golang service/cdn/config.go）。
 */
class BytePlusCdnUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 BytePlusRestClient（或测试 mock，需有 openApi() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'byteplus_cdn';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var BytePlusRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->openApi('POST', 'AddCertificate', '2021-03-01', [], [
                'Certificate' => trim($fullChain),
                'PrivateKey' => trim($keyPem),
                'Source' => 'cert_center',
                'Desc' => $certName,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(BytePlusErrorSanitizer::sanitize($e), 0);
        }

        $certId = $result->CertId ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('BytePlus CDN AddCertificate 未返回 CertId');
        }

        return $certId;
    }
}

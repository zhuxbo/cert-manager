<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 天翼云证书管理服务 CMS 上传器（storeKind=ctcccloud_cms）。
 *
 * 对齐 certimate certmgr ctcccloud-cms 的 Upload：先把证书拆成服务器证书 + 中间证书，调天翼云 CMS REST
 *   POST /v1/certificate/upload {name, certificate=服务器证书, certificateChain=中间证书, privateKey, encryptionStandard:"INTERNATIONAL"}
 *
 * 关键差异：CMS UploadCertificate **响应不返回证书 id**（certimate 上传后再调 GetCertificateList 按 CN/SAN/有效期/
 * SHA1 指纹反查 id）。但本插件 cms 端点是**纯上传**（CtcccloudCmsDeployer::bind 为 no-op），返回的 remote_cert_id
 * 仅入 cloud_deploy_remote_certs 去重表 + 日志、**从不用于绑定**，故直接返回本地生成的 certName 作为标识，省去反查
 * （省一次列表调用 + 不依赖反查匹配逻辑）。
 *
 * 与 certimate 对齐的取舍：省略上传前/后的 GetCertificateList 查重/反查 —— RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重。
 *
 * 证书拆分（服务器证书 vs 中间证书）：用插件内 cert / chain 边界（cert=叶子证书，chain=中间证书），对齐 certimate
 * ExtractCertificatesFromPEM 的「服务器证书 + 颁发者证书」拆分。
 */
class CtcccloudCmsUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 CtcccloudRestClient（绑定 cms endpoint） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'ctcccloud_cms';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @return string remote_cert_id = 本地生成的证书名（CMS 不回传 id，纯上传端点不需要真实 id）
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // CMS 分别接收服务器证书与中间证书（不像 CDN 系拼完整链）。
        $serverCert = trim($certPem);
        $issuerChain = trim($chainPem);
        // 天翼云 CMS 证书命名：对齐 certimate cm{unix}。
        $certName = 'cm'.time();

        try {
            /** @var CtcccloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $client->post('/v1/certificate/upload', [
                'name' => $certName,
                'certificate' => $serverCert,
                'certificateChain' => $issuerChain,
                'privateKey' => trim($keyPem),
                'encryptionStandard' => 'INTERNATIONAL',
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(CtcccloudErrorSanitizer::sanitize($e), 0);
        }

        return $certName;
    }
}

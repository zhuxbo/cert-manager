<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 优刻得 USSL 证书服务上传器（storeKind=ucloud_ussl）。
 *
 * 对齐 certimate ucloud-ussl Upload：调 USSL 证书服务
 *   Action=UploadNormalCertificate
 *     {CertificateName, SslPublicKey=base64(完整链), SslPrivateKey=base64(私钥),
 *      SslMD5=md5(base64(完整链) + base64(私钥))}
 *   → CertificateID(数字)
 *
 * 关键细节（逐字节对齐 certmgr ucloud_ussl.go）：
 * - 证书与私钥先各自 base64 编码，再外发（与内联型 uewaf 一致）。
 * - SslMD5 = md5_hex( base64(cert) 串 拼接 base64(key) 串 )，**先拼后 md5**（非分别 md5）。
 * - certName 用 clouddeploy_{毫秒}（符合 UCloud 命名规则：字母/数字/下划线）。
 * - certimate 上传前先 GetCertificateList 查重复用；本类省略——插件 RemoteCertStore 已按
 *   (access_id, store_kind, fingerprint) 去重，上传器只管上传（RetCode 80035「已存在」交由上层处理）。
 *
 * 返回值约定：USSL CertificateID 是数字，ucdn/us3 绑定还需 certName，故返回复合串 "{certId}|{certName}"
 * （仿 QiniuSslUploader）；各 deployer 经 ParsesUcloudCertRef 拆出所需的一端。
 *
 * SDK client（UcloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient('api', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class UcloudUsslUploader implements CertUploaderInterface
{
    use ParsesUcloudCertRef;

    /** @param Closure(array<string,mixed>):object $clientFactory 返回 UcloudRestClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'ucloud_ussl';
    }

    /**
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @return string 复合 remote_cert_id："{certId}|{certName}"
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与 Qiniu/AliyunCas 上传器一致）。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certB64 = base64_encode($fullChain);
        $keyB64 = base64_encode(trim($keyPem)."\n");
        // 与 certmgr ucloud_ussl.go 一致：先拼两段 base64 串，再整体 md5（hex 小写）。
        $md5 = md5($certB64.$keyB64);
        // UCloud 证书命名规则：字母/数字/下划线；用毫秒时间戳保唯一。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var UcloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $certId = $client->uploadNormalCertificate($certName, $certB64, $keyB64, $md5);
        } catch (Throwable $e) {
            throw new RuntimeException(UcloudErrorSanitizer::sanitize($e), 0);
        }

        if ($certId <= 0) {
            throw new RuntimeException('优刻得 UploadNormalCertificate 未返回 CertificateID');
        }

        return $this->buildCertRef((string) $certId, $certName);
    }
}

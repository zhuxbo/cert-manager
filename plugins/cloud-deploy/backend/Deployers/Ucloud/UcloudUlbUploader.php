<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 优刻得 ULB 服务证书上传器（storeKind=ucloud_ulb:{region}，region 维度隔离）。
 *
 * ULB（应用型 ALB / 传统型 CLB）使用**独立于 USSL** 的证书空间（CreateSSL→SSLId 字符串），且是
 * 区域型服务（同一证书在不同 region 是不同 SSLId），故 storeKind 编码 region 以隔离标识空间
 * （仿 AliyunSlbUploader storeKind=slb:{region}）。
 *
 * 对齐 certimate ucloud-ulb Upload：
 *   Action=CreateSSL {SSLName, SSLType=Pem, UserCert=服务器证书, CaCert=中间证书, PrivateKey=私钥} → SSLId
 * 与 USSL 不同：ULB 收**未 base64** 的 PEM，且服务器证书与中间证书分列（UserCert / CaCert）。
 * certimate 用 ExtractCertificatesFromPEM 拆服务器证书 + 中间证书；插件的 bind/upload 实参已把服务器
 * 证书（certPem）与中间证书（chainPem）分开传入，直接对应 UserCert / CaCert。
 *
 * certimate 上传前先 DescribeSSL 查重复用；本类省略——插件 RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重。
 */
class UcloudUlbUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 UcloudRestClient（已绑定 region）
     * @param  string  $region  地域（编码进 storeKind 隔离跨 region 标识空间）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $region,
    ) {}

    public function storeKind(): string
    {
        return 'ucloud_ulb:'.$this->region;
    }

    /**
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @return string 云端 SSLId
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $sslName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var UcloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $sslId = $client->createUlbSSL($sslName, trim($certPem), trim($chainPem), trim($keyPem));
        } catch (Throwable $e) {
            throw new RuntimeException(UcloudErrorSanitizer::sanitize($e), 0);
        }

        if ($sslId === '') {
            throw new RuntimeException('优刻得 CreateSSL 未返回 SSLId');
        }

        return $sslId;
    }
}

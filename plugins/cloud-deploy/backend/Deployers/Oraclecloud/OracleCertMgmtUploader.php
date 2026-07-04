<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * Oracle Cloud Certificates Management 证书上传器（storeKind="oci_certmgmt:{compartment}"）。
 *
 * 对齐 certimate oraclecloud-certificatesmgmt certmgr：拆 leaf + 中间证书，CreateCertificate（IMPORTED
 * config）→ 证书 OCID。删 certimate 上传前的 ListCertificates 查重（RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重），上传器只管导入。
 *
 * 关于 storeKind 与 region：RemoteCertStore::ensure 在 upload() **之前**调 storeKind() 做去重，而凭证
 * （含 region / tenancy / 私钥）只在 upload() 才到达，故 storeKind 只能用 config 已知的 compartment 维度。
 * 这已足够隔离 —— 去重键含 access_id，而一个 access = 一套 OCI API Key = 固定 tenancy/region，region 对
 * 同一 access 恒定，无需再进 store_kind。compartment 进 store_kind 以隔离同账号跨区间。
 *
 * 鉴权：upload 时从凭证取 region + API Key，经 $signerFactory(credentials) 构造 OCI 签名器
 * （keyId={tenancy}/{user}/{fingerprint}，私钥本地 RSA-SHA256 签名），$clientFactory(signer, region) 构造
 * REST client。两工厂经注入缝（测试可 mock）。私钥仅本地签名用，绝不进 remote_cert_id 或错误文案。
 */
class OracleCertMgmtUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):OciRequestSigner  $signerFactory  入参凭证，返回 OCI 签名器
     * @param  Closure(OciRequestSigner,string):object  $clientFactory  入参 (签名器, region)，返回 OraclecloudClient
     * @param  string  $compartmentOcid  OCI 区间 OCID（证书归属区间，来自 config）
     */
    public function __construct(
        private readonly Closure $signerFactory,
        private readonly Closure $clientFactory,
        private readonly string $compartmentOcid,
    ) {}

    public function storeKind(): string
    {
        return 'oci_certmgmt:'.$this->compartmentOcid;
    }

    /**
     * @param  array{tenancy_ocid:string,user_ocid:string,fingerprint:string,private_key:string,private_key_passphrase?:string,region:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        if ($this->compartmentOcid === '') {
            throw new RuntimeException('Oracle Cloud 缺少区间 OCID（compartment_ocid）');
        }
        $region = isset($credentials['region']) ? (string) $credentials['region'] : '';
        if ($region === '') {
            throw new RuntimeException('Oracle Cloud 缺少区域（region）');
        }

        // OCI 命名规则：字母开头、字母数字下划线连字符；毫秒时间戳保唯一
        $certName = 'clouddeploy-'.(int) (microtime(true) * 1000);
        $serverCertPem = rtrim($certPem)."\n";
        $chain = trim($chainPem);

        try {
            $signer = ($this->signerFactory)($credentials);

            /** @var OraclecloudClient $client */
            $client = ($this->clientFactory)($signer, $region);
            $certOcid = $client->createImportedCertificate(
                $this->compartmentOcid,
                $certName,
                $serverCertPem,
                $chain,
                $keyPem,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(OraclecloudErrorSanitizer::sanitize($e), 0);
        }

        if ($certOcid === '') {
            throw new RuntimeException('Oracle Cloud CreateCertificate 未返回证书 OCID');
        }

        return $certOcid;
    }
}

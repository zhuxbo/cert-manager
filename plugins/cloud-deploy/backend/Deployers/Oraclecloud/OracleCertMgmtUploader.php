<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * Oracle Cloud Certificates Management 证书上传器。
 *
 * 对齐 certimate oraclecloud-certificatesmgmt certmgr：拆 leaf + 中间证书，CreateCertificate（IMPORTED
 * config）→ 证书 OCID。删 certimate 上传前的 ListCertificates 查重（RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重），上传器只管导入。
 *
 * 生产 Job 通过 PreparesCertUploaderForJob 预先解析并注入原子 AuthMaterial。prepared storeKind
 * 使用认证方式以及 resolved region + compartment 的稳定摘要隔离标识空间。注册表等只读
 * 元信息检查使用明确的 unprepared namespace，不承诺 region 隔离，且不可用于实际上传。
 * 两种标识均不包含 token、RPST 或私钥。
 */
class OracleCertMgmtUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(OciRequestSigner,string):object  $clientFactory  入参 (签名器, region)，返回 OraclecloudClient
     * @param  string  $compartmentOcid  OCI 区间 OCID（证书归属区间，来自 config）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $compartmentOcid,
        private readonly string $authMethod,
        private readonly ?OraclecloudAuthMaterial $authMaterial,
    ) {}

    public function storeKind(): string
    {
        if ($this->authMaterial === null) {
            // 只供 Registry 等元信息检查；u=unprepared，不表示已解析 region/认证模式。
            return 'oci:u:'.substr(hash('sha256', $this->compartmentOcid), 0, 24);
        }
        if ($this->authMaterial->region() === '') {
            throw new RuntimeException('Oracle Cloud 上传器尚未解析认证材料');
        }

        $mode = match ($this->authMethod) {
            'apikey' => 'a',
            'instanceprincipal' => 'i',
            'resourceprincipal' => 'r',
            default => throw new RuntimeException('Oracle Cloud 认证方式无效'),
        };
        $namespace = hash('sha256', $this->authMaterial->region()."\0".$this->compartmentOcid);

        // DB store_kind 上限 32；96-bit 摘要覆盖完整 region + compartment，且不暴露认证秘密。
        return 'oci:'.$mode.':'.substr($namespace, 0, 24);
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        if ($this->compartmentOcid === '') {
            throw new RuntimeException('Oracle Cloud 缺少区间 OCID（compartment_ocid）');
        }
        // OCI 命名规则：字母开头、字母数字下划线连字符；毫秒时间戳保唯一
        $certName = 'clouddeploy-'.(int) (microtime(true) * 1000);
        $serverCertPem = rtrim($certPem)."\n";
        $chain = trim($chainPem);

        try {
            $material = $this->authMaterial;
            if ($material === null) {
                throw new RuntimeException('Oracle Cloud 上传器尚未解析认证材料');
            }
            $region = $material->region();
            if ($region === '') {
                throw new RuntimeException('Oracle Cloud 缺少区域（region）');
            }
            $signer = OciRequestSigner::fromAuthMaterial($material);

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

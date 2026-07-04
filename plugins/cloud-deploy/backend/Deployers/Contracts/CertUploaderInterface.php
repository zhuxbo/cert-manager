<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 证书上传职责单一入口（取代旧 DeployerInterface::uploadCert）。
 * 证书服务型端点（CAS/SLB/腾讯 SSL）的 deployer 通过 certUploader() 暴露一个实现，
 * 由 RemoteCertStore::ensure 调用、按 (access_id, storeKind, fingerprint) 去重。
 */
interface CertUploaderInterface
{
    /**
     * 上传证书到云证书服务，返回云端证书 id。
     *
     * @param  array<string,mixed>  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string;

    /**
     * 证书存储空间标识，进 RemoteCertStore 去重键以隔离不同标识空间。
     * 'cas'|'slb'|'tencent_ssl' —— CAS CertId 与 SLB ServerCertificateId 是不同空间，不可混用同 fingerprint 去重。
     */
    public function storeKind(): string;
}

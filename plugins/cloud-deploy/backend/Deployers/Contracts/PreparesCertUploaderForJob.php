<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 需要在 RemoteCertStore 查重前解析运行时凭证的证书上传器。
 *
 * principal / workload identity 等凭证会动态决定云端标识空间；实现必须把单次 Job
 * 解析出的非秘密 namespace 固化进 uploader，确保 storeKind() 与 upload() 使用同一快照。
 */
interface PreparesCertUploaderForJob
{
    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $credentials
     */
    public function certUploaderForJob(array $config, array $credentials): CertUploaderInterface;
}

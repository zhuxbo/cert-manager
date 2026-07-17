<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * Oracle Cloud Certificates Management（仅导入，证书服务型）。
 *
 * 对齐 certimate oraclecloud-certificatesmgmt：Deploy 把证书导入 OCI 证书管理服务（拿证书 OCID），
 * **不绑定 Load Balancer 等资源**（后续在控制台/其他流程引用证书 OCID）。
 *
 * 插件模型：usesRemoteCertStore=true + OracleCertMgmtUploader（store_kind="oci_certmgmt:{compartment}"），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader($config)) 完成。
 *
 * 鉴权：OCI HTTP Signatures（RSA-SHA256，keyId={tenancy}/{user}/{fingerprint}，私钥本地签名），
 * 纯 GuzzleHttp REST 调 certificatesmanagement.{region}.oci.oraclecloud.com（见 OciRequestSigner /
 * OraclecloudClient）。仅支持 API Key 认证方式（certimate 另有 instanceprincipal/resourceprincipal，
 * 依赖实例元数据/SDK，纯 REST 移植不可靠实现，故不支持）。
 *
 * config：compartment_ocid（必填）。region + API Key 走凭证。
 */
class OraclecloudCertificatesMgmtDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    public function provider(): string
    {
        return 'oraclecloud';
    }

    public function product(): string
    {
        return 'certificatesmgmt';
    }

    public function label(): string
    {
        return 'Oracle Cloud 证书管理（仅导入）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'compartment_ocid', 'label' => '区间 OCID（Compartment OCID）', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $compartmentOcid = isset($config['compartment_ocid']) ? (string) $config['compartment_ocid'] : '';

        return new OracleCertMgmtUploader(
            fn (array $credentials): OciRequestSigner => $this->makeClient('signer', $credentials),
            fn (OciRequestSigner $signer, string $region): object => $this->makeClient('api', [], $signer, $region),
            $compartmentOcid,
        );
    }

    /**
     * 纯导入端点：导入已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（证书 OCID，本端点不使用）
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已导入 OCI 证书管理服务，无资源绑定。
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, ?OciRequestSigner $signer = null, string $region = ''): object
    {
        return match ($kind) {
            'signer' => new OciRequestSigner(
                (string) ($credentials['tenancy_ocid'] ?? ''),
                (string) ($credentials['user_ocid'] ?? ''),
                (string) ($credentials['fingerprint'] ?? ''),
                (string) ($credentials['private_key'] ?? ''),
                (string) ($credentials['private_key_passphrase'] ?? ''),
            ),
            'api' => new OraclecloudClient($signer ?? new OciRequestSigner('', '', '', ''), $region),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return OraclecloudErrorSanitizer::sanitize($e);
    }
}

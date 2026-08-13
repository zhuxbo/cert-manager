<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\PreparesCertUploaderForJob;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Plugins\CloudDeploy\Support\CloudMetadataHttpClient;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Throwable;

/**
 * Oracle Cloud Certificates Management（仅导入，证书服务型）。
 *
 * 对齐 certimate oraclecloud-certificatesmgmt：Deploy 把证书导入 OCI 证书管理服务（拿证书 OCID），
 * **不绑定 Load Balancer 等资源**（后续在控制台/其他流程引用证书 OCID）。
 *
 * 插件模型：usesRemoteCertStore=true + OracleCertMgmtUploader（认证模式/region/compartment 隔离），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploaderForJob(...)) 完成。
 *
 * 鉴权支持 API Key、OCI Compute instance principal 与 resource principal 2.2。
 *
 * config：compartment_ocid（必填）。region + API Key 走凭证。
 */
class OraclecloudCertificatesMgmtDeployer extends AbstractDeployer implements PreparesCertUploaderForJob, UploadOnlyDeployerInterface
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
            fn (OciRequestSigner $signer, string $region): object => $this->makeClient('api', [], $signer, $region),
            $compartmentOcid,
            'apikey',
            null,
        );
    }

    public function certUploaderForJob(array $config, array $credentials): CertUploaderInterface
    {
        $compartmentOcid = isset($config['compartment_ocid']) ? (string) $config['compartment_ocid'] : '';
        $authMethod = (string) ($credentials['auth_method'] ?? 'apikey');
        $provider = $this->makeClient('provider', $credentials);
        if (! $provider instanceof OraclecloudCredentialProvider) {
            throw new \RuntimeException('Oracle Cloud 凭证提供器无效');
        }
        $material = $provider->resolve();
        if ($material->region() === '') {
            throw new \RuntimeException('Oracle Cloud 缺少区域（region）');
        }

        return new OracleCertMgmtUploader(
            fn (OciRequestSigner $signer, string $region): object => $this->makeClient('api', [], $signer, $region),
            $compartmentOcid,
            $authMethod,
            $material,
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
            'provider' => $this->newCredentialProvider($credentials),
            'signer' => new OciRequestSigner(
                (string) ($credentials['tenancy_ocid'] ?? ''),
                (string) ($credentials['user_ocid'] ?? ''),
                (string) ($credentials['fingerprint'] ?? ''),
                (string) ($credentials['private_key'] ?? ''),
                (string) ($credentials['private_key_passphrase'] ?? ''),
            ),
            // region 是租户可控字段，可经 :port/ 注入突破 DNS 后缀直连内网（反模式 18）
            'api' => $this->newOracleApiClient($signer ?? new OciRequestSigner('', '', '', ''), $region),
            default => throw new \InvalidArgumentException('不支持的 Oracle Cloud client 类型'),
        };
    }

    /** @param array<string,mixed> $credentials */
    private function newCredentialProvider(array $credentials): OraclecloudCredentialProvider
    {
        return match ((string) ($credentials['auth_method'] ?? 'apikey')) {
            'apikey' => new OracleApiKeyCredentialProvider($credentials),
            'instanceprincipal' => new OracleInstancePrincipalProvider(CloudMetadataHttpClient::forOracle()),
            'resourceprincipal' => new OracleResourcePrincipalProvider,
            default => throw new \InvalidArgumentException('不支持的 Oracle Cloud 认证方式'),
        };
    }

    private function newOracleApiClient(OciRequestSigner $signer, string $region): OraclecloudClient
    {
        app(OutboundDestinationPolicy::class)->authorizeOfficialHost(
            $this->provider(),
            'certificatesmanagement.'.$region.'.oci.oraclecloud.com',
        );

        return new OraclecloudClient($signer, $region);
    }

    protected function sanitize(Throwable $e): string
    {
        return OraclecloudErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * Azure Key Vault（仅导入，证书服务型）。
 *
 * 对齐 certimate azure-keyvault 的「新建证书」分支：Deploy 把 PEM 证书转 PKCS12 后导入 Key Vault
 * （拿证书标识 kid），**不绑定 CDN / Front Door 等资源**（后续在控制台/其他流程关联 Key Vault 证书）。
 * certificate_name 留空时创建新证书；指定时向同名证书导入新版本，实现 Certimate 的原地替换语义。
 *
 * 插件模型：usesRemoteCertStore=true + AzureKeyVaultUploader（store_kind="azure_keyvault:{vault}"），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader($config)) 完成。
 *
 * 鉴权：Azure AD OAuth2 client_credentials（service principal 换 access_token，scope vault/.default），
 * 再 Bearer 调 Key Vault REST 导入证书。仅用 GuzzleHttp + PHP openssl_pkcs12_export（见 AzureKeyVaultUploader）。
 *
 * config：vault_name（必填）/ certificate_name（选填）。cloud_name 走凭证。
 */
class AzureKeyVaultDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    public function provider(): string
    {
        return 'azure';
    }

    public function product(): string
    {
        return 'keyvault';
    }

    public function label(): string
    {
        return 'Azure Key Vault（仅导入）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'vault_name', 'label' => 'Key Vault 名称', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_name', 'label' => '证书名称（选填，指定时原地替换）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $vaultName = isset($config['vault_name']) ? (string) $config['vault_name'] : '';
        $certificateName = isset($config['certificate_name']) ? (string) $config['certificate_name'] : '';

        return new AzureKeyVaultUploader(
            fn (): AzureOAuth2 => $this->makeClient('oauth', []),
            fn (string $token, string $vaultBaseUrl): object => $this->makeClient('api', [], $token, $vaultBaseUrl),
            $vaultName,
            $certificateName,
        );
    }

    /**
     * 纯导入端点：导入已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（Key Vault 证书 kid，本端点不使用）
     * @param  array{tenant_id:string,client_id:string,client_secret:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已导入 Key Vault，无资源绑定。
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $token = '', string $vaultBaseUrl = ''): object
    {
        return match ($kind) {
            'oauth' => new AzureOAuth2,
            'api' => new AzureKeyVaultClient($token, $vaultBaseUrl),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AzureErrorSanitizer::sanitize($e);
    }
}

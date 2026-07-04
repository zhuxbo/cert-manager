<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * Azure Key Vault 证书上传器（storeKind="azure_keyvault:{vault}"）。
 *
 * 对齐 certimate azure-keyvault certmgr：把 PEM 证书转 PKCS12 后 ImportCertificate 到 Key Vault →
 * 证书标识 id（kid）。删 certimate 上传前的 GetCertificates 查重（RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重），上传器只管转换 + 导入。
 *
 * PEM→PKCS12：Azure Key Vault 不支持导入带链 PEM（Azure/azure-cli#19017），必须转 PFX。用 PHP 原生
 * openssl_pkcs12_export（无需外部二进制），把 leaf + 私钥 + 中间证书链（extracerts）打成空口令 PKCS12。
 *
 * storeKind 含 vault 名 —— 同账号导入到不同 Key Vault 各落一行、各持本 vault 的 kid，避免跨 vault 误复用。
 *
 * 鉴权：经 $oauthFactory 取 OAuth2 helper（client_credentials 换 token），再用 $clientFactory(token, vaultBaseUrl)
 * 构造带 Bearer 的 REST client。vaultBaseUrl 按主权云环境 + vault 名派生。两工厂经注入缝（测试可 mock）。
 * clientSecret/token/私钥仅本地用，绝不进 remote_cert_id 或错误文案。
 */
class AzureKeyVaultUploader implements CertUploaderInterface
{
    /**
     * @param  Closure():AzureOAuth2  $oauthFactory  返回 OAuth2 helper（client_credentials 换 token）
     * @param  Closure(string,string):object  $clientFactory  入参 (access_token, vaultBaseUrl)，返回 AzureKeyVaultClient
     * @param  string  $vaultName  Key Vault 名称
     * @param  string  $cloudName  主权云环境（决定登录端点/scope/DNS 后缀）
     */
    public function __construct(
        private readonly Closure $oauthFactory,
        private readonly Closure $clientFactory,
        private readonly string $vaultName,
        private readonly string $cloudName,
    ) {}

    public function storeKind(): string
    {
        return 'azure_keyvault:'.$this->vaultName;
    }

    /**
     * @param  array{tenant_id:string,client_id:string,client_secret:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        if ($this->vaultName === '') {
            throw new RuntimeException('Azure 缺少 Key Vault 名称（vault_name）');
        }

        $pkcs12 = $this->toPkcs12($certPem, $keyPem, $chainPem);
        [$certCN, $certSN] = $this->certIdentity($certPem);
        $certName = 'clouddeploy-'.(int) (microtime(true) * 1000);

        $env = AzureCloudEnv::resolve($this->cloudName);
        $vaultBaseUrl = 'https://'.$this->vaultName.'.'.$env['vaultDnsSuffix'];

        try {
            $oauth = ($this->oauthFactory)();
            $token = $oauth->fetchAccessToken(
                (string) ($credentials['tenant_id'] ?? ''),
                (string) ($credentials['client_id'] ?? ''),
                (string) ($credentials['client_secret'] ?? ''),
                $this->cloudName,
            );

            /** @var AzureKeyVaultClient $client */
            $client = ($this->clientFactory)($token, $vaultBaseUrl);
            $kid = $client->importCertificate($certName, base64_encode($pkcs12), array_filter([
                'clouddeploy/cert-cn' => $certCN,
                'clouddeploy/cert-sn' => $certSN,
            ], fn (string $v) => $v !== ''));
        } catch (Throwable $e) {
            throw new RuntimeException(AzureErrorSanitizer::sanitize($e), 0);
        }

        if ($kid === '') {
            throw new RuntimeException('Azure ImportCertificate 未返回证书标识 id');
        }

        return $kid;
    }

    /**
     * PEM → PKCS12（空口令）。leaf + 私钥 + 中间证书链（extracerts）。
     * 用 PHP 原生 openssl_pkcs12_export，不依赖外部二进制。
     */
    private function toPkcs12(string $certPem, string $keyPem, string $chainPem): string
    {
        $cert = openssl_x509_read($certPem);
        if ($cert === false) {
            throw new RuntimeException('Azure 上传失败：服务器证书 PEM 解析失败');
        }

        $privateKey = openssl_pkey_get_private($keyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Azure 上传失败：私钥 PEM 解析失败');
        }

        $extraCerts = $this->splitChain($chainPem);

        $pkcs12 = '';
        $ok = openssl_pkcs12_export($cert, $pkcs12, $privateKey, '', $extraCerts === [] ? [] : ['extracerts' => $extraCerts]);
        if (! $ok || $pkcs12 === '') {
            throw new RuntimeException('Azure 上传失败：PEM 转 PKCS12 失败');
        }

        return $pkcs12;
    }

    /**
     * 拆中间证书链 PEM 为单证书数组（供 PKCS12 extracerts）。
     *
     * @return list<string>
     */
    private function splitChain(string $chainPem): array
    {
        $chainPem = trim($chainPem);
        if ($chainPem === '') {
            return [];
        }

        if (! preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $chainPem, $m)) {
            return [];
        }

        return array_values(array_map('trim', $m[0]));
    }

    /**
     * 取证书 CN + 序列号（十六进制），作 Key Vault tag（对齐 certimate 的 cert-cn / cert-sn tag）。
     *
     * @return array{0:string,1:string}
     */
    private function certIdentity(string $certPem): array
    {
        $parsed = openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return ['', ''];
        }

        $cn = '';
        if (isset($parsed['subject']['CN'])) {
            $cn = is_array($parsed['subject']['CN']) ? (string) ($parsed['subject']['CN'][0] ?? '') : (string) $parsed['subject']['CN'];
        }

        $sn = '';
        if (isset($parsed['serialNumberHex'])) {
            $sn = strtolower((string) $parsed['serialNumberHex']);
        } elseif (isset($parsed['serialNumber'])) {
            $sn = (string) $parsed['serialNumber'];
        }

        return [$cn, $sn];
    }
}

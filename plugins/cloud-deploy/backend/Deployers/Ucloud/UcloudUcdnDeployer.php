<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 优刻得 CDN（UCDN）部署器 —— 证书服务型（usesRemoteCertStore=true，USSL）。
 *
 * 流程对齐 certimate ucloud-ucdn：
 *   1. 证书经 USSL 上传拿 CertificateID（走 RemoteCertStore 去重）。
 *   2. GetUcdnDomainConfig 读域名当前 HTTPS 状态（HttpsStatusCn / HttpsStatusAbroad）。
 *   3. UpdateUcdnDomainHttpsConfigV2 沿用原 HTTPS 状态、绑定新 CertId(数字) + CertName，CertType=ussl。
 *
 * config 用 domain_id（加速域名 ID，非域名字符串；对齐 certimate DomainId）。
 */
class UcloudUcdnDeployer extends AbstractDeployer
{
    use ParsesUcloudCertRef;
    use UcloudClientFactory;

    public function provider(): string
    {
        return 'ucloud';
    }

    public function product(): string
    {
        return 'ucdn';
    }

    public function label(): string
    {
        return '优刻得 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_id', 'label' => '加速域名 ID', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // USSL 上传器复用 deployer 注入缝：测试 override makeClient('api') 即作用于上传。
        return new UcloudUsslUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string  $certRef  复合 remote_cert_id "{certId}|{certName}"（UCDN 同时用 certId + certName）
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @param  array{domain_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domainId = (string) $this->requireConfig($config, 'domain_id');
        [$certIdStr, $certName] = $this->parseCertRef((string) $certRef);
        $certId = (int) $certIdStr;

        // 读域名配置（SDK 调用包 guardSdk，返回结果）。
        $info = $this->guardSdk(fn () => $this->makeClient('api', $credentials)->getUcdnDomainConfig($domainId));

        // 业务判断放 guardSdk 外（fail() 不被 guardSdk 吞成 SDK 错误）。
        if ($info['DomainList'] === []) {
            $this->fail("优刻得 CDN 未找到加速域名 $domainId");
        }
        $domain = $info['DomainList'][0];
        $httpsStatusCn = is_string($domain['HttpsStatusCn'] ?? null) ? $domain['HttpsStatusCn'] : '';
        $httpsStatusAbroad = is_string($domain['HttpsStatusAbroad'] ?? null) ? $domain['HttpsStatusAbroad'] : '';

        // 更新 HTTPS 配置（SDK 调用包 guardSdk）。
        $this->guardSdk(fn () => $this->makeClient('api', $credentials)
            ->updateUcdnDomainHttpsConfigV2($domainId, $httpsStatusCn, $httpsStatusAbroad, $certId, $certName));
    }

    protected function sanitize(Throwable $e): string
    {
        return UcloudErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * BytePlus CDN（证书服务型）：证书先经 CDN AddCertificate 上传拿 CertId（走 RemoteCertStore 去重），
 * 再 BatchDeployCert 关联到加速域名。
 *
 * 对齐 certimate byteplus-cdn：
 *   - 上传：CDN AddCertificate(source=cert_center) → CertId（certmgr byteplus-cdn）。
 *   - 绑定：BatchDeployCert{CertId, Domain}（updateDomainCertificate）。
 *
 * 仅实现 exact domain 核心路径（对齐插件既有 cdn/qiniu/baidu 约定）；不做 certimate 的
 * wildcard（ListCdnDomains 分页匹配）/ certsan（DescribeCertConfig）多域名遍历。
 *
 * 签名 service / region 对齐 byteplus-sdk-golang service/cdn/config.go：
 *   ServiceName = "CDN"（**大写**）、DefaultRegion = "ap-singapore-1"、host open.byteplusapi.com。
 *   大小写必须精确匹配——signing service 写错（如 "cdn"）会令凭证 scope 与网关不一致、签名校验失败。
 */
class BytePlusCdnDeployer extends AbstractDeployer
{
    /** CDN 签名 service（byteplus-sdk-golang ServiceName，**大写**）。 */
    private const CDN_SERVICE = 'CDN';

    /** CDN 签名 region（byteplus-sdk-golang DefaultRegion）。 */
    private const CDN_REGION = 'ap-singapore-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'BytePlus CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new BytePlusCdnUploader(
            fn (array $credentials): object => $this->makeClient('cdn', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（CDN CertId）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            // 关联证书与加速域名：Action=BatchDeployCert Version=2021-03-01 body {CertId, Domain}
            $client->openApi('POST', 'BatchDeployCert', '2021-03-01', [], [
                'CertId' => $certId,
                'Domain' => $domain,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new BytePlusRestClient(
                self::CDN_SERVICE,
                self::CDN_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * BytePlus 视频直播 Media Live（证书服务型）：证书先经直播 CreateCert 上传拿 ChainID（走 RemoteCertStore
 * 去重），再 BindCert 关联到直播流域名。
 *
 * 对齐 certimate byteplus-medialive：
 *   - 上传：直播 CreateCert(Rsa, UseWay=https) → ChainID（certmgr byteplus-medialive）。
 *   - 绑定：BindCert{ChainID, Domain, HTTPS:true}（updateDomainCertificate）。
 *
 * 仅实现 exact domain 核心路径；不做 certimate 的 wildcard（ListDomainDetail 分页匹配）/ certsan 多域名遍历。
 *
 * 签名 service / region 对齐 byteplus-sdk-golang service/live/v20230101/config.go：
 *   ServiceName = "live"、默认 region "cn-north-1"、host open.byteplusapi.com、version 2023-01-01。
 */
class BytePlusMediaLiveDeployer extends AbstractDeployer
{
    /** 直播签名 region（byteplus-sdk-golang live 默认 region）。 */
    private const LIVE_REGION = 'cn-north-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'medialive';
    }

    public function label(): string
    {
        return 'BytePlus 视频直播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new BytePlusMediaLiveUploader(
            fn (array $credentials): object => $this->makeClient('live', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（直播 ChainID）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $chainId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $chainId) {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('live', $credentials);
            // 绑定证书：Action=BindCert Version=2023-01-01 body {ChainID, Domain, HTTPS:true}
            $client->openApi('POST', 'BindCert', '2023-01-01', [], [
                'ChainID' => $chainId,
                'Domain' => $domain,
                'HTTPS' => true,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'live' => new BytePlusRestClient(
                'live',
                self::LIVE_REGION,
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

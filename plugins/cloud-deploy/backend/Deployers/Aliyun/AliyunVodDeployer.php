<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Vod\V20170321\Models\SetVodDomainSSLCertificateRequest;
use AlibabaCloud\SDK\Vod\V20170321\Vod;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 VOD（视频点播，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 vod.SetVodDomainSSLCertificate 以 CertType=cas + CertId + CertName + CertRegion 绑定。
 *
 * 与 dcdn 的差异：vod 绑定**额外需要 CertName**。因 RemoteCertStore 命中时会跳过上传（直接复用既有
 * remote_cert_id），上传时的 CertName 不可得，故 bind 内**按 CertId 反查 CAS GetUserCertificateDetail
 * 拿 name**（与上传命中/未命中无关，始终能取到），避免把 CertName 塞进 remote_cert_id 污染 dcdn 共用的去重值。
 *
 * 仅实现 exact domain 核心路径；不做 certimate 的 DomainMatchPattern（wildcard/certsan）/遍历域名（留后续）。
 */
class AliyunVodDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig;
    use ParsesCasCertIdentifier;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'vod';
    }

    public function label(): string
    {
        return '阿里云视频点播';
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
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');
        [$certId, $certRegion] = $this->parseCertIdentifier((string) $certRef);

        $this->guardSdk(function () use ($credentials, $domain, $certId, $certRegion) {
            // 反查 CertName（vod 绑定必填；RemoteCertStore 命中时上传被跳过，只能按 certId 取）
            /** @var Cas $cas */
            $cas = $this->makeClient('cas', $credentials);
            $detail = $cas->getUserCertificateDetail(new GetUserCertificateDetailRequest([
                'certId' => $certId,
                'certFilter' => true,
            ]));
            $certName = $detail->body?->name ?? '';

            /** @var Vod $client */
            $client = $this->makeClient('vod', $credentials);
            $client->setVodDomainSSLCertificate(new SetVodDomainSSLCertificateRequest([
                'domainName' => $domain,
                'certType' => 'cas',
                'certId' => $certId,
                'certName' => $certName,
                'certRegion' => $certRegion,
                'SSLProtocol' => 'on',
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, 'cas.aliyuncs.com')),
            'vod' => new Vod($this->aliyunConfig($credentials, 'vod.cn-hangzhou.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

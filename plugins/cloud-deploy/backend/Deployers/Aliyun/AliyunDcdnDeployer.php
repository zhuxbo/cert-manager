<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Dcdn\V20180115\Dcdn;
use AlibabaCloud\SDK\Dcdn\V20180115\Models\SetDcdnDomainSSLCertificateRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 DCDN（全站加速，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 dcdn.SetDcdnDomainSSLCertificate 以 CertType=cas + CertId + CertRegion 绑定。
 *
 * 仅实现 exact domain 核心路径；不做 certimate 的 DomainMatchPattern（wildcard/certsan）/遍历域名（留后续）。
 */
class AliyunDcdnDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig;
    use ParsesCasCertIdentifier;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'dcdn';
    }

    public function label(): string
    {
        return '阿里云 DCDN';
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
        // 上传器复用 deployer 的注入缝：测试 override makeClient('cas') 即作用于上传
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
            /** @var Dcdn $client */
            $client = $this->makeClient('dcdn', $credentials);
            $client->setDcdnDomainSSLCertificate(new SetDcdnDomainSSLCertificateRequest([
                'domainName' => $domain,
                'certType' => 'cas',
                'certId' => $certId,
                'certRegion' => $certRegion,
                'SSLProtocol' => 'on',
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, 'cas.aliyuncs.com')),
            'dcdn' => new Dcdn($this->aliyunConfig($credentials, 'dcdn.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

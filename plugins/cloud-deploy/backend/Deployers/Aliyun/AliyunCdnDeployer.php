<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cdn\V20180510\Cdn;
use AlibabaCloud\SDK\Cdn\V20180510\Models\SetCdnDomainSSLCertificateRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 阿里云 CDN（内联型）：CDN 支持 CertType=upload 直传 PEM，不走证书服务，故 usesRemoteCertStore=false、certUploader=null。
 * 绑定调官方 SDK Cdn::setCdnDomainSSLCertificate。
 */
class AliyunCdnDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '阿里云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');
        // 证书 + 中间证书拼成完整链上传
        $sslPub = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $this->guardSdk(function () use ($credentials, $domain, $sslPub, $certRef) {
            /** @var Cdn $client */
            $client = $this->makeClient('cdn', $credentials);
            $client->setCdnDomainSSLCertificate(new SetCdnDomainSSLCertificateRequest([
                'domainName' => $domain,
                'SSLProtocol' => 'on',
                'certType' => 'upload',
                'SSLPub' => $sslPub,
                'SSLPri' => $certRef['key'],
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new Cdn($this->aliyunConfig($credentials, 'cdn.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

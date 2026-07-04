<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Live\V20161101\Live;
use AlibabaCloud\SDK\Live\V20161101\Models\SetLiveDomainCertificateRequest;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 阿里云直播（内联型）：直播 SetLiveDomainCertificate 只支持 CertType=upload 直传 PEM
 * （SDK 请求体无 CertId/CertRegion 字段，不支持 CAS 引用），故 usesRemoteCertStore=false、certUploader=null，
 * 与阿里云 CDN 同型，对齐 certimate aliyun-live。
 *
 * 每次绑定生成唯一 CertName（阿里云命名规则：字母/数字/下划线）。
 * 仅实现 exact domain 核心路径；不做 certimate 的 DomainMatchPattern（wildcard/certsan）/遍历域名（留后续）。
 */
class AliyunLiveDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'live';
    }

    public function label(): string
    {
        return '阿里云直播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
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
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $domain, $sslPub, $certRef, $certName) {
            /** @var Live $client */
            $client = $this->makeClient('live', $credentials);
            $client->setLiveDomainCertificate(new SetLiveDomainCertificateRequest([
                'domainName' => $domain,
                'certName' => $certName,
                'certType' => 'upload',
                'SSLProtocol' => 'on',
                'SSLPub' => $sslPub,
                'SSLPri' => $certRef['key'],
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'live' => new Live(new Config([
                'accessKeyId' => $credentials['access_key_id'] ?? '',
                'accessKeySecret' => $credentials['access_key_secret'] ?? '',
                'endpoint' => 'live.aliyuncs.com',
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

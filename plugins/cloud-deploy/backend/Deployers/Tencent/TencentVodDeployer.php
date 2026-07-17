<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Vod\V20180717\Models\SetVodDomainCertificateRequest;
use TencentCloud\Vod\V20180717\VodClient;
use Throwable;

/**
 * 腾讯云点播 VOD（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调点播（vod/v20180717）SetVodDomainCertificate 以 Operation='Set' +
 * CertID（注意官方字段大写 ID）把证书设到点播加速域名。
 * 仅实现 certimate tencentcloud-vod 的 exact 单域名核心路径（不带 SubAppId/certsan）。
 */
class TencentVodDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'vod';
    }

    public function label(): string
    {
        return '腾讯云点播 VOD';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '点播加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');

        $this->guardSdk(function () use ($credentials, $domain, $certRef) {
            /** @var VodClient $client */
            $client = $this->makeClient('vod', $credentials);
            $req = new SetVodDomainCertificateRequest;
            // 字段大写 CertID 为腾讯点播官方拼写（区别于 cdn 的 CertId），deserialize 按此键填充
            $req->deserialize([
                'Domain' => $domain,
                'Operation' => 'Set',
                'CertID' => $certRef,
            ]);
            $client->SetVodDomainCertificate($req);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL 全局服务、点播接口无区域维度（certimate 亦传空 region），region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'vod' => new VodClient($cred, '', $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

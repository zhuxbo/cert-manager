<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Live\V20180801\LiveClient;
use TencentCloud\Live\V20180801\Models\ModifyLiveDomainCertBindingsRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云直播 CSS（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调直播（live/v20180801）ModifyLiveDomainCertBindings 以 CloudCertId +
 * DomainInfos[].{DomainName,Status=1} 绑定到播放域名并启用 HTTPS。
 * 仅实现 certimate tencentcloud-css 的 exact 单域名核心路径（不做 certsan）。
 */
class TencentCssDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'css';
    }

    public function label(): string
    {
        return '腾讯云直播 CSS';
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
            /** @var LiveClient $client */
            $client = $this->makeClient('live', $credentials);
            $req = new ModifyLiveDomainCertBindingsRequest;
            $req->deserialize([
                'DomainInfos' => [['DomainName' => $domain, 'Status' => 1]],
                'CloudCertId' => $certRef,
            ]);
            $client->ModifyLiveDomainCertBindings($req);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL 全局服务、直播接口无区域维度（certimate 亦传空 region），region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'live' => new LiveClient($cred, '', $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

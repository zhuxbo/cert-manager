<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Cdn\V20180606\CdnClient;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigRequest;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云 ECDN（证书服务型）：ECDN 已并入 CDN 接口体系，绑定证书复用 CDN SDK
 * （cdn/v20180606）的 UpdateDomainConfig 设 Https.CertInfo.CertId——
 * 与 TencentCdnDeployer 同一 bind API，无 ServiceType 区分，仅展示标签独立
 * （对齐 certimate tencentcloud-ecdn：其 createSDKClient 即 tccdn.NewClient）。
 */
class TencentEcdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'ecdn';
    }

    public function label(): string
    {
        return '腾讯云 ECDN';
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
            /** @var CdnClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $req = new UpdateDomainConfigRequest;
            $req->deserialize([
                'Domain' => $domain,
                'Https' => [
                    'Switch' => 'on',
                    'CertInfo' => ['CertId' => $certRef],
                ],
            ]);
            $client->UpdateDomainConfig($req);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // CDN/SSL 为全局服务，region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'cdn' => new CdnClient($cred, '', $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

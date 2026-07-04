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
 * 腾讯云 CDN（证书服务型）：证书先经 SSL 服务上传拿 CertificateId（走 RemoteCertStore 去重），
 * 再调 CDN UpdateDomainConfig 设 Https.CertInfo.CertId 绑定（与现状一致，非 DeployCertificateInstance）。
 */
class TencentCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '腾讯云 CDN';
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
        // 上传器复用 deployer 的注入缝：测试 override makeClient('ssl') 即作用于上传
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

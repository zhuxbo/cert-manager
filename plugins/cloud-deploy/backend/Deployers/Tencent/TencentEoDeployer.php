<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\TeoClient;
use Throwable;

/**
 * 腾讯云 EdgeOne（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 TEO（teo/v20220901）ModifyHostsCertificate 以 Mode='sslcert' +
 * ServerCertInfo[].CertId 把证书绑到站点（ZoneId）下的加速域名（Hosts）。
 * 仅实现 certimate tencentcloud-eo 的 exact 单域名核心路径（不做 wildcard/certsan/多证书 EnableMultipleSSL）。
 */
class TencentEoDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'eo';
    }

    public function label(): string
    {
        return '腾讯云 EdgeOne';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'zone_id', 'label' => '站点 ID', 'type' => 'string', 'required' => true],
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
     * @param  array{zone_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $zoneId = $this->requireConfig($config, 'zone_id');
        $domain = $this->requireConfig($config, 'domain');

        $this->guardSdk(function () use ($credentials, $zoneId, $domain, $certRef) {
            /** @var TeoClient $client */
            $client = $this->makeClient('teo', $credentials);
            $req = new ModifyHostsCertificateRequest;
            $req->deserialize([
                'ZoneId' => $zoneId,
                'Mode' => 'sslcert',
                'Hosts' => [$domain],
                'ServerCertInfo' => [['CertId' => $certRef]],
            ]);
            $client->ModifyHostsCertificate($req);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL 全局服务、TEO 接口无区域维度（certimate 亦传空 region），region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'teo' => new TeoClient($cred, '', $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

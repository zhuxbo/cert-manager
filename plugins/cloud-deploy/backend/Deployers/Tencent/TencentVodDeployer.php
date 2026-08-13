<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Vod\V20180717\Models\DescribeVodDomainsRequest;
use TencentCloud\Vod\V20180717\Models\SetVodDomainCertificateRequest;
use TencentCloud\Vod\V20180717\VodClient;
use Throwable;

/**
 * 腾讯云点播 VOD（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调点播（vod/v20180717）SetVodDomainCertificate 以 Operation='Set' +
 * CertID（注意官方字段大写 ID）把证书设到点播加速域名。
 * 实现 certimate tencentcloud-vod 的 exact 单域名路径，可选透传 SubAppId。
 */
class TencentVodDeployer extends AbstractDeployer
{
    use UsesTencentEndpoint;

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
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'sub_app_id', 'label' => '点播子应用 ID（选填）', 'type' => 'number', 'required' => false],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '点播加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string,sub_app_id?:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $subAppId = isset($config['sub_app_id']) && is_numeric($config['sub_app_id'])
            ? (int) $config['sub_app_id']
            : 0;

        /** @var VodClient $client */
        $client = $this->makeClient('vod', $credentials);
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domains = match ($pattern) {
            '', 'exact' => [(string) $this->requireConfig($config, 'domain')],
            'certsan' => $this->listVodDomains($client, $subAppId),
            default => $this->fail("不支持的域名匹配模式: $pattern"),
        };

        foreach ($domains as $domain) {
            $this->guardSdk(function () use ($client, $domain, $subAppId, $certRef) {
                $req = new SetVodDomainCertificateRequest;
                // 字段大写 CertID 为腾讯点播官方拼写（区别于 cdn 的 CertId），deserialize 按此键填充
                $payload = [
                    'Domain' => $domain,
                    'Operation' => 'Set',
                    'CertID' => $certRef,
                ];
                if ($subAppId > 0) {
                    $payload['SubAppId'] = $subAppId;
                }
                $req->deserialize($payload);
                $client->SetVodDomainCertificate($req);
            });
        }
    }

    /** @return list<string> */
    private function listVodDomains(VodClient $client, int $subAppId): array
    {
        $offset = 0;
        $domains = [];
        do {
            $response = $this->guardSdk(function () use ($client, $offset, $subAppId) {
                $request = new DescribeVodDomainsRequest;
                $payload = ['Offset' => $offset, 'Limit' => 20];
                if ($subAppId !== 0) {
                    $payload['SubAppId'] = $subAppId;
                }
                $request->deserialize($payload);

                return $client->DescribeVodDomains($request);
            });
            $items = $response->getDomainSet();
            foreach ($items as $item) {
                if ((string) $item->getDeployStatus() !== 'Locked' && (string) $item->getDomain() !== '') {
                    $domains[] = (string) $item->getDomain();
                }
            }
            $offset += 20;
        } while (count($items) === 20);

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL 全局服务、点播接口无区域维度（certimate 亦传空 region），region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'vod' => new VodClient($cred, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

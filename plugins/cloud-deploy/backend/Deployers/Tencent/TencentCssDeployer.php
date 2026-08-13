<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Live\V20180801\LiveClient;
use TencentCloud\Live\V20180801\Models\DescribeLiveDomainsRequest;
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
    use MatchesTencentCertificateDomains;
    use UsesTencentEndpoint;

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
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => false],
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
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));

        /** @var LiveClient $client */
        $client = $this->makeClient('live', $credentials);
        if ($pattern === '' || $pattern === 'exact') {
            $domains = [(string) $this->requireConfig($config, 'domain')];
        } elseif ($pattern === 'certsan') {
            $domains = $this->matchTencentCertificateDomains((string) $certRef, $credentials, $this->listCssDomains($client));
        } else {
            $this->fail("不支持的域名匹配模式: $pattern");
        }
        if ($domains === []) {
            $this->fail('未找到证书 SAN 匹配的腾讯云直播域名');
        }

        $this->guardSdk(function () use ($client, $domains, $certRef) {
            $req = new ModifyLiveDomainCertBindingsRequest;
            $req->deserialize([
                'DomainInfos' => array_map(fn (string $domain): array => ['DomainName' => $domain, 'Status' => 1], $domains),
                'CloudCertId' => $certRef,
            ]);
            $client->ModifyLiveDomainCertBindings($req);
        });
    }

    /** @return list<string> */
    private function listCssDomains(LiveClient $client): array
    {
        $page = 1;
        $domains = [];
        do {
            $response = $this->guardSdk(function () use ($client, $page) {
                $request = new DescribeLiveDomainsRequest;
                $request->deserialize(['DomainStatus' => 1, 'DomainType' => 1, 'PageNum' => $page, 'PageSize' => 100]);

                return $client->DescribeLiveDomains($request);
            });
            $items = $response->getDomainList();
            foreach ($items as $item) {
                if ((string) $item->getName() !== '') {
                    $domains[] = (string) $item->getName();
                }
            }
            $page++;
        } while (count($items) === 100);

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

        // SSL 全局服务、直播接口无区域维度（certimate 亦传空 region），region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'live' => new LiveClient($cred, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

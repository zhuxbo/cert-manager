<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Scf\V20180416\Models\CertConf;
use TencentCloud\Scf\V20180416\Models\GetCustomDomainRequest;
use TencentCloud\Scf\V20180416\Models\ListCustomDomainsRequest;
use TencentCloud\Scf\V20180416\Models\UpdateCustomDomainRequest;
use TencentCloud\Scf\V20180416\ScfClient;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云云函数 SCF（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 SCF（scf/v20180416）UpdateCustomDomain 以 CertConfig.CertificateId
 * 把证书设到云函数自定义域名（Domain）。
 *
 * 先 GetCustomDomain 读取现有 Protocol（更新接口 Protocol 必传，HTTPS 协议证书才生效）——
 * 现有 Protocol 为空或 HTTP 时升级为 HTTP&HTTPS，否则原样保留，对齐 certimate updateDomainCertificate。
 *
 * 简化：仅实现 exact 单域名核心路径（对应 certimate tencentcloud-scf DOMAIN_MATCH_PATTERN_EXACT）；
 * 不做 certsan（ListCustomDomains 遍历 + 证书 SAN 匹配多域名）、不跳过「已是同证书」短路。
 *
 * SCF 接口是 region 维度，client 构造必须带 region（certimate createSDKClient 传 config.Region），
 * 故 region 为必填配置；SSL 上传服务仍为全局（空 region）。
 */
class TencentScfDeployer extends AbstractDeployer
{
    use MatchesTencentCertificateDomains;
    use UsesTencentEndpoint;

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'scf';
    }

    public function label(): string
    {
        return '腾讯云 SCF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => false],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 腾讯 SSL 上传是全局服务（空 region），与 SCF 的 region 维度无关
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $region = (string) $this->requireConfig($config, 'region');

        /** @var ScfClient $client */
        $client = $this->makeClient('scf', $credentials, $region);
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domains = match ($pattern) {
            '', 'exact' => [(string) $this->requireConfig($config, 'domain')],
            'certsan' => $this->matchTencentCertificateDomains((string) $certRef, $credentials, $this->listScfDomains($client)),
            default => $this->fail("不支持的域名匹配模式: $pattern"),
        };
        if ($domains === []) {
            $this->fail('未找到证书 SAN 匹配的 SCF 自定义域名');
        }

        foreach ($domains as $domain) {
            $this->guardSdk(function () use ($client, $domain, $certRef) {

                // 读现有 Protocol（更新接口必传）
                $getReq = new GetCustomDomainRequest;
                $getReq->deserialize(['Domain' => $domain]);
                $getResp = $client->GetCustomDomain($getReq);
                /** @var CertConf|null $certConfig SDK 字段未标 nullable，但服务端可省略。 */
                $certConfig = $getResp->CertConfig;
                if ($certConfig?->getCertificateId() === (string) $certRef) {
                    return;
                }
                /** @var string|null $protocol SDK 字段未标 nullable，但服务端可省略。 */
                $protocol = $getResp->Protocol;
                $protocol = (string) $protocol;
                if ($protocol === '' || $protocol === 'HTTP') {
                    $protocol = 'HTTP&HTTPS';
                }

                // 更新证书：CertConfig（注意键名 CertConfig，类型 CertConf）.CertificateId
                $updateReq = new UpdateCustomDomainRequest;
                $updateReq->deserialize([
                    'Domain' => $domain,
                    'Protocol' => $protocol,
                    'CertConfig' => ['CertificateId' => $certRef],
                ]);
                $client->UpdateCustomDomain($updateReq);
            });
        }
    }

    /** @return list<string> */
    private function listScfDomains(ScfClient $client): array
    {
        $offset = 0;
        $domains = [];
        do {
            $response = $this->guardSdk(function () use ($client, $offset) {
                $request = new ListCustomDomainsRequest;
                $request->deserialize(['Offset' => $offset, 'Limit' => 20]);

                return $client->ListCustomDomains($request);
            });
            $items = $response->getDomains();
            foreach ($items as $item) {
                if ((string) $item->getDomain() !== '') {
                    $domains[] = (string) $item->getDomain();
                }
            }
            $offset += 20;
        } while (count($items) === 20);

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            // SSL 全局服务（certimate 亦传空 region）
            'ssl' => new SslClient($cred, '', $profile),
            // SCF region 维度：client 构造必须带 region
            'scf' => new ScfClient($cred, $region, $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

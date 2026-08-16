<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 火山引擎视频直播 Live（证书服务型）：证书经火山 Live 自有证书空间上传拿 ChainID（走 RemoteCertStore 去重），
 * 再绑定到直播流域名。对齐 certimate volcengine-live（volc-sdk-golang）：
 *   CreateCert（上传，VolcLiveUploader）→ BindCert {ChainID, Domain, HTTPS:true}
 *   （host live.volcengineapi.com，Version=2023-01-01，签名 service=live，region cn-north-1）
 *
 * 仅 exact 域名匹配（不做 wildcard/certsan 遍历）。Live 用自有证书空间（VolcLiveUploader，storeKind=volc_live），
 * 非证书中心。⚠ BindCert body 的 HTTPS 键为大写（对齐官方 SDK json tag）。
 */
class VolcLiveDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesVolcDomains;

    public const HOST = 'live.volcengineapi.com';

    public const VERSION = '2023-01-01';

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'live';
    }

    public function label(): string
    {
        return '火山引擎视频直播';
    }

    public function configSchema(): array
    {
        return [
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
        // Live 自有证书空间（非证书中心）；projectName 由凭证级配置注入（此处取不到凭证，留空，
        // certimate Live certmgr 的 ProjectName 来自凭证，上传时若需可在 makeClient 注入——保持与无 projectName 一致）。
        return new VolcLiveUploader(
            fn (array $credentials): object => $this->makeClient('live', $credentials),
        );
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  Live ChainID 与可选证书材料
     * @param  array{access_key_id?:string,secret_access_key?:string,project_name?:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        [$chainId, $certificate] = $this->volcCertificateReference($certRef);
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $domain, $chainId, $certificate, $pattern) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('live', $credentials);
            $candidates = in_array($pattern, ['wildcard', 'certsan'], true) ? $this->listLiveDomains($client) : [];
            $domains = $this->matchVolcDomains($candidates, $pattern, $domain, $certificate);
            if ($domains === []) {
                $this->fail('未找到匹配的直播域名');
            }
            foreach ($domains as $matchedDomain) {
                $client->callJson('BindCert', self::VERSION, [
                    'ChainID' => $chainId,
                    'Domain' => $matchedDomain,
                    'HTTPS' => true,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function listLiveDomains(VolcRestClient $client): array
    {
        $domains = [];
        $page = 1;
        do {
            $result = $client->callJson('ListDomainDetail', self::VERSION, [
                'DomainStatusList' => [0],
                'PageNum' => $page,
                'PageSize' => 1000,
            ]);
            $items = is_array($result['Result']['DomainList'] ?? null) ? $result['Result']['DomainList'] : [];
            foreach ($items as $item) {
                $candidate = is_array($item) ? (string) ($item['Domain'] ?? '') : '';
                if ($candidate !== '') {
                    $domains[] = $candidate;
                }
            }
            $page++;
        } while (count($items) >= 1000);

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // Live 专属 host + 签名 service live + region cn-north-1
            'live' => new VolcRestClient(
                self::HOST,
                'live',
                'cn-north-1',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}

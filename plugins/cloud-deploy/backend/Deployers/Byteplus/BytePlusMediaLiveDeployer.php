<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * BytePlus 视频直播 Media Live（证书服务型）：证书先经直播 CreateCert 上传拿 ChainID（走 RemoteCertStore
 * 去重），再 BindCert 关联到直播流域名。
 *
 * 对齐 certimate byteplus-medialive：
 *   - 上传：直播 CreateCert(Rsa, UseWay=https) → ChainID（certmgr byteplus-medialive）。
 *   - 绑定：BindCert{ChainID, Domain, HTTPS:true}（updateDomainCertificate）。
 *
 * 仅实现 exact domain 核心路径；不做 certimate 的 wildcard（ListDomainDetail 分页匹配）/ certsan 多域名遍历。
 *
 * 签名 service / region 对齐 byteplus-sdk-golang service/live/v20230101/config.go：
 *   ServiceName = "live"、默认 region "cn-north-1"、host open.byteplusapi.com、version 2023-01-01。
 */
class BytePlusMediaLiveDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesBytePlusDomains;
    use MatchesCertificateHostnames;

    /** 直播签名 region（byteplus-sdk-golang live 默认 region）。 */
    private const LIVE_REGION = 'cn-north-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'medialive';
    }

    public function label(): string
    {
        return 'BytePlus 视频直播';
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
        return new BytePlusMediaLiveUploader(
            fn (array $credentials): object => $this->makeClient('live', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（直播 ChainID）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $chainId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $pattern, $certificate, $chainId) {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('live', $credentials);
            if (! in_array($pattern, ['exact', 'wildcard', 'certsan'], true)) {
                $this->fail("BytePlus MediaLive 不支持的域名匹配模式: $pattern");
            }
            $domains = [$domain];
            if ($pattern === 'certsan' || ($pattern === 'wildcard' && str_starts_with($domain, '*.'))) {
                $domains = [];
                for ($page = 1; ; $page++) {
                    $result = $client->openApi('POST', 'ListDomainDetail', '2023-01-01', [], [
                        'DomainStatusList' => [0], 'PageNum' => $page, 'PageSize' => 1000,
                    ]);
                    $items = is_array($result->DomainList ?? null) ? $result->DomainList : [];
                    foreach ($items as $item) {
                        $candidate = (string) ($item->Domain ?? '');
                        if (($pattern === 'wildcard' && $this->hostnameMatches($domain, $candidate))
                            || ($pattern === 'certsan' && $this->certificateMatchesHostname($certificate, $candidate))) {
                            $domains[] = $candidate;
                        }
                    }
                    if (count($items) < 1000) {
                        break;
                    }
                }
            }
            if ($domains === []) {
                $this->fail('未找到匹配的 MediaLive 域名');
            }
            foreach ($domains as $matchedDomain) {
                $client->openApi('POST', 'BindCert', '2023-01-01', [], [
                    'ChainID' => $chainId, 'Domain' => $matchedDomain, 'HTTPS' => true,
                ]);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'live' => new BytePlusRestClient(
                'live',
                self::LIVE_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}

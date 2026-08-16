<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Qiniu\Auth;
use Throwable;

/**
 * 七牛云融合 CDN（证书服务型）：证书先经七牛证书中心上传拿 certID（走 RemoteCertStore 去重），
 * 再按域名当前 HTTPS 状态绑定：
 *   - 未启用 HTTPS（https 为空或 certId 空）→ EnableDomainHttps(sslize)
 *   - 已启用但 certId 不同 → ModifyDomainHttpsConf(httpsconf)，沿用域名原有 forceHttps/http2Enable
 *   - 已启用且 certId 相同 → 无操作
 *
 * 仅实现 exact domain 核心路径（对齐插件既有 cdn/dcdn 约定）；不做 certimate 的 wildcard/certsan
 * 遍历域名（DomainMatchPattern）。exact 模式去掉域名前导 "*"（"*.example.com" → ".example.com"，
 * 适配七牛 CDN 泛域名格式），与 certimate qiniu-cdn 一致。
 */
class QiniuCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;
    use ParsesQiniuCertRef;

    public function provider(): string
    {
        return 'qiniu';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '七牛云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 上传器复用 deployer 的注入缝：测试 override makeClient('api') 即作用于上传
        return new QiniuSslUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  复合 remote_cert_id 与可选证书材料
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        $remoteRef = is_array($certRef) ? $certRef['remote_cert_id'] : $certRef;
        $certificate = is_array($certRef) ? $certRef['cert'] : '';
        [$certId] = $this->parseCertRef($remoteRef);
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $domain, $certId, $certificate, $pattern) {
            /** @var QiniuRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $candidates = in_array($pattern, ['wildcard', 'certsan'], true) ? $client->listCdnDomains() : [];
            $domains = match ($pattern) {
                '', 'exact' => [preg_replace('/^\*/', '', $domain) ?? $domain],
                'wildcard' => array_values(array_filter($candidates, fn (string $candidate): bool => $this->certificateHostnamePatternMatches($domain, $candidate))),
                'certsan' => $certificate === '' ? $this->fail('certsan 匹配缺少证书材料') : array_values(array_filter($candidates, fn (string $candidate): bool => $this->certificateMatchesHostname($certificate, $candidate))),
                default => $this->fail("不支持的域名匹配模式 $pattern"),
            };
            if ($domains === []) {
                $this->fail('未找到匹配的 CDN 域名');
            }
            foreach ($domains as $matchedDomain) {
                $info = $client->getCdnDomainInfo($matchedDomain);
                $https = $info['https'];
                $boundCertId = is_array($https) && is_string($https['certId'] ?? null) ? $https['certId'] : '';

                if ($https === null || $boundCertId === '') {
                    // 未启用 HTTPS → 启用并绑证书
                    $client->enableCdnDomainHttps($matchedDomain, $certId, true, true);
                } elseif ($boundCertId !== $certId) {
                    // 已启用但证书不同 → 改证书，沿用原 forceHttps/http2Enable
                    $client->modifyCdnDomainHttpsConf(
                        $matchedDomain,
                        $certId,
                        (bool) ($https['forceHttps'] ?? false),
                        (bool) ($https['http2Enable'] ?? false),
                    );
                }
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new QiniuRestClient(new Auth(
                $credentials['access_key'] ?? '',
                $credentials['secret_key'] ?? '',
            )),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return QiniuErrorSanitizer::sanitize($e);
    }
}

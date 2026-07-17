<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
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
class QiniuCdnDeployer extends AbstractDeployer
{
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
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
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
     * @param  string  $certRef  复合 remote_cert_id "{certID}|{certName}"（CDN 用 certID）
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');
        [$certId] = $this->parseCertRef((string) $certRef);
        // exact：去掉前导 "*"，适配七牛 CDN 泛域名格式（"*.example.com" → ".example.com"）
        $domain = ltrim((string) $domain) === '' ? (string) $domain : preg_replace('/^\*/', '', (string) $domain);

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var QiniuRestClient $client */
            $client = $this->makeClient('api', $credentials);

            $info = $client->getCdnDomainInfo((string) $domain);
            $https = $info['https'];
            $boundCertId = is_array($https) && is_string($https['certId'] ?? null) ? $https['certId'] : '';

            if ($https === null || $boundCertId === '') {
                // 未启用 HTTPS → 启用并绑证书
                $client->enableCdnDomainHttps((string) $domain, $certId, true, true);
            } elseif ($boundCertId !== $certId) {
                // 已启用但证书不同 → 改证书，沿用原 forceHttps/http2Enable
                $client->modifyCdnDomainHttpsConf(
                    (string) $domain,
                    $certId,
                    (bool) ($https['forceHttps'] ?? false),
                    (bool) ($https['http2Enable'] ?? false),
                );
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
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return QiniuErrorSanitizer::sanitize($e);
    }
}

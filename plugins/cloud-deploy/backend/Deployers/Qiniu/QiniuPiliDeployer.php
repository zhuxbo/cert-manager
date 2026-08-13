<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Qiniu\Auth;
use Throwable;

/**
 * 七牛云直播 Pili（证书服务型）：证书先经七牛证书中心上传拿 certID + certName（走 RemoteCertStore 去重），
 * 再调 SetDomainCert 绑定域名证书（POST /v2/hubs/{hub}/domains/{domain}/cert {certName}）。不支持泛域名。
 *
 * 关键差异：Pili 绑定收 **certName**（非 cdn/kodo 的 certID）—— 七牛 Pili SetDomainCert 接口只接收
 * certName。故从复合 remote_cert_id "{certID}|{certName}" 拆出 certName 使用。
 *
 * 仅实现 exact domain 核心路径（对齐插件既有约定）；不做 certimate 的 certsan 遍历 hub 域名。
 */
class QiniuPiliDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;
    use ParsesQiniuCertRef;

    public function provider(): string
    {
        return 'qiniu';
    }

    public function product(): string
    {
        return 'pili';
    }

    public function label(): string
    {
        return '七牛云 Pili 直播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'hub', 'label' => '直播空间名', 'type' => 'string', 'required' => true],
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
        return new QiniuSslUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  复合 remote_cert_id 与可选证书材料
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{hub:string,domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $hub = $this->requireConfig($config, 'hub');
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        $remoteRef = is_array($certRef) ? $certRef['remote_cert_id'] : $certRef;
        $certificate = is_array($certRef) ? $certRef['cert'] : '';
        [, $certName] = $this->parseCertRef($remoteRef);
        if (in_array($pattern, ['', 'exact'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $hub, $domain, $certName, $certificate, $pattern) {
            /** @var QiniuRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $domains = match ($pattern) {
                '', 'exact' => [$domain],
                'certsan' => $certificate === '' ? $this->fail('certsan 匹配缺少证书材料') : array_values(array_filter(
                    $client->listPiliDomains((string) $hub),
                    fn (string $candidate): bool => $this->certificateMatchesHostname($certificate, $candidate),
                )),
                default => $this->fail("不支持的域名匹配模式 $pattern"),
            };
            if ($domains === []) {
                $this->fail('未找到匹配证书的 Pili 域名');
            }
            foreach ($domains as $matchedDomain) {
                $client->setPiliDomainCert((string) $hub, $matchedDomain, $certName);
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

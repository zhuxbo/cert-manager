<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
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
class QiniuPiliDeployer extends AbstractDeployer
{
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
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
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
     * @param  string  $certRef  复合 remote_cert_id "{certID}|{certName}"（Pili 用 certName）
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{hub:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $hub = $this->requireConfig($config, 'hub');
        $domain = $this->requireConfig($config, 'domain');
        [, $certName] = $this->parseCertRef((string) $certRef);

        $this->guardSdk(function () use ($credentials, $hub, $domain, $certName) {
            /** @var QiniuRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->setPiliDomainCert((string) $hub, (string) $domain, $certName);
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

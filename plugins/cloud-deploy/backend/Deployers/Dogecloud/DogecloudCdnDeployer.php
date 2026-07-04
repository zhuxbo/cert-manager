<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 多吉云 CDN（证书服务型）：证书先上传到多吉云拿 certId（走 RemoteCertStore 去重），再绑定到 CDN 域名。
 *
 * 对齐 certimate dogecloud-cdn 的 deployToDomain（DEPLOY_TARGET 默认 exact）：
 *   1. 证书经 DogecloudSslUploader 上传（POST /cdn/cert/upload.json）拿 certId（store_kind=dogecloud，走 RemoteCertStore 去重）。
 *   2. bind 调 POST /cdn/cert/bind.json {id: certId(int64), domain} 把证书绑定到加速域名。
 *
 * 与 certimate 对齐的取舍：certimate 支持 exact / certsan 两种匹配模式（certsan 会 ListCdnDomain 后按证书 SAN
 * 过滤批量绑定）。本端点**仅实现 exact**（domain 必填、直接绑定该域名），与插件其他端点（KsyunCdn/Qiniu/Baidu cdn 等）
 * 「仅 exact」的简化口径一致。如需按 SAN 批量部署，可逐个域名各配一个 target。
 */
class DogecloudCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'dogecloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '多吉云 CDN';
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
        return new DogecloudSslUploader(fn (array $credentials): object => $this->makeClient('cdn', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（多吉云证书 id，十进制字符串）
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (int) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var DogecloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $client->post('/cdn/cert/bind.json', [
                'id' => $certId,
                'domain' => $domain,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new DogecloudRestClient(
                $credentials['access_key'] ?? '',
                $credentials['secret_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return DogecloudErrorSanitizer::sanitize($e);
    }
}

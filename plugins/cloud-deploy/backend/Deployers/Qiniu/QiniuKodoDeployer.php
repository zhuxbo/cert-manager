<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Qiniu\Auth;
use Throwable;

/**
 * 七牛云对象存储 Kodo（证书服务型）：证书先经七牛证书中心上传拿 certID（走 RemoteCertStore 去重），
 * 再调 BindBucketCert 绑定自定义域名证书（PUT /cert/bind {certid, domain}）。不支持泛域名。
 *
 * 注：certimate qiniu-kodo 的 DeployerConfig 含 Bucket 字段但标注「暂时无用」（绑定只按 domain 走全局
 * /cert/bind 接口，不需要 bucket）。本插件据此把 bucket 列为**可选**展示字段、bind 不消费（不 requireConfig）。
 */
class QiniuKodoDeployer extends AbstractDeployer
{
    use ParsesQiniuCertRef;

    public function provider(): string
    {
        return 'qiniu';
    }

    public function product(): string
    {
        return 'kodo';
    }

    public function label(): string
    {
        return '七牛云 Kodo';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
            // certimate 暴露但「暂时无用」；保留为可选展示字段，bind 不消费
            ['key' => 'bucket', 'label' => '存储空间名', 'type' => 'string', 'required' => false],
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
     * @param  string  $certRef  复合 remote_cert_id "{certID}|{certName}"（Kodo 用 certID）
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{domain:string,bucket?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');
        [$certId] = $this->parseCertRef((string) $certRef);

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var QiniuRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->bindKodoBucketCert((string) $domain, $certId);
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

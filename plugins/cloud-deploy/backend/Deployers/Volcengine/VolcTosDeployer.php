<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Throwable;

/**
 * 火山引擎对象存储 TOS（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再为存储桶自定义域名绑证书。对齐 certimate volcengine-tos：
 *   PUT {bucket}.tos-{region}.volces.com/?customdomain
 *   body {CustomDomainRule:{Domain, CertId}}（TOS4-HMAC-SHA256 S3 风格签名，service=tos）
 *
 * 不支持泛域名（与 certimate 一致）。region 必填（TOS host 需 region）；证书中心上传与 TOS 用同一 region。
 * TOS 绑定走 VolcRestClient::putTos（独立 S3 风格签名），区别于其他端点的 OpenAPI 签名。
 */
class VolcTosDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'tos';
    }

    public function label(): string
    {
        return '火山引擎对象存储';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'bucket', 'label' => '存储桶名', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名（不支持泛域名）', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // RegistryCompleteness 以空 config 探活：region 优雅默认（真实 region 由 bind 强校验），不抛
        $region = (string) ($config['region'] ?? '');

        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * @param  string  $certRef  证书中心 InstanceId
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials
     * @param  array{region:string,bucket:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $bucket, $domain, $certId) {
            // bucket 是租户可控字段，可经 :port/ 注入突破 DNS 后缀直连内网（反模式 18）
            app(OutboundDestinationPolicy::class)->authorizeOfficialHost(
                $this->provider(),
                $bucket.'.tos-'.$region.'.volces.com',
            );

            /** @var VolcRestClient $client */
            $client = $this->makeClient('tos', $credentials, $region);
            $client->putTos($bucket, $region, [
                'CustomDomainRule' => [
                    'Domain' => $domain,
                    'CertId' => $certId,
                ],
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            // TOS：S3 风格签名（host 在 putTos 内按 bucket+region 构造，此处 host/service 仅占位，签名走 putTos 专用路径）
            'tos' => new VolcRestClient(
                "tos-$region.volces.com",
                'tos',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}

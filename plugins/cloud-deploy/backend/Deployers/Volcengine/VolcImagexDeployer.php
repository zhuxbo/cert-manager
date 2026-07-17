<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 火山引擎 veImageX 图片处理（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再设到 veImageX 服务的自定义域名。对齐 certimate volcengine-imagex（volc-sdk-golang）：
 *   GetDomainConfig（GET，query ServiceId+DomainName）读既有 HTTPS 配置
 *   → UpdateHttps（POST，query ServiceId + JSON body）设新证书并保留既有 HTTPS 开关
 *   （host imagex.volcengineapi.com，Version=2018-08-01，签名 service=ImageX，region cn-north-1）
 *
 * ⚠ 火山 veImageX 两处与通用协议不同（已对齐官方 SDK，写错静默丢值）：
 *   - Action 名为 `UpdateHttps`（非 UpdateHTTPS）
 *   - UpdateHttps body 为 **snake_case**：{domain, https:{cert_id, enable_https, ...}}（非 PascalCase）
 *
 * 不支持泛域名（与 certimate 一致）。证书中心上传走默认 cn-beijing。
 */
class VolcImagexDeployer extends AbstractDeployer
{
    private const HOST = 'imagex.volcengineapi.com';

    private const VERSION = '2018-08-01';

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'imagex';
    }

    public function label(): string
    {
        return '火山引擎 veImageX';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'service_id', 'label' => '服务 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名（不支持泛域名）', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 证书中心默认 cn-beijing（certimate imagex 的 certmgr 默认）
        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials),
        );
    }

    /**
     * @param  string  $certRef  证书中心 InstanceId
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials
     * @param  array{service_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $serviceId = (string) $this->requireConfig($config, 'service_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $serviceId, $domain, $certId) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('imagex', $credentials);

            // 读既有域名配置（GET，query ServiceId+DomainName）
            $domainConfig = $client->callQuery('GetDomainConfig', self::VERSION, [
                'ServiceId' => $serviceId,
                'DomainName' => $domain,
            ]);
            $httpsConfig = is_array($domainConfig['HTTPSConfig'] ?? null) ? $domainConfig['HTTPSConfig'] : [];

            // 组 https body（snake_case），保留既有开关，换证书
            $https = [
                'cert_id' => $certId,
                'enable_https' => true,
            ];
            if ($httpsConfig !== []) {
                $https['enable_https'] = (bool) ($httpsConfig['EnableHTTPS'] ?? true);
                $https['enable_http2'] = (bool) ($httpsConfig['EnableHTTP2'] ?? false);
                $https['enable_ocsp'] = (bool) ($httpsConfig['EnableOcsp'] ?? false);
                $https['enable_force_redirect'] = (bool) ($httpsConfig['EnableForceRedirect'] ?? false);
                if (isset($httpsConfig['TLSVersions']) && is_array($httpsConfig['TLSVersions'])) {
                    $https['tls_versions'] = array_values($httpsConfig['TLSVersions']);
                }
                if (isset($httpsConfig['ForceRedirectType']) && is_string($httpsConfig['ForceRedirectType'])) {
                    $https['force_redirect_type'] = $httpsConfig['ForceRedirectType'];
                }
                if (isset($httpsConfig['ForceRedirectCode']) && is_string($httpsConfig['ForceRedirectCode'])) {
                    $https['force_redirect_code'] = $httpsConfig['ForceRedirectCode'];
                }
            }

            // UpdateHttps：POST，query ServiceId + JSON body（snake_case）
            $client->callJson('UpdateHttps', self::VERSION, [
                'domain' => $domain,
                'https' => $https,
            ], ['ServiceId' => $serviceId]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // veImageX 专属 host + 签名 service ImageX（PascalCase！）+ region cn-north-1
            'imagex' => new VolcRestClient(
                self::HOST,
                'ImageX',
                'cn-north-1',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                'cn-beijing',
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

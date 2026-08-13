<?php

namespace Plugins\CloudDeploy\Deployers\Gcore;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * Gcore CDN（证书服务型）。
 *
 * 对齐 certimate gcore-cdn：先把证书上传到 Gcore SSL 证书服务（POST /cdn/sslData，由 GcoreSslUploader +
 * RemoteCertStore 完成、去重），再把证书绑定到 CDN 资源（GET /cdn/resources/{id} 取详情 →
 * PUT /cdn/resources/{id} 回写 sslEnabled=true + sslData=证书 id，其余字段原样保留）。
 *
 * usesRemoteCertStore=true：bind 收 remote_cert_id（云端 sslData id 字符串）。
 * 鉴权 Authorization: APIKey {token}（api_token 凭证）。
 *
 * config：resource_id（必填，CDN 资源 ID）。
 *
 * 字段名严格对齐 gcorelabscdn-go v1.0.37 resources.UpdateRequest（写错大小写会被静默丢成 null）：
 *   description / active / originGroup / originProtocol / secondaryHostnames / sslEnabled / sslData /
 *   proxy_ssl_enabled / proxy_ssl_ca / proxy_ssl_data / options。
 * proxy_ssl_ca / proxy_ssl_data 为 0 时回写 null（对齐 certimate lo.Ternary(!=0, &v, nil)）。
 */
class CdnDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.gcore.com/';

    public function provider(): string
    {
        return 'gcore';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'Gcore CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'resource_id', 'label' => 'CDN 资源 ID', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_id', 'label' => '证书 ID（选填，填则原位替换）', 'type' => 'number', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new GcoreSslUploader(
            fn (array $credentials): object => $this->makeClient('api', $credentials),
            (int) ($config['certificate_id'] ?? 0),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（Gcore sslData id）
     * @param  array{api_token:string}  $credentials
     * @param  array{resource_id:string|int}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $resourceId = (int) $this->requireConfig($config, 'resource_id');
        $certId = (int) $certRef;

        $this->guardSdk(function () use ($credentials, $resourceId, $certId) {
            /** @var GcoreClient $client */
            $client = $this->makeClient('api', $credentials);

            // 取 CDN 资源详情，回写时原样保留非证书相关字段。
            $resource = $client->getResource($resourceId);

            $proxySslCa = (int) ($resource['proxy_ssl_ca'] ?? 0);
            $proxySslData = (int) ($resource['proxy_ssl_data'] ?? 0);

            $client->updateResource($resourceId, [
                'description' => (string) ($resource['description'] ?? ''),
                'active' => (bool) ($resource['active'] ?? true),
                'originGroup' => (int) ($resource['originGroup'] ?? 0),
                'originProtocol' => (string) ($resource['originProtocol'] ?? ''),
                'secondaryHostnames' => is_array($resource['secondaryHostnames'] ?? null) ? $resource['secondaryHostnames'] : [],
                'sslEnabled' => true,
                'sslData' => $certId,
                'proxy_ssl_enabled' => (bool) ($resource['proxy_ssl_enabled'] ?? false),
                // 0 时回写 null（对齐 certimate lo.Ternary(!=0, &v, nil)）
                'proxy_ssl_ca' => $proxySslCa !== 0 ? $proxySslCa : null,
                'proxy_ssl_data' => $proxySslData !== 0 ? $proxySslData : null,
                'options' => new \stdClass,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new GcoreClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'APIKey '.($credentials['api_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return GcoreErrorSanitizer::sanitize($e);
    }
}

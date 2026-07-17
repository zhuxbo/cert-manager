<?php

namespace Plugins\CloudDeploy\Deployers\Netlify;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Netlify 网站（内联型）。
 *
 * 对齐 certimate netlify（deployTarget=website）：把证书配置到指定 Netlify 站点的 SNI 证书
 * （POST /api/v1/sites/{siteId}/ssl）。
 * - cert（服务器证书）→ certificate
 * - chain（中间证书）→ ca_certificates
 * - key（私钥）→ key
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，直灌到站点 SSL。
 * 鉴权 Bearer Token（api_token 凭证）。
 *
 * config：site_id（必填）。
 */
class WebsiteDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.netlify.com/api/v1/';

    public function provider(): string
    {
        return 'netlify';
    }

    public function product(): string
    {
        return 'website';
    }

    public function label(): string
    {
        return 'Netlify 网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'site_id', 'label' => '网站 ID', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_token:string}  $credentials
     * @param  array{site_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $siteId = (string) $this->requireConfig($config, 'site_id');

        $params = [
            'certificate' => $certRef['cert'],
            'ca_certificates' => $certRef['chain'],
            'key' => $certRef['key'],
        ];

        $this->guardSdk(function () use ($credentials, $siteId, $params) {
            /** @var NetlifyClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->provisionSiteTlsCertificate($siteId, $params);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new NetlifyClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.($credentials['api_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return NetlifyErrorSanitizer::sanitize($e);
    }
}

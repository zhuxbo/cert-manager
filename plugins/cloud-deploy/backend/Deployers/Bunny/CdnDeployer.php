<?php

namespace Plugins\CloudDeploy\Deployers\Bunny;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Bunny CDN（内联型）。
 *
 * 对齐 certimate bunny-cdn：把证书添加到指定 Pull Zone 的自定义证书
 * （POST /pullzone/{pullZoneId}/addCertificate）。
 * - Certificate：完整链（rtrim(cert)."\n".trim(chain)）的 base64
 * - CertificateKey：私钥的 base64
 * - Hostname：绑定的主机名
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。
 * 鉴权 AccessKey 请求头（api_key 凭证）。
 *
 * config：pull_zone_id（必填）/ hostname（必填）。
 */
class CdnDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.bunny.net/';

    public function provider(): string
    {
        return 'bunny';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'Bunny CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'pull_zone_id', 'label' => 'Pull Zone ID', 'type' => 'string', 'required' => true],
            ['key' => 'hostname', 'label' => 'CDN 主机名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_key:string}  $credentials
     * @param  array{pull_zone_id:string,hostname:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pullZoneId = (string) $this->requireConfig($config, 'pull_zone_id');
        $hostname = (string) $this->requireConfig($config, 'hostname');
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $body = [
            'Hostname' => $hostname,
            'Certificate' => base64_encode($fullChain),
            'CertificateKey' => base64_encode($certRef['key']),
        ];

        $this->guardSdk(function () use ($credentials, $pullZoneId, $body) {
            /** @var BunnyClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->addCustomCertificate($pullZoneId, $body);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new BunnyClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'AccessKey' => (string) ($credentials['api_key'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BunnyErrorSanitizer::sanitize($e);
    }
}

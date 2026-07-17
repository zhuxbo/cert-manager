<?php

namespace Plugins\CloudDeploy\Deployers\Cloudflare;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Cloudflare SSL（自定义证书，内联型）。
 *
 * 对齐 certimate cloudflare-ssl：把证书直灌到 Cloudflare Zone 的自定义证书（Custom SSL）。
 * - 未填 certificate_id：`POST /zones/{zone}/custom_certificates` 新建（certificate=完整链 + private_key
 *   + bundle_method=ubiquitous + deploy=environment||production）。
 * - 填了 certificate_id：`PATCH /zones/{zone}/custom_certificates/{id}` 更新已有证书内容。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，certificate 用 cert+chain 完整链。
 * 鉴权 Bearer Token（api_token 凭证）。仅企业版 Zone 支持 Custom SSL（对齐 certimate，不额外校验）。
 *
 * config：zone_id（必填）/ certificate_id（选填，填则走更新）/ environment（选填，默认 production）。
 */
class CloudflareSslDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.cloudflare.com/client/v4/';

    public function provider(): string
    {
        return 'cloudflare';
    }

    public function product(): string
    {
        return 'ssl';
    }

    public function label(): string
    {
        return 'Cloudflare SSL';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_id', 'label' => '自定义证书 ID（选填，填则更新已有证书）', 'type' => 'string', 'required' => false],
            ['key' => 'environment', 'label' => '部署环境（选填，默认 production）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_token:string}  $credentials
     * @param  array{zone_id:string,certificate_id?:string,environment?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $zoneId = (string) $this->requireConfig($config, 'zone_id');
        $certificateId = isset($config['certificate_id']) ? (string) $config['certificate_id'] : '';
        $environment = isset($config['environment']) && (string) $config['environment'] !== ''
            ? (string) $config['environment']
            : 'production';
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $body = [
            'certificate' => $fullChain,
            'private_key' => $certRef['key'],
            'bundle_method' => 'ubiquitous',
            'deploy' => $environment,
        ];

        $this->guardSdk(function () use ($credentials, $zoneId, $certificateId, $body) {
            /** @var CloudflareClient $client */
            $client = $this->makeClient('api', $credentials);
            if ($certificateId === '') {
                $client->createCustomCertificate($zoneId, $body);
            } else {
                $client->editCustomCertificate($zoneId, $certificateId, $body);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new CloudflareClient(new GuzzleClient([
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
        return CloudflareErrorSanitizer::sanitize($e);
    }
}

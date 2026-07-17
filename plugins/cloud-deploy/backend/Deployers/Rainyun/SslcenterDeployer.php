<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 雨云 SSL 证书中心（内联型）。
 *
 * 对齐 certimate rainyun-sslcenter：把证书上传/替换到雨云证书中心。
 * - 未填 certificate_id：POST /product/sslcenter/ 新建证书。
 * - 填了 certificate_id：PUT /product/sslcenter/{id} 替换已有证书内容。
 *
 * 内联型（usesRemoteCertStore=false）：证书中心本身即部署目标，bind 直接 create/update，无资源绑定、
 * 无云端证书 id 复用（雨云 create 接口不返回 id，需 list-match 才能取——仅 RCDN 绑定场景需要，见
 * RainyunSslcenterUploader）。鉴权 API Key（api_key 凭证）。
 *
 * config：certificate_id（选填，填则更新，否则新建）。
 */
class SslcenterDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.v2.rainyun.com/';

    public function provider(): string
    {
        return 'rainyun';
    }

    public function product(): string
    {
        return 'sslcenter';
    }

    public function label(): string
    {
        return '雨云 SSL 证书中心';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '证书 ID（选填，填则替换已有证书）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_key:string}  $credentials
     * @param  array{certificate_id?:string|int}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = isset($config['certificate_id']) ? (int) $config['certificate_id'] : 0;
        // cert + chain（完整链）作证书内容
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $certificateId, $fullChain, $key) {
            /** @var RainyunClient $client */
            $client = $this->makeClient('api', $credentials);
            if ($certificateId === 0) {
                $client->sslCenterCreate($fullChain, $key);
            } else {
                $client->sslCenterUpdate($certificateId, $fullChain, $key);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new RainyunClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'X-API-Key' => (string) ($credentials['api_key'] ?? ''),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return RainyunErrorSanitizer::sanitize($e);
    }
}

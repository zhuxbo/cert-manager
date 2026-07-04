<?php

namespace Plugins\CloudDeploy\Deployers\Linode;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Linode 对象存储（内联型）。
 *
 * 对齐 certimate linode-los：把证书配置到指定对象存储桶的 TLS/SSL 证书。流程三步：
 *   1. GET  /object-storage/buckets/{region}/{bucket}/ssl —— 查是否已有证书
 *   2. 若已有则 DELETE 旧证书（Linode 不支持覆盖，须先删后传）
 *   3. POST 上传新证书（certificate=完整链、private_key=私钥）
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，certificate 用 cert+chain 完整链。
 * 鉴权 Bearer Token（access_token 凭证）。
 *
 * 注意：certimate 的 product key 为 `los`（取自其目录 linode-los，对象存储而非负载均衡），照原样保留。
 *
 * config：region_id（必填）/ bucket（必填）。
 */
class LosDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.linode.com/v4/';

    public function provider(): string
    {
        return 'linode';
    }

    public function product(): string
    {
        return 'los';
    }

    public function label(): string
    {
        return 'Linode 对象存储';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region_id', 'label' => '对象存储区域 ID', 'type' => 'string', 'required' => true],
            ['key' => 'bucket', 'label' => '对象存储桶名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{access_token:string}  $credentials
     * @param  array{region_id:string,bucket:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $regionId = (string) $this->requireConfig($config, 'region_id');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $privateKey = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $regionId, $bucket, $fullChain, $privateKey) {
            /** @var LinodeClient $client */
            $client = $this->makeClient('api', $credentials);

            // 已有证书须先删（Linode 不支持覆盖上传）
            if ($client->getObjectStorageSslEnabled($regionId, $bucket)) {
                $client->deleteObjectStorageSsl($regionId, $bucket);
            }

            $client->uploadObjectStorageSsl($regionId, $bucket, [
                'certificate' => $fullChain,
                'private_key' => $privateKey,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new LinodeClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.($credentials['access_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return LinodeErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * SamWaf 证书（内联型）。
 *
 * 对齐 certimate samwaf（deployTarget=certificate）：detail-then-edit 两步替换 SamWaf 已有证书内容。
 *   1. GET /sslconfig/detail?id= 确认目标证书存在（data.id 非空，否则报错 could not find ssl config）。
 *   2. POST /sslconfig/edit 写回新证书（cert_content=完整链、key_content=私钥）。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。鉴权 `X-API-Key` 头。
 * certificate_id 为**字符串**（SamWaf 用字符串 ID，与 SafeLine 的数字 ID 不同）。
 *
 * config：certificate_id（SamWaf 证书 ID 字符串，必填）。server_url / api_key / allow_insecure 归 credentialSchema。
 */
class SamwafDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'samwaf';
    }

    public function product(): string
    {
        return 'samwaf';
    }

    public function label(): string
    {
        return 'SamWaf 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => 'SamWaf 证书 ID', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure?:bool}  $credentials
     * @param  array{certificate_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (string) $this->requireConfig($config, 'certificate_id');
        // 完整链（leaf + 中间证书），与 certimate 传 certPEM 完整链一致
        $fullChain = rtrim($certRef['cert']);
        if (trim($certRef['chain']) !== '') {
            $fullChain .= "\n".trim($certRef['chain']);
        }
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $certificateId, $fullChain, $key) {
            /** @var SamwafClient $client */
            $client = $this->makeClient('api', $credentials);

            // 1. 确认目标证书存在（data.id 非空）
            $detail = $client->getSslConfigDetail($certificateId);
            $foundId = is_string($detail['id'] ?? null) ? $detail['id'] : '';
            if ($detail === null || $foundId === '') {
                throw new SamwafApiException('NotFound', "未找到 SSL 配置: '$certificateId'");
            }

            // 2. 写回新证书内容
            $client->editSslConfig($certificateId, $fullChain, $key);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');

        return match ($kind) {
            'api' => new SamwafClient(new GuzzleClient([
                'base_uri' => "$serverUrl/api/v1/",
                'timeout' => 30,
                'verify' => empty($credentials['allow_insecure']),
                'headers' => [
                    'X-API-Key' => (string) ($credentials['api_key'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return SamwafErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Safeline;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 雷池 WAF（SafeLine）证书（内联型）。
 *
 * 对齐 certimate safeline（deployTarget=certificate）：替换雷池已有证书内容（POST /api/open/cert，type=2 手动证书）。
 * - 完整链（certRef.cert + certRef.chain）→ 请求体 manual.crt
 * - 私钥（certRef.key）→ 请求体 manual.key
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。鉴权 `X-SLCE-API-TOKEN` 头。
 * 替换的是雷池里**已存在**的证书（certificate_id 指向已建条目）。
 *
 * config：certificate_id（雷池证书数字 ID，必填）。server_url / api_token / allow_insecure 归 credentialSchema。
 */
class SafelineDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'safeline';
    }

    public function product(): string
    {
        return 'safeline';
    }

    public function label(): string
    {
        return '雷池 WAF 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '雷池证书 ID', 'type' => 'number', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_token:string,allow_insecure?:bool}  $credentials
     * @param  array{certificate_id:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (int) $this->requireConfig($config, 'certificate_id');
        // 完整链（leaf + 中间证书），与 certimate 传 certPEM 完整链一致
        $fullChain = rtrim($certRef['cert']);
        if (trim($certRef['chain']) !== '') {
            $fullChain .= "\n".trim($certRef['chain']);
        }
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $certificateId, $fullChain, $key) {
            /** @var SafelineClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->updateCertificate($certificateId, $fullChain, $key);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');

        return match ($kind) {
            'api' => new SafelineClient(new GuzzleClient([
                'base_uri' => "$serverUrl/",
                'timeout' => 30,
                'verify' => empty($credentials['allow_insecure']),
                'headers' => [
                    'X-SLCE-API-TOKEN' => (string) ($credentials['api_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return SafelineErrorSanitizer::sanitize($e);
    }
}

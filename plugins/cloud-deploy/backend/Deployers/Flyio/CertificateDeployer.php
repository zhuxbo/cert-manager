<?php

namespace Plugins\CloudDeploy\Deployers\Flyio;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Fly.io 证书（内联型）。
 *
 * 对齐 certimate flyio：把证书导入到指定 Fly.io 应用的自定义证书
 * （POST /apps/{appName}/certificates/custom）。
 * - cert + chain（完整链）→ 请求体 fullchain
 * - key（私钥）→ 请求体 private_key
 * - domain（自定义域名，支持泛域名）→ 请求体 hostname
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，直接导入（上传即部署）。
 * 鉴权 Bearer Token（api_token 凭证）。
 *
 * config：app_name（必填）/ domain（必填）。
 */
class CertificateDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.machines.dev/v1/';

    public function provider(): string
    {
        return 'flyio';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'Fly.io 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'app_name', 'label' => '应用名称', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名（支持泛域名）', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_token:string}  $credentials
     * @param  array{app_name:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $appName = (string) $this->requireConfig($config, 'app_name');
        $domain = (string) $this->requireConfig($config, 'domain');
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $body = [
            'hostname' => $domain,
            'fullchain' => $fullChain,
            'private_key' => $certRef['key'],
        ];

        $this->guardSdk(function () use ($credentials, $appName, $body) {
            /** @var FlyioClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->importCustomCertificate($appName, $body);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new FlyioClient(new GuzzleClient([
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
        return FlyioErrorSanitizer::sanitize($e);
    }
}

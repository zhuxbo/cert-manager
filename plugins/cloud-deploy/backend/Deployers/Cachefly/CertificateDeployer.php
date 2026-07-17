<?php

namespace Plugins\CloudDeploy\Deployers\Cachefly;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * CacheFly 证书（内联型）。
 *
 * 对齐 certimate cachefly：把证书上传到 CacheFly 证书服务（POST /certificates）。
 * - cert + chain（完整链）→ 请求体 certificate
 * - key（私钥）→ 请求体 certificateKey
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，上传即部署（无资源绑定，
 * 与 certimate 一致——上传后 CacheFly 自动按 SNI 匹配服务）。鉴权 Bearer Token（api_token 凭证）。
 *
 * config：[]（无资源配置，纯上传）。
 */
class CertificateDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    private const BASE_URI = 'https://api.cachefly.com/api/2.5/';

    public function provider(): string
    {
        return 'cachefly';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'CacheFly 证书';
    }

    public function configSchema(): array
    {
        return [];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_token:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $body = [
            'certificate' => $fullChain,
            'certificateKey' => $certRef['key'],
        ];

        $this->guardSdk(function () use ($credentials, $body) {
            /** @var CacheflyClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->createCertificate($body);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new CacheflyClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'X-CF-Authorization' => 'Bearer '.($credentials['api_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CacheflyErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Vercel;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Vercel 证书（内联型）。
 *
 * 对齐 certimate vercel：把证书直接上传到 Vercel 证书服务（PUT /v8/certs，skipValidation=true）。
 * - cert（服务器证书 leaf）→ 请求体 cert
 * - chain（中间证书）→ 请求体 ca
 * - key（私钥）→ 请求体 key
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，无资源绑定（上传即部署）。
 * 鉴权 Bearer Token（api_access_token 凭证）；team_id 非空则作 ?teamId= 查询参（团队下操作）。
 *
 * config：[]（无资源配置，纯上传）。team_id 是账号级凭证，归 credentialSchema。
 */
class CertificateDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.vercel.com/v8/';

    public function provider(): string
    {
        return 'vercel';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'Vercel 证书';
    }

    public function configSchema(): array
    {
        return [];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{api_access_token:string,team_id?:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $teamId = isset($credentials['team_id']) ? (string) $credentials['team_id'] : '';

        $body = [
            'ca' => $certRef['chain'],
            'cert' => $certRef['cert'],
            'key' => $certRef['key'],
            'skipValidation' => true,
        ];

        $this->guardSdk(function () use ($credentials, $body, $teamId) {
            /** @var VercelClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->uploadCert($body, $teamId);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new VercelClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.($credentials['api_access_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VercelErrorSanitizer::sanitize($e);
    }
}

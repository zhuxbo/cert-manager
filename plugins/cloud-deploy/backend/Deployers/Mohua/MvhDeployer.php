<?php

namespace Plugins\CloudDeploy\Deployers\Mohua;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 嘿华云虚拟主机（内联型）。
 *
 * 对齐 certimate mohua-mvh：把证书设置到指定虚拟主机的指定域名
 * （POST /provision/custom/{hostId}/domains func=SetSSL）。
 * - cert + chain（完整链）→ sslCert（client 内 url-encode）
 * - key（私钥）→ sslKey（client 内 url-encode）
 * - domain_id → 请求体 id
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，直灌到虚拟主机域名 SSL。
 * 鉴权走「账号 + API 密钥」登录换 JWT（client 内懒登录）。
 *
 * config：host_id（必填）/ domain_id（必填，数字）。
 */
class MvhDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://cloud.mhjz1.cn/';

    public function provider(): string
    {
        return 'mohua';
    }

    public function product(): string
    {
        return 'mvh';
    }

    public function label(): string
    {
        return '嘿华云虚拟主机';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'host_id', 'label' => '虚拟主机 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain_id', 'label' => '域名 ID', 'type' => 'number', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{username:string,api_password:string}  $credentials
     * @param  array{host_id:string,domain_id:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $hostId = (string) $this->requireConfig($config, 'host_id');
        $domainId = (int) $this->requireConfig($config, 'domain_id');
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $hostId, $domainId, $fullChain, $key) {
            /** @var MohuaClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->setVirtualHostSsl($hostId, $domainId, $fullChain, $key);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new MohuaClient(
                new GuzzleClient([
                    'base_uri' => self::BASE_URI,
                    'timeout' => 30,
                    'headers' => ['Accept' => 'application/json'],
                ]),
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['api_password'] ?? ''),
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return MohuaErrorSanitizer::sanitize($e);
    }
}

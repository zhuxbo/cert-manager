<?php

namespace Plugins\CloudDeploy\Deployers\Unicloud;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * uniCloud 托管网站（内联型）。
 *
 * 对齐 certimate unicloud-webhost：把证书变更到指定服务空间的托管网站域名
 * （POST {uniApiBase}/host/create-domain-with-cert）。
 * - cert + chain（完整链）→ 请求体 cert（url-encode）
 * - key（私钥）→ 请求体 key（url-encode）
 * - space_provider → provider；space_id → spaceId；domain → domain
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，直灌到托管网站域名 SSL。
 * 鉴权走「控制台账号 + 密码」两级 token（client 内懒登录）。域名不支持泛域名（对齐 certimate）。
 *
 * config：space_provider（必填，aliyun|alipay|tencent）/ space_id（必填）/ domain（必填）。
 */
class WebhostDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'unicloud';
    }

    public function product(): string
    {
        return 'webhost';
    }

    public function label(): string
    {
        return 'uniCloud 托管网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'space_provider', 'label' => '服务空间提供商（aliyun / alipay / tencent）', 'type' => 'string', 'required' => true],
            ['key' => 'space_id', 'label' => '服务空间 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '托管网站域名（不支持泛域名）', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{username:string,password:string}  $credentials
     * @param  array{space_provider:string,space_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $spaceProvider = (string) $this->requireConfig($config, 'space_provider');
        $spaceId = (string) $this->requireConfig($config, 'space_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        // cert + chain（完整链）；cert/key 经 url-encode（对齐 certimate url.QueryEscape）
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $body = [
            'provider' => $spaceProvider,
            'spaceId' => $spaceId,
            'domain' => $domain,
            'cert' => rawurlencode($fullChain),
            'key' => rawurlencode($certRef['key']),
        ];

        $this->guardSdk(function () use ($credentials, $body) {
            /** @var UnicloudClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->createDomainWithCert($body);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new UnicloudClient(
                new GuzzleClient([
                    'timeout' => 30,
                    'headers' => ['Accept' => 'application/json'],
                ]),
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return UnicloudErrorSanitizer::sanitize($e);
    }
}

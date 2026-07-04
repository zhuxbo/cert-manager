<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 京东云点播 VOD（内联型，直灌 PEM）：先按域名查 domainId，再把证书 PEM + 私钥设到该域名 SSL 配置。
 *
 * 对齐 certimate jdcloud-vod exact 路径：
 *   findDomainIdByDomain（GET /v1/domains 分页，匹配 name → 取 id，int）
 *   → GetHttpSsl（GET /v1/domains/{domainId}:getHttpSsl，取 jumpType）
 *   → SetHttpSsl（POST /v1/domains/{domainId}:setHttpSsl，
 *     body {source=default, title, sslCert, sslKey, jumpType=沿用, enabled=true}）
 *
 * 点播加速域名不支持泛域名，仅 exact（对齐 certimate）；不做 certsan 遍历。
 * usesRemoteCertStore=false → bind 收 {cert,key,chain} 三元组。
 */
class JdcloudVodDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'vod';
    }

    public function label(): string
    {
        return '京东云点播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '点播加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  string|array{cert:string,key:string,chain:string}  $certRef  内联 PEM 三元组
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        if (! is_array($certRef)) {
            $this->fail('京东云点播为内联型，需 PEM 三元组');
        }
        $certPem = rtrim((string) ($certRef['cert'] ?? ''))."\n".trim((string) ($certRef['chain'] ?? ''));
        $keyPem = (string) ($certRef['key'] ?? '');

        // SDK 查询包 guardSdk；"域名未找到" 是业务错误，放 guardSdk 外（否则会被重建成无 previous 的
        // 通用 SDK 异常、丢 DeployBusinessException 类型，CloudDeployJob 会误判为可重试）。
        $domainId = $this->guardSdk(fn (): ?int => $this->makeClient('vod', $credentials)->findVodDomainId($domain));
        if ($domainId === null) {
            $this->fail("未找到点播域名 $domain");
        }

        $this->guardSdk(function () use ($credentials, $domainId, $certPem, $keyPem) {
            /** @var JdcloudRestClient $client */
            $client = $this->makeClient('vod', $credentials);
            $jumpType = $client->getVodHttpSslJumpType($domainId);
            $title = 'clouddeploy-'.(int) (microtime(true) * 1000);
            $client->setVodHttpSsl($domainId, $title, trim($certPem), trim($keyPem), $jumpType);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'vod' => JdcloudClientFactory::vod($credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}

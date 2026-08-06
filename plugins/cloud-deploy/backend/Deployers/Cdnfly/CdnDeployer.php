<?php

namespace Plugins\CloudDeploy\Deployers\Cdnfly;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Cdnfly（自建 CDN 系统，内联型）。
 *
 * 对齐 certimate cdnfly：按 deploy_target 两条业务流程把证书直灌到 Cdnfly 资源——
 *   - deploy_target=website：GET /sites/{siteId} 取原 https_listen → POST /certs 新建证书拿 id →
 *     把 https_listen.cert 设为新证书 id → PUT /sites/{siteId} 更新（沿用原 https_listen 其余字段）。
 *   - deploy_target=certificate：PUT /certs/{certificateId} 直接替换指定证书内容。
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌 Cdnfly 自身证书库，无云端证书去重。
 * 鉴权 API-Key + API-Secret（凭证含自建服务地址 server_url + allow_insecure_connections）。
 *
 * config：deploy_target（必填，website|certificate）/ site_id（website 时必填）/
 *         certificate_id（certificate 时必填）。server_url/api_key/api_secret 归凭证（provider 级）。
 */
class CdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'cdnfly';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'Cdnfly';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标（website 网站 / certificate 证书）', 'type' => 'string', 'required' => true],
            ['key' => 'site_id', 'label' => '网站 ID（部署目标为 website 时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'certificate_id', 'label' => '证书 ID（部署目标为 certificate 时必填）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,api_secret:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{deploy_target:string,site_id?:string,certificate_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $deployTarget = (string) $this->requireConfig($config, 'deploy_target');
        // cert + chain（完整链）作 cert 内容
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $key = $certRef['key'];

        match ($deployTarget) {
            'website' => $this->deployToSite($credentials, $config, $fullChain, $key),
            'certificate' => $this->deployToCertificate($credentials, $config, $fullChain, $key),
            default => $this->fail("不支持的部署目标 '$deployTarget'"),
        };
    }

    /**
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    private function deployToSite(array $credentials, array $config, string $certPem, string $keyPem): void
    {
        $siteId = (string) $this->requireConfig($config, 'site_id');

        $this->guardSdk(function () use ($credentials, $siteId, $certPem, $keyPem) {
            /** @var CdnflyClient $client */
            $client = $this->makeClient('api', $credentials);

            // 取原网站详情（含 https_listen JSON 串）
            $site = $client->getSite($siteId);
            $httpsListenRaw = is_string($site['https_listen'] ?? null) ? $site['https_listen'] : '';
            $httpsListen = $httpsListenRaw !== '' ? json_decode($httpsListenRaw, true) : [];
            $httpsListen = is_array($httpsListen) ? $httpsListen : [];

            // 新建证书拿 id
            $certId = $client->createCert([
                'name' => 'clouddeploy-'.(int) (microtime(true) * 1000),
                'type' => 'custom',
                'cert' => $certPem,
                'key' => $keyPem,
            ]);
            if ($certId === '') {
                $this->fail('Cdnfly 新建证书未返回证书 id');
            }

            // 沿用原 https_listen 其余字段，仅替换 cert
            $httpsListen['cert'] = $certId;
            $client->updateSite($siteId, ['https_listen' => $httpsListen]);
        });
    }

    /**
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    private function deployToCertificate(array $credentials, array $config, string $certPem, string $keyPem): void
    {
        $certificateId = (string) $this->requireConfig($config, 'certificate_id');

        $this->guardSdk(function () use ($credentials, $certificateId, $certPem, $keyPem) {
            /** @var CdnflyClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->updateCert($certificateId, [
                'type' => 'custom',
                'cert' => $certPem,
                'key' => $keyPem,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new CdnflyClient($this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/v1/', [
                'timeout' => 30,
                'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                'headers' => [
                    'API-Key' => (string) ($credentials['api_key'] ?? ''),
                    'API-Secret' => (string) ($credentials['api_secret'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    /** 把凭证里的「允许不安全连接」开关归一为 bool（兼容前端可能传字符串 "1"/"true"）。 */
    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    protected function sanitize(Throwable $e): string
    {
        return CdnflyErrorSanitizer::sanitize($e);
    }
}

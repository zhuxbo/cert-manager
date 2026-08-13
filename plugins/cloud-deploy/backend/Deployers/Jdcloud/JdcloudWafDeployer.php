<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 京东云 WAF（证书服务型）：证书先经 SSL 证书中心上传拿 certId（走 RemoteCertStore 去重），
 * 再绑定到 WAF 实例的防护域名。
 *
 * 对齐 certimate jdcloud-waf：
 *   BindCert（POST /v1/regions/{regionId}/wafInstanceIds/{wafInstanceId}/cert:bindCert，
 *     body {req:{wafInstanceId, domain, certId}}）—— regionId / wafInstanceId 为 path 参数，
 *     req.wafInstanceId 与 path 重复（对齐 certimate 原样）。
 *
 * 防护域名不支持泛域名，仅 exact（对齐 certimate）。
 */
class JdcloudWafDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'waf';
    }

    public function label(): string
    {
        return '京东云 WAF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region_id', 'label' => '地域 ID', 'type' => 'string', 'required' => true],
            ['key' => 'instance_id', 'label' => 'WAF 实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '防护域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new JdcloudSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  云端 certId（SSL 证书中心上传所得）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{region_id:string,instance_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $regionId = (string) $this->requireConfig($config, 'region_id');
        $instanceId = (string) $this->requireConfig($config, 'instance_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $regionId, $instanceId, $domain, $certId) {
            /** @var JdcloudRestClient $client */
            $client = $this->makeClient('waf', $credentials);
            $client->bindWafCert($regionId, $instanceId, $domain, $certId);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'ssl' => JdcloudClientFactory::ssl($credentials),
            'waf' => JdcloudClientFactory::waf($credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Ratpanel;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 耗子面板 控制台证书（内联型，product=console）。
 *
 * 对齐 certimate ratpanel-console：替换面板控制台自身的 SSL 证书（POST /setting/cert）。
 * - 完整链（certRef.cert + certRef.chain）→ 请求体 cert
 * - 私钥（certRef.key）→ 请求体 key
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。鉴权 HMAC-SHA256 签名。
 * 无部署配置（控制台证书唯一，无需 domain/site）。凭证全归 credentialSchema。
 */
class RatpanelConsoleDeployer extends AbstractDeployer
{
    use BuildsRatpanelClient;

    public function provider(): string
    {
        return 'ratpanel';
    }

    public function product(): string
    {
        return 'console';
    }

    public function label(): string
    {
        return '耗子面板 控制台';
    }

    public function configSchema(): array
    {
        return [];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // 完整链（leaf + 中间证书），与 certimate 传 certPEM 完整链一致
        $fullChain = rtrim($certRef['cert']);
        if (trim($certRef['chain']) !== '') {
            $fullChain .= "\n".trim($certRef['chain']);
        }
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $fullChain, $key) {
            $this->makeClient('api', $credentials)->setSettingCert($fullChain, $key);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => $this->makeRatpanelClient($credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return RatpanelErrorSanitizer::sanitize($e);
    }
}

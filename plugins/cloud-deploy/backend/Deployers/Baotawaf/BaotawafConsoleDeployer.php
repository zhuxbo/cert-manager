<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 堡塔云 WAF 控制台 SSL（内联型）。
 *
 * 对齐 certimate baotawaf-console：设置堡塔云 WAF 面板自身的 HTTPS 证书。
 * - config.SetCert（JSON {certContent, keyContent}）。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，certContent 用完整链（叶子 + 中间）。
 * provider key 'baotawaf'、product key 'console'。
 * config：无（面板 SSL 设置无额外参数）。
 */
class BaotawafConsoleDeployer extends AbstractDeployer
{
    use BuildsBaotawafClient;

    public function provider(): string
    {
        return 'baotawaf';
    }

    public function product(): string
    {
        return 'console';
    }

    public function label(): string
    {
        return '堡塔云 WAF 面板';
    }

    public function configSchema(): array
    {
        return [];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure?:mixed}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);

        $this->guardSdk(function () use ($credentials, $certRef, $fullChainPEM) {
            /** @var BaotawafClient $client */
            $client = $this->makeClient('api', $credentials);

            $client->configSetCert($fullChainPEM, $certRef['key']);
        });
    }

    protected function sanitize(Throwable $e): string
    {
        return BaotawafErrorSanitizer::sanitize($e);
    }
}

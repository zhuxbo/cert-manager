<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 1Panel 面板控制台 SSL（内联型）。
 *
 * 对齐 certimate 1panel-console：设置 1Panel 面板自身的 HTTPS 证书。
 * - v1：POST /settings/ssl/update，{cert, key, ssl:"enable", sslType:"import-paste", autoRestart}
 * - v2：POST /core/settings/ssl/update，{cert, key, ssl:"Enable", sslType:"import-paste", autoRestart}
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，cert 用完整链（叶子 + 中间）。
 * provider key 'onepanel'、product key 'console'。
 * config：auto_restart（选填，是否自动重启，默认 false）。
 */
class OnepanelConsoleDeployer extends AbstractDeployer
{
    use BuildsOnepanelClient;

    public function provider(): string
    {
        return 'onepanel';
    }

    public function product(): string
    {
        return 'console';
    }

    public function label(): string
    {
        return '1Panel 面板';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'auto_restart', 'label' => '自动重启面板', 'type' => 'bool', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_version:string,api_key:string,node_name?:string,allow_insecure?:mixed}  $credentials
     * @param  array{auto_restart?:mixed}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $autoRestart = $this->truthy($config['auto_restart'] ?? null) ? 'true' : 'false';

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);

        $this->guardSdk(function () use ($credentials, $certRef, $fullChainPEM, $autoRestart) {
            /** @var OnepanelClient $client */
            $client = $this->makeClient('api', $credentials);

            $client->updatePanelSSL([
                'cert' => $fullChainPEM,
                'key' => $certRef['key'],
                'sslType' => 'import-paste',
                'ssl' => $client->isV2() ? 'Enable' : 'enable',
                'autoRestart' => $autoRestart,
            ]);
        });
    }

    protected function sanitize(Throwable $e): string
    {
        return OnepanelErrorSanitizer::sanitize($e);
    }
}

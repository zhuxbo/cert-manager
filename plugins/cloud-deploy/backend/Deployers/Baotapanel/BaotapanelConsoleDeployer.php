<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 宝塔面板控制台 SSL（内联型）。
 *
 * 对齐 certimate baotapanel-console：设置宝塔面板自身的 HTTPS 证书。
 * - config.SavePanelSSL（表单 privateKey/certPem）。
 * - auto_restart=true 时额外 system.ServiceAdmin(name=nginx, type=restart)；重启会断连产生 error，
 *   按 certimate 约定吞掉该异常（不影响部署结果）。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，证书用完整链（叶子 + 中间）。
 * provider key 'baotapanel'、product key 'console'。
 * config：auto_restart（选填，是否重启 nginx，默认 false）。
 */
class BaotapanelConsoleDeployer extends AbstractDeployer
{
    use BuildsBaotapanelClient;

    public function provider(): string
    {
        return 'baotapanel';
    }

    public function product(): string
    {
        return 'console';
    }

    public function label(): string
    {
        return '宝塔面板';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'auto_restart', 'label' => '自动重启 nginx', 'type' => 'bool', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure?:mixed}  $credentials
     * @param  array{auto_restart?:mixed}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $autoRestart = $this->truthy($config['auto_restart'] ?? null);

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);

        $this->guardSdk(function () use ($credentials, $certRef, $fullChainPEM, $autoRestart) {
            /** @var BaotapanelClient $client */
            $client = $this->makeClient('api', $credentials);

            $client->configSavePanelSSL($fullChainPEM, $certRef['key']);

            if ($autoRestart) {
                // 重启面板会断连产生 error，按 certimate 约定吞掉（无需关心响应）
                try {
                    $client->systemServiceAdmin('nginx', 'restart');
                } catch (Throwable) {
                    // ignored
                }
            }
        });
    }

    protected function sanitize(Throwable $e): string
    {
        return BaotapanelErrorSanitizer::sanitize($e);
    }
}

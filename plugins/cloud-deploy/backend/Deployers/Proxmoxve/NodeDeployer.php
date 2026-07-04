<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxve;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Proxmox VE（内联型）—— 上传节点自定义证书。
 *
 * 对齐 certimate proxmoxve：把证书直灌到指定集群节点——
 *   POST /nodes/{nodeName}/certificates/custom {certificates=完整链, key, force:true, restart}
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌节点自定义证书，无云端证书去重。
 * force 固定 true（覆盖已有）；restart 由 config.auto_restart 决定（是否上传后重启 pveproxy）。
 * 鉴权 Authorization: PVEAPIToken={api_token}={api_token_secret}（凭证含自建服务地址 server_url）。
 *
 * config：node_name（必填，集群节点名称）/ auto_restart（选填，bool，是否自动重启）。
 */
class NodeDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'proxmoxve';
    }

    public function product(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return 'Proxmox VE 节点';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'node_name', 'label' => '集群节点名称', 'type' => 'string', 'required' => true],
            ['key' => 'auto_restart', 'label' => '上传后自动重启（选填）', 'type' => 'bool', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_token:string,api_token_secret:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{node_name:string,auto_restart?:bool|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $nodeName = (string) $this->requireConfig($config, 'node_name');
        $autoRestart = $this->truthy($config['auto_restart'] ?? false);
        // cert + chain（完整链）作 certificates 内容
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $this->guardSdk(function () use ($credentials, $nodeName, $fullChain, $certRef, $autoRestart) {
            /** @var ProxmoxveClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->nodeUploadCustomCertificate($nodeName, [
                'certificates' => $fullChain,
                'key' => $certRef['key'],
                'force' => true,
                'restart' => $autoRestart,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $tokenId = (string) ($credentials['api_token'] ?? '');
        $tokenSecret = (string) ($credentials['api_token_secret'] ?? '');

        return match ($kind) {
            'api' => new ProxmoxveClient(
                new GuzzleClient([
                    'base_uri' => rtrim((string) ($credentials['server_url'] ?? ''), '/').'/api2/json/',
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'headers' => [
                        'Authorization' => "PVEAPIToken=$tokenId=$tokenSecret",
                        'Accept' => 'application/json',
                    ],
                ]),
            ),
        };
    }

    /** 归一 bool 开关（兼容前端可能传字符串 "1"/"true"）。 */
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
        return ProxmoxveErrorSanitizer::sanitize($e);
    }
}

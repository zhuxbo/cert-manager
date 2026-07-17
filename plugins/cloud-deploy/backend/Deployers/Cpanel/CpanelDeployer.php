<?php

namespace Plugins\CloudDeploy\Deployers\Cpanel;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * cPanel 网站证书（内联型）。
 *
 * 对齐 certimate cpanel（deployTarget=website）：把证书安装到指定域名的站点（UAPI SSL::install_ssl）。
 * - 服务器证书 leaf（certRef.cert）→ 查询参 cert
 * - 私钥（certRef.key）→ 查询参 key
 * - 中间证书（certRef.chain）→ 查询参 cabundle
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组（插件上游已拆好 leaf/chain，
 * 无需再分链）。鉴权 `Authorization: cpanel <username>:<apiToken>` 头。不支持泛域名（对齐 certimate）。
 *
 * config：domain（站点域名，必填）。server_url / username / api_token / allow_insecure 归 credentialSchema。
 */
class CpanelDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'cpanel';
    }

    public function product(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return 'cPanel 网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '站点域名（不支持泛域名）', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,username:string,api_token:string,allow_insecure?:bool}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $serverCert = trim($certRef['cert']);
        $privateKey = $certRef['key'];
        $caBundle = trim($certRef['chain']);

        $this->guardSdk(function () use ($credentials, $domain, $serverCert, $privateKey, $caBundle) {
            /** @var CpanelClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->installSsl($domain, $serverCert, $privateKey, $caBundle);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');
        $username = (string) ($credentials['username'] ?? '');
        $apiToken = (string) ($credentials['api_token'] ?? '');

        return match ($kind) {
            'api' => new CpanelClient(new GuzzleClient([
                'base_uri' => "$serverUrl/execute/",
                'timeout' => 30,
                'verify' => empty($credentials['allow_insecure']),
                'headers' => [
                    // cPanel UAPI 令牌鉴权：scheme 词 cpanel + 空格 + user:token（非 Basic/Bearer）
                    'Authorization' => "cpanel $username:$apiToken",
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CpanelErrorSanitizer::sanitize($e);
    }
}

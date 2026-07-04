<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 堡塔云 WAF 网站证书（内联型）。
 *
 * 对齐 certimate baotawaf：把证书部署到堡塔云 WAF 的一个或多个站点。
 * - 分页 wafmastersite.GetSiteList(site_name) 按名称精确匹配拿 site_id。
 * - wafmastersite.ModifySite(types=openCert, server.listen_ssl_port=[port], server.ssl.{is_ssl:1, full_chain, private_key})。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，full_chain 用完整链（叶子 + 中间）。
 * provider key 'baotawaf'、product key 'site'。
 * config：site_names（必填，逗号或换行分隔）/ site_port（选填，SSL 端口，默认 443）。
 */
class BaotawafSiteDeployer extends AbstractDeployer
{
    use BuildsBaotawafClient;

    private const PAGE_SIZE = 100;

    public function provider(): string
    {
        return 'baotawaf';
    }

    public function product(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return '堡塔云 WAF 网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'site_names', 'label' => '网站名称（逗号或换行分隔）', 'type' => 'string', 'required' => true],
            ['key' => 'site_port', 'label' => 'SSL 端口（默认 443）', 'type' => 'number', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure?:mixed}  $credentials
     * @param  array{site_names:string,site_port?:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $siteNames = $this->parseList((string) $this->requireConfig($config, 'site_names'));
        if ($siteNames === []) {
            $this->fail('网站名称 site_names 不能为空');
        }
        $sitePort = isset($config['site_port']) && (int) $config['site_port'] > 0 ? (int) $config['site_port'] : 443;

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);
        $privkeyPEM = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $siteNames, $sitePort, $fullChainPEM, $privkeyPEM) {
            /** @var BaotawafClient $client */
            $client = $this->makeClient('api', $credentials);

            foreach ($siteNames as $siteName) {
                $siteId = $this->findSiteId($client, $siteName);
                $client->modifySiteCertificate($siteId, $sitePort, $fullChainPEM, $privkeyPEM);
            }
        });
    }

    /**
     * 按名称分页精确查找 site_id。
     */
    private function findSiteId(BaotawafClient $client, string $siteName): string
    {
        $page = 1;
        while (true) {
            $list = $client->getSiteList($siteName, $page, self::PAGE_SIZE);

            foreach ($list as $item) {
                if (($item['site_name'] ?? null) === $siteName) {
                    return (string) ($item['site_id'] ?? '');
                }
            }

            if (count($list) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        throw new BaotawafApiException('SiteNotFound', "未找到站点: $siteName");
    }

    /**
     * 把逗号 / 换行分隔的站点名解析为去空白、去空项的 list。
     *
     * @return list<string>
     */
    private function parseList(string $raw): array
    {
        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    protected function sanitize(Throwable $e): string
    {
        return BaotawafErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 宝塔面板网站证书（内联型）。
 *
 * 对齐 certimate baotapanel：把证书部署到宝塔面板的一个或多个站点。
 * - site_type=any：批量部署——先走 v1（ssl.cert.SaveCert → ssl.SetBatchCertToSite），若 v1 失败则
 *   回退 v2（v2.ssldomain.UploadCert → v2.ssldomain.CertDeploySites）。
 * - site_type=proxy：对每个站点调 mod.proxy.com.SetSSL。
 * - site_type 其他（php/java/nodejs/go/python/html/general 或空）：对每个站点调 site.SetSSL。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，证书用完整链（叶子 + 中间）。
 * provider key 'baotapanel'、product key 'site'。
 * config：site_type（选填，默认空=普通站点）/ site_names（必填，逗号或换行分隔的站点名）。
 */
class BaotapanelSiteDeployer extends AbstractDeployer
{
    use BuildsBaotapanelClient;

    /** 受支持的站点类型（空=普通站点，默认走 site.SetSSL）。 */
    private const SITE_TYPES = ['php', 'java', 'nodejs', 'go', 'python', 'proxy', 'html', 'general'];

    public function provider(): string
    {
        return 'baotapanel';
    }

    public function product(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return '宝塔面板网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'site_type', 'label' => '网站类型（any=批量；proxy=反代；空=普通）', 'type' => 'string', 'required' => false],
            ['key' => 'site_names', 'label' => '网站名称（逗号或换行分隔）', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure?:mixed}  $credentials
     * @param  array{site_type?:string,site_names:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $siteType = isset($config['site_type']) ? (string) $config['site_type'] : '';
        $siteNames = $this->parseList((string) $this->requireConfig($config, 'site_names'));
        if ($siteNames === []) {
            $this->fail('网站名称 site_names 不能为空');
        }
        if ($siteType !== '' && $siteType !== 'any' && ! in_array($siteType, self::SITE_TYPES, true)) {
            $this->fail("不支持的网站类型: $siteType");
        }

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);
        $privkeyPEM = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $siteType, $siteNames, $fullChainPEM, $privkeyPEM) {
            /** @var BaotapanelClient $client */
            $client = $this->makeClient('api', $credentials);

            if ($siteType === 'any') {
                $this->deployToAny($client, $siteNames, $fullChainPEM, $privkeyPEM);

                return;
            }

            foreach ($siteNames as $siteName) {
                if ($siteType === 'proxy') {
                    $client->modProxyComSetSSL($siteName, $fullChainPEM, $privkeyPEM);
                } else {
                    $client->siteSetSSL($siteName, $fullChainPEM, $privkeyPEM);
                }
            }
        });
    }

    /**
     * any 批量：先 v1，失败回退 v2（对齐 certimate updateSitesCertificateByAny → ...ByAnyV2）。
     *
     * @param  list<string>  $siteNames
     */
    private function deployToAny(BaotapanelClient $client, array $siteNames, string $certPEM, string $privkeyPEM): void
    {
        try {
            $sslHash = $client->sslCertSaveCert($certPEM, $privkeyPEM);
            $client->sslSetBatchCertToSite($sslHash, $siteNames);
        } catch (BaotapanelApiException) {
            // v1 不可用，回退 v2
            $sslHash = $client->sslDomainUploadCertV2($certPEM, $privkeyPEM);
            $client->sslDomainCertDeploySitesV2($sslHash, $siteNames);
        }
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
        return BaotapanelErrorSanitizer::sanitize($e);
    }
}

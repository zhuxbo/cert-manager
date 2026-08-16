<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanelgo;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 宝塔面板（Windows Go 版）网站证书（内联型）。
 *
 * 对齐 certimate baotapanelgo：把证书部署到宝塔（Windows）面板的一个或多个站点。
 * - panel.GetConfig 检测 WebServer 类型。
 * - 按站点类型查找站点：空类型 / IIS 类型（php/asp/aspx）走 datalist.GetDataList(table=sites)；
 *   其他类型走 site.GetProjectList(search_type=类型)，按名称精确匹配拿 site id。
 * - 非 IIS 服务器：site.SetSiteSSL(siteid, status=1, key, cert) 直灌 PEM。
 * - IIS 服务器：PEM→PFX 后经 files.Upload 上传，再由 site.SetSitePFXSSL 应用。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，证书用完整链（叶子 + 中间）。
 * provider key 'baotapanelgo'、product key 'site'。
 * config：site_type（选填，空=按数据列表查找）/ site_names（必填，逗号或换行分隔）。
 */
class BaotapanelgoSiteDeployer extends AbstractDeployer
{
    use BuildsBaotapanelgoClient;

    private const PAGE_LIMIT = 10;

    /** 受支持的（非 IIS）站点类型。 */
    private const SITE_TYPES = ['php', 'java', 'asp', 'go', 'python', 'nodejs', 'proxy', 'general'];

    /** IIS 下站点类型（findSiteByName 走 datalist 而非 get_project_list）。 */
    private const SITE_TYPES_IIS = ['php', 'asp', 'aspx'];

    public function provider(): string
    {
        return 'baotapanelgo';
    }

    public function product(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return '宝塔面板（Windows）网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'site_type', 'label' => '网站类型（空=按数据列表查找）', 'type' => 'string', 'required' => false],
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
        if ($siteType !== '' && ! in_array($siteType, self::SITE_TYPES, true) && ! in_array($siteType, self::SITE_TYPES_IIS, true)) {
            $this->fail("不支持的网站类型: $siteType");
        }

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);
        $privkeyPEM = $certRef['key'];

        // 先检测 WebServer 类型与面板路径（SDK 调用），再按类型选择 PEM/PFX 流程。
        $panelConfig = $this->guardSdk(function () use ($credentials): array {
            /** @var BaotapanelgoClient $client */
            $client = $this->makeClient('api', $credentials);

            return $client->panelGetConfig();
        });
        $siteConfig = is_array($panelConfig['site'] ?? null) ? $panelConfig['site'] : [];
        $webServer = strtolower(is_string($siteConfig['webserver'] ?? null) ? $siteConfig['webserver'] : '');
        $paths = is_array($panelConfig['paths'] ?? null) ? $panelConfig['paths'] : [];
        $softPath = rtrim((string) ($paths['soft'] ?? ''), '/\\');

        $this->guardSdk(function () use ($credentials, $siteType, $siteNames, $fullChainPEM, $privkeyPEM, $webServer, $softPath) {
            /** @var BaotapanelgoClient $client */
            $client = $this->makeClient('api', $credentials);

            foreach ($siteNames as $siteName) {
                $siteId = $this->findSiteId($client, $siteType, $siteName);
                if ($webServer === 'iis') {
                    if ($softPath === '') {
                        throw new BaotapanelgoApiException('InvalidConfig', '宝塔面板未返回 paths.soft');
                    }
                    $password = 'certimate';
                    $pfx = $this->buildPfx($fullChainPEM, $privkeyPEM, $password);
                    $directory = $softPath.'/temp/ssl/certimate';
                    $filename = hash('sha256', $pfx).'.pfx';
                    $client->filesUpload($directory, $filename, $pfx, true);
                    $client->siteSetSitePfxSsl($siteId, $directory.'/'.$filename, $password);
                } else {
                    $client->siteSetSiteSSL($siteId, true, $fullChainPEM, $privkeyPEM);
                }
            }
        });
    }

    protected function buildPfx(string $certificate, string $privateKey, string $password): string
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate, $matches);
        $certificates = $matches[0];
        if ($certificates === []) {
            throw new BaotapanelgoApiException('PfxConversionFailed', '证书转换为 PFX 失败：未找到证书');
        }

        $output = '';
        $options = count($certificates) > 1 ? ['extracerts' => array_slice($certificates, 1)] : [];
        if (! openssl_pkcs12_export($certificates[0], $output, $privateKey, $password, $options)) {
            throw new BaotapanelgoApiException('PfxConversionFailed', '证书转换为 PFX 失败');
        }

        return $output;
    }

    /**
     * 按名称查找站点 ID（空类型/IIS 类型走 datalist；其它走 get_project_list），分页精确匹配（大小写不敏感）。
     */
    private function findSiteId(BaotapanelgoClient $client, string $siteType, string $siteName): int
    {
        $useDatalist = $siteType === '' || in_array($siteType, self::SITE_TYPES_IIS, true);

        $page = 1;
        while (true) {
            $items = $useDatalist
                ? $client->datalistGetDataList('sites', $siteName, $page, self::PAGE_LIMIT)
                : $client->siteGetProjectList($siteType, $siteName, $page, self::PAGE_LIMIT);

            foreach ($items as $item) {
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';
                if (strcasecmp($name, $siteName) === 0) {
                    return (int) ($item['id'] ?? 0);
                }
            }

            if (count($items) < self::PAGE_LIMIT) {
                break;
            }
            $page++;
        }

        throw new BaotapanelgoApiException('SiteNotFound', "未找到站点: $siteName");
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
        return BaotapanelgoErrorSanitizer::sanitize($e);
    }
}

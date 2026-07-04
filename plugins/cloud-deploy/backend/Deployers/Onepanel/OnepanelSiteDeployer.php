<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 1Panel 网站 / 证书（内联型）。
 *
 * 对齐 certimate 1panel：把证书部署到 1Panel 的网站或替换指定证书。
 * - deploy_target=website：
 *     1. 上传证书到 1Panel 证书库（先按内容去重，命中则复用其 ID；否则上传后再查回 ID）。
 *     2. 解析待部署网站 ID：
 *        - website_match_pattern=specified（默认）：用 website_id。
 *        - website_match_pattern=certsan：分页拉网站，按证书 SAN 匹配 primaryDomain + 网站 domains。
 *     3. 逐个网站：取 HTTPS 配置，若已启用且证书相同则跳过，否则 POST 绑定证书（保留原 HTTPS 配置项）。
 * - deploy_target=certificate：用 certificate_id 直接替换证书内容（WebsiteSSLUpload 带 sslID）。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain}，证书内容用完整链（叶子 + 中间）。
 * provider key 'onepanel'、product key 'site'。
 * config：deploy_target（website/certificate，必填）/ website_match_pattern（specified/certsan，选填）
 *   / website_id（specified 时必填）/ certificate_id（certificate 时必填）。
 */
class OnepanelSiteDeployer extends AbstractDeployer
{
    use BuildsOnepanelClient;
    use MatchesCertificateHostname;

    private const SSL_SEARCH_PAGE_SIZE = 100;

    private const WEBSITE_SEARCH_PAGE_SIZE = 100;

    public function provider(): string
    {
        return 'onepanel';
    }

    public function product(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return '1Panel 网站';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '网站', 'value' => 'website'],
                ['label' => '证书（替换指定证书）', 'value' => 'certificate'],
            ]],
            ['key' => 'website_match_pattern', 'label' => '网站匹配模式（部署目标为网站时）', 'type' => 'string', 'required' => false, 'options' => [
                ['label' => '指定网站 ID', 'value' => 'specified'],
                ['label' => '证书 SAN 匹配', 'value' => 'certsan'],
            ]],
            ['key' => 'website_id', 'label' => '网站 ID（指定网站时必填）', 'type' => 'number', 'required' => false],
            ['key' => 'certificate_id', 'label' => '证书 ID（部署目标为证书时必填）', 'type' => 'number', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_version:string,api_key:string,node_name?:string,allow_insecure?:mixed}  $credentials
     * @param  array{deploy_target:string,website_match_pattern?:string,website_id?:int|string,certificate_id?:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $deployTarget = (string) $this->requireConfig($config, 'deploy_target');

        $serverCertPEM = trim($certRef['cert']);
        $intermediaPEM = trim($certRef['chain']);
        $fullChainPEM = $intermediaPEM === '' ? $serverCertPEM : ($serverCertPEM."\n".$intermediaPEM);
        $privkeyPEM = $certRef['key'];

        if ($deployTarget === 'website') {
            $matchPattern = isset($config['website_match_pattern']) && (string) $config['website_match_pattern'] !== ''
                ? (string) $config['website_match_pattern']
                : 'specified';

            // specified 模式：requireConfig website_id 须在任何 SDK 调用前（供契约测试记账）
            $specifiedWebsiteId = $matchPattern === 'specified'
                ? (int) $this->requireConfig($config, 'website_id')
                : 0;

            $this->guardSdk(function () use ($credentials, $fullChainPEM, $privkeyPEM, $serverCertPEM, $matchPattern, $specifiedWebsiteId) {
                /** @var OnepanelClient $client */
                $client = $this->makeClient('api', $credentials);

                // 1. 上传（去重）拿证书 ID
                $sslId = $this->uploadCertificate($client, $fullChainPEM, $privkeyPEM);

                // 2. 解析待部署网站 ID
                $websiteIds = $matchPattern === 'certsan'
                    ? $this->matchWebsiteIdsByCertSan($client, $serverCertPEM)
                    : [$specifiedWebsiteId];

                // 3. 逐个网站绑定
                foreach ($websiteIds as $websiteId) {
                    $this->updateWebsiteCertificate($client, $websiteId, $sslId);
                }
            });

            return;
        }

        if ($deployTarget === 'certificate') {
            $certificateId = (int) $this->requireConfig($config, 'certificate_id');

            $this->guardSdk(function () use ($credentials, $fullChainPEM, $privkeyPEM, $certificateId) {
                /** @var OnepanelClient $client */
                $client = $this->makeClient('api', $credentials);

                // 替换指定证书内容（带 sslID）
                $client->uploadWebsiteSSL([
                    'sslID' => $certificateId,
                    'type' => 'paste',
                    'description' => 'upload from cloud-deploy',
                    'certificate' => $fullChainPEM,
                    'privateKey' => $privkeyPEM,
                ]);
            });

            return;
        }

        $this->fail("不支持的部署目标: $deployTarget");
    }

    /**
     * 上传证书（先按内容去重，命中复用 ID；否则上传后再查回 ID）。
     */
    private function uploadCertificate(OnepanelClient $client, string $certPEM, string $privkeyPEM): int
    {
        $existingId = $this->findCertificateIdByContent($client, $certPEM, $privkeyPEM);
        if ($existingId !== null) {
            return $existingId;
        }

        // 生成符合 1Panel 命名规则的证书名
        $certName = 'cloud-deploy-'.(int) (microtime(true) * 1000);
        $client->uploadWebsiteSSL([
            'type' => 'paste',
            'description' => $certName,
            'certificate' => $certPEM,
            'privateKey' => $privkeyPEM,
        ]);

        $newId = $this->findCertificateIdByContent($client, $certPEM, $privkeyPEM);
        if ($newId === null) {
            throw new OnepanelApiException('UploadFailed', '上传证书后未能查到证书 ID，可能上传失败');
        }

        return $newId;
    }

    /**
     * 分页搜索证书库，按内容（去空白后 cert + privkey 全等）匹配，返回证书 ID。无则 null。
     */
    private function findCertificateIdByContent(OnepanelClient $client, string $certPEM, string $privkeyPEM): ?int
    {
        $wantCert = $this->stripPem($certPEM);
        $wantKey = $this->stripPem($privkeyPEM);

        $page = 1;
        while (true) {
            $resp = $client->searchWebsiteSSL([
                'page' => $page,
                'pageSize' => self::SSL_SEARCH_PAGE_SIZE,
            ]);
            $items = $resp['items'];

            foreach ($items as $item) {
                $itemCert = $this->stripPem(is_string($item['pem'] ?? null) ? $item['pem'] : '');
                $itemKey = $this->stripPem(is_string($item['privateKey'] ?? null) ? $item['privateKey'] : '');
                if ($itemCert === $wantCert && $itemKey === $wantKey) {
                    return (int) ($item['id'] ?? 0);
                }
            }

            if (count($items) < self::SSL_SEARCH_PAGE_SIZE || $page * self::SSL_SEARCH_PAGE_SIZE >= $resp['total']) {
                break;
            }
            $page++;
        }

        return null;
    }

    /**
     * CERTSAN 模式：分页拉网站，按证书覆盖 primaryDomain 或网站 domains 收集网站 ID。
     *
     * @return list<int>
     */
    private function matchWebsiteIdsByCertSan(OnepanelClient $client, string $serverCertPEM): array
    {
        $websiteIds = [];
        $page = 1;
        while (true) {
            $resp = $client->searchWebsites([
                'order' => 'ascending',
                'orderBy' => 'primary_domain',
                'page' => $page,
                'pageSize' => self::WEBSITE_SEARCH_PAGE_SIZE,
            ]);
            $items = $resp['items'];

            foreach ($items as $website) {
                $primaryDomain = is_string($website['primaryDomain'] ?? null) ? $website['primaryDomain'] : '';
                if (! $this->certMatchesHostname($serverCertPEM, $primaryDomain)) {
                    continue;
                }

                $websiteId = (int) ($website['id'] ?? 0);
                if ($websiteId === 0) {
                    continue;
                }

                $detail = $client->getWebsite($websiteId);
                $domains = is_array($detail['domains'] ?? null) ? $detail['domains'] : [];
                foreach ($domains as $domainInfo) {
                    if (! is_array($domainInfo)) {
                        continue;
                    }
                    $ssl = (bool) ($domainInfo['ssl'] ?? false);
                    $domain = is_string($domainInfo['domain'] ?? null) ? $domainInfo['domain'] : '';
                    if ($ssl || $this->certMatchesHostname($serverCertPEM, $domain)) {
                        $websiteIds[] = $websiteId;
                        break;
                    }
                }
            }

            if (count($items) < self::WEBSITE_SEARCH_PAGE_SIZE) {
                break;
            }
            $page++;
        }

        if ($websiteIds === []) {
            throw new OnepanelApiException('NoWebsiteMatched', '未找到证书覆盖的网站');
        }

        return $websiteIds;
    }

    /**
     * 更新单个网站的 HTTPS 证书：已启用且 SSL ID 相同则跳过；否则保留原 HTTPS 配置项 POST 绑定。
     */
    private function updateWebsiteCertificate(OnepanelClient $client, int $websiteId, int $sslId): void
    {
        $https = $client->getWebsiteHttps($websiteId);
        $enabled = (bool) ($https['enable'] ?? false);
        $currentSslId = (int) ($https['websiteSSLId'] ?? 0);
        if ($enabled && $currentSslId === $sslId) {
            return;
        }

        $httpConfig = is_string($https['httpConfig'] ?? null) && $https['httpConfig'] !== '' ? $https['httpConfig'] : 'HTTPToHTTPS';
        $sslProtocol = is_array($https['SSLProtocol'] ?? null) ? array_values($https['SSLProtocol']) : [];
        $algorithm = is_string($https['algorithm'] ?? null) ? $https['algorithm'] : '';

        $body = [
            'websiteId' => $websiteId,
            'enable' => true,
            'type' => 'existed',
            'websiteSSLId' => $sslId,
            'httpConfig' => $httpConfig,
            'SSLProtocol' => $sslProtocol,
            'algorithm' => $algorithm,
            'hsts' => (bool) ($https['hsts'] ?? false),
        ];
        if ($client->isV2()) {
            $body['http3'] = (bool) ($https['http3'] ?? false);
        }

        $client->postWebsiteHttps($websiteId, $body);
    }

    /** 去 CR/LF/首尾空白，用于证书内容全等比较（对齐 certimate dedup）。 */
    private function stripPem(string $pem): string
    {
        return trim(str_replace(["\r", "\n"], '', $pem));
    }

    protected function sanitize(Throwable $e): string
    {
        return OnepanelErrorSanitizer::sanitize($e);
    }
}

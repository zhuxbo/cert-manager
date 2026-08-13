<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 天翼云视频直播加速（LVDN，证书服务型，仅 exact 域名匹配）。
 *
 * product key 用 `lvdn`（注意 certimate provider.go 的枚举字符串写作 `ctcccloud-ldvn` 是上游笔误，目录/包/本实现
 * 一律用 `lvdn`）。对齐 certimate ctcccloud-lvdn 的 Deploy（DOMAIN_MATCH_PATTERN_EXACT）：
 *   1. 创建证书到 LVDN 证书空间拿 CertName（store_kind=ctcccloud_lvdn，POST /cert/creat-cert —— 注意无 /v1 前缀）。
 *   2. bind 精确匹配 config.domain：
 *      - GET /live/domain/query-domain-detail?domain={domain}&product_code=005。
 *      - POST /live/domain/update-domain {domain, product_code:"005", https_switch:1, cert_name=CertName}。
 *
 * 与 CDN/ICDN 的差异：LVDN 用 product_code="005" + https_switch(int 1) 而非 https_status("on")，且路径前缀为 /live、
 * create-cert 无 /v1 前缀（对齐各 SDK api 文件）。endpoint host：ctlvdn-global.ctapi.ctyun.cn。仅 exact。
 */
class CtcccloudLvdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCtcccloudDomains;

    /** LVDN 产品码（对齐 certimate，固定 005）。 */
    private const PRODUCT_CODE = '005';

    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'lvdn';
    }

    public function label(): string
    {
        return '天翼云视频直播加速';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new CtcccloudCertCreateUploader(
            fn (array $credentials): object => $this->makeClient('lvdn', $credentials),
            'ctcccloud_lvdn',
            '/cert/creat-cert',
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（LVDN CertName）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $certName = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $pattern, $certificate, $certName) {
            /** @var CtcccloudRestClient $client */
            $client = $this->makeClient('lvdn', $credentials);

            foreach ($this->matchingDomains($client, '/domain/query-domain-list', $domain, $pattern, self::PRODUCT_CODE, false, $certificate) as $matchedDomain) {
                $client->get('/live/domain/query-domain-detail', [
                    'domain' => $matchedDomain, 'product_code' => self::PRODUCT_CODE,
                ]);
                $client->post('/live/domain/update-domain', [
                    'domain' => $matchedDomain, 'product_code' => self::PRODUCT_CODE,
                    'https_switch' => 1, 'cert_name' => $certName,
                ]);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'lvdn' => new CtcccloudRestClient(
                'ctlvdn-global.ctapi.ctyun.cn',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CtcccloudErrorSanitizer::sanitize($e);
    }
}

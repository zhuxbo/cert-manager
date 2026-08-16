<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 天翼云 AccessOne（边缘安全加速 AO，证书服务型，仅 exact 域名匹配）。
 *
 * 对齐 certimate ctcccloud-ao 的 Deploy（DOMAIN_MATCH_PATTERN_EXACT）：
 *   1. 创建证书到 AccessOne 证书空间拿 CertName（store_kind=ctcccloud_ao，POST /ctapi/v1/accessone/cert/create）。
 *   2. bind 精确匹配 config.domain：
 *      - POST /ctapi/v1/accessone/domain/config {domain, product_code:"020"}（查询域名基础及加速配置）。
 *      - POST /ctapi/v1/scdn/domain/modify_config {domain, product_code(取自查询结果), origin(回写源站，weight 转字符串、0→1),
 *        https_status:"on", cert_name=CertName}（修改配置：开启 HTTPS + 绑定证书，同时回写源站避免被清空）。
 *
 * 关键细节（与 certimate 严格对齐）：
 *   - 修改配置必须回写 origin（源站列表），否则会把已有源站清空；weight 为 0 时回退为 1，且统一转字符串
 *     （ModifyDomainConfig 的 origin.weight 是 string，GetDomainConfig 返回的是 int）。
 *   - product_code 用查询结果里的值（而非硬编码 020），与 certimate 一致。
 *   - 修改配置走 /ctapi/v1/scdn/... 路径（与查询的 /ctapi/v1/accessone/... 不同前缀），对齐 SDK api_modify_domain_config.go。
 *
 * 仅 exact（见 CtcccloudCdnDeployer 说明）。endpoint host：accessone-global.ctapi.ctyun.cn。
 */
class CtcccloudAoDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCtcccloudDomains;

    /** 查询域名配置时的产品码（对齐 certimate，固定 020）。 */
    private const QUERY_PRODUCT_CODE = '020';

    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'ao';
    }

    public function label(): string
    {
        return '天翼云边缘安全加速 AccessOne';
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
            fn (array $credentials): object => $this->makeClient('ao', $credentials),
            'ctcccloud_ao',
            '/ctapi/v1/accessone/cert/create',
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（AccessOne CertName）
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
            $client = $this->makeClient('ao', $credentials);

            $domains = $this->matchingDomains($client, '/ctapi/v2/domain/query', $domain, $pattern, self::QUERY_PRODUCT_CODE, true, $certificate);
            foreach ($domains as $matchedDomain) {
                $detail = $client->post('/ctapi/v1/accessone/domain/config', [
                    'domain' => $matchedDomain,
                    'product_code' => self::QUERY_PRODUCT_CODE,
                ]);

                $returnObj = is_array($detail['returnObj'] ?? null) ? $detail['returnObj'] : [];
                $productCode = is_string($returnObj['product_code'] ?? null) && $returnObj['product_code'] !== ''
                    ? $returnObj['product_code']
                    : self::QUERY_PRODUCT_CODE;

                $client->post('/ctapi/v1/scdn/domain/modify_config', [
                    'domain' => $matchedDomain,
                    'product_code' => $productCode,
                    'origin' => $this->remapOrigin($returnObj['origin'] ?? null),
                    'https_status' => 'on',
                    'cert_name' => $certName,
                ]);
            }
        });
    }

    /**
     * 把 GetDomainConfig 返回的源站列表（weight 为 int）转为 ModifyDomainConfig 所需形状（weight 为 string，0→1）。
     *
     * @param  mixed  $origin  GetDomainConfig 的 returnObj.origin
     * @return list<array{origin:string,role:string,weight:string}>
     */
    private function remapOrigin(mixed $origin): array
    {
        if (! is_array($origin)) {
            return [];
        }

        $out = [];
        foreach ($origin as $item) {
            if (! is_array($item)) {
                continue;
            }
            $weight = (int) ($item['weight'] ?? 0);
            if ($weight === 0) {
                $weight = 1;
            }
            $out[] = [
                'origin' => (string) ($item['origin'] ?? ''),
                'role' => (string) ($item['role'] ?? ''),
                'weight' => (string) $weight,
            ];
        }

        return $out;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'ao' => new CtcccloudRestClient(
                'accessone-global.ctapi.ctyun.cn',
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

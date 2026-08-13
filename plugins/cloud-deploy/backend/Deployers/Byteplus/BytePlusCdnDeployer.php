<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * BytePlus CDN（证书服务型）：证书先经 CDN AddCertificate 上传拿 CertId（走 RemoteCertStore 去重），
 * 再 BatchDeployCert 关联到加速域名。
 *
 * 对齐 certimate byteplus-cdn：
 *   - 上传：CDN AddCertificate(source=cert_center) → CertId（certmgr byteplus-cdn）。
 *   - 绑定：BatchDeployCert{CertId, Domain}（updateDomainCertificate）。
 *
 * exact 直接部署；wildcard 经 ListCdnDomains 分页匹配在线域名；certsan 经 DescribeCertConfig
 * 取得证书可关联但尚未使用相同证书的域名，再逐个 BatchDeployCert。
 *
 * 签名 service / region 对齐 byteplus-sdk-golang service/cdn/config.go：
 *   ServiceName = "CDN"（**大写**）、DefaultRegion = "ap-singapore-1"、host open.byteplusapi.com。
 *   大小写必须精确匹配——signing service 写错（如 "cdn"）会令凭证 scope 与网关不一致、签名校验失败。
 */
class BytePlusCdnDeployer extends AbstractDeployer
{
    use MatchesBytePlusDomains;

    /** CDN 签名 service（byteplus-sdk-golang ServiceName，**大写**）。 */
    private const CDN_SERVICE = 'CDN';

    /** CDN 签名 region（byteplus-sdk-golang DefaultRegion）。 */
    private const CDN_REGION = 'ap-singapore-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'BytePlus CDN';
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
        return new BytePlusCdnUploader(
            fn (array $credentials): object => $this->makeClient('cdn', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（CDN CertId）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = (string) ($config['domain'] ?? '');
        if (($pattern === '' || $pattern === 'exact' || $pattern === 'wildcard') && $domain === '') {
            $this->requireConfig($config, 'domain');
        }
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $pattern, $domain, $certId) {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $domains = match ($pattern) {
                '', 'exact' => [$domain],
                'wildcard' => str_starts_with($domain, '*.')
                    ? $this->findWildcardDomains($client, $credentials, $domain)
                    : [$domain],
                'certsan' => $this->findCertificateDomains($client, $certId),
                default => throw new BytePlusApiException('UnsupportedDomainMatchPattern', "不支持的域名匹配模式: $pattern"),
            };

            foreach ($domains as $matchedDomain) {
                // 关联证书与加速域名：Action=BatchDeployCert Version=2021-03-01 body {CertId, Domain}
                $client->openApi('POST', 'BatchDeployCert', '2021-03-01', [], [
                    'CertId' => $certId,
                    'Domain' => $matchedDomain,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function findWildcardDomains(BytePlusRestClient $client, array $credentials, string $domain): array
    {
        $domains = [];
        for ($page = 1; ; $page++) {
            $body = [
                'Domain' => substr($domain, 2),
                'Status' => 'online',
                'PageNum' => $page,
                'PageSize' => 100,
            ];
            $project = (string) ($credentials['project_name'] ?? '');
            if ($project !== '') {
                $body['Project'] = $project;
            }
            $response = $client->openApi('POST', 'ListCdnDomains', '2021-03-01', [], $body);
            $items = is_array($response->Result->Data ?? null) ? $response->Result->Data : [];
            foreach ($items as $item) {
                $candidate = is_object($item) ? (string) ($item->Domain ?? '') : '';
                if ($this->hostnameMatches($domain, $candidate)) {
                    $domains[] = $candidate;
                }
            }
            if (count($items) < 100) {
                break;
            }
        }

        return $domains;
    }

    /** @return list<string> */
    private function findCertificateDomains(BytePlusRestClient $client, string $certId): array
    {
        $response = $client->openApi('POST', 'DescribeCertConfig', '2021-03-01', [], ['CertId' => $certId]);
        $result = $response->Result ?? null;
        $domains = [];
        foreach (['CertNotConfig', 'OtherCertConfig'] as $field) {
            $items = is_object($result) && is_array($result->{$field} ?? null) ? $result->{$field} : [];
            foreach ($items as $item) {
                $domain = is_object($item) ? (string) ($item->Domain ?? '') : '';
                if ($domain !== '') {
                    $domains[] = $domain;
                }
            }
        }
        $specified = is_object($result) && is_array($result->SpecifiedCertConfig ?? null)
            ? $result->SpecifiedCertConfig
            : [];
        if ($domains === [] && $specified === []) {
            throw new BytePlusApiException('DomainNotFound', '未找到证书匹配的 CDN 域名');
        }

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new BytePlusRestClient(
                self::CDN_SERVICE,
                self::CDN_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}

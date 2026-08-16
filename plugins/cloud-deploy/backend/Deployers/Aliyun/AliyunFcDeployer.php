<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\FC\V20230330\FC;
use AlibabaCloud\SDK\FC\V20230330\Models\CertConfig;
use AlibabaCloud\SDK\FC\V20230330\Models\ListCustomDomainsRequest;
use AlibabaCloud\SDK\FC\V20230330\Models\UpdateCustomDomainInput;
use AlibabaCloud\SDK\FC\V20230330\Models\UpdateCustomDomainRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 阿里云函数计算 FC 自定义域名（内联型）：UpdateCustomDomain 直传 PEM 给自定义域名的 CertConfig，
 * 不走 CAS 证书服务，故 usesRemoteCertStore=false、certUploader=null。对齐 certimate aliyun-fc（FC 3.0 一路）。
 *
 * 仅实现 FC 3.0（fc-20230330）+ exact domain 核心路径；不做 certimate 的 FC 2.0（fc-open-20210406）
 * 与 DomainMatchPattern（wildcard/certsan）/遍历域名（留后续）。
 *
 * get-then-update（不可省）：UpdateCustomDomain 是全量覆盖，若不带 protocol/tlsConfig 会把域名既有的
 * 协议/TLS 配置重置。故先 GetCustomDomain 读回 protocol/tlsConfig 原样回填；并在证书未变化时短路跳过
 * （幂等，避免重复签发）；protocol=HTTP 时升级为 HTTP,HTTPS（与 certimate 一致，保证 HTTPS 可用）。
 *
 * FC SDK 走 darabonba OpenApiClient，API 错误抛 TeaError，沿用 AliyunErrorSanitizer 脱敏。
 */
class AliyunFcDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'fc';
    }

    public function label(): string
    {
        return '阿里云函数计算 FC';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'service_version', 'label' => '服务版本', 'type' => 'string', 'required' => false, 'default' => '3.0'],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string, mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = $this->requireConfig($config, 'region');
        $serviceVersion = (string) ($config['service_version'] ?? '3.0');
        if (! in_array($serviceVersion, ['3', '3.0'], true)) {
            $this->fail("Aliyun FC 不支持的服务版本: {$serviceVersion}（FC 2.0 SDK 依赖未安装）");
        }
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = (string) ($config['domain'] ?? '');
        if (($pattern === '' || $pattern === 'exact' || $pattern === 'wildcard') && $domain === '') {
            $this->requireConfig($config, 'domain');
        }
        if (! in_array($pattern, ['', 'exact', 'wildcard', 'certsan'], true)) {
            $this->fail("Aliyun FC 不支持的域名匹配模式: $pattern");
        }
        // 证书 + 中间证书拼成完整链上传（与 CDN/Live/OSS 同口径）
        $certificate = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $region, $pattern, $domain, $certificate, $certRef, $certName) {
            /** @var FC $client */
            $client = $this->makeClient('fc', array_replace($credentials, ['region' => $region]));

            $domains = match ($pattern) {
                '', 'exact' => [$domain],
                'wildcard' => str_starts_with($domain, '*.')
                    ? $this->findDomains($client, fn (string $candidate): bool => $this->hostnameMatches($domain, $candidate))
                    : [$domain],
                default => $this->findDomains($client, fn (string $candidate): bool => $this->certificateMatches((string) $certRef['cert'], $candidate)),
            };

            foreach ($domains as $matchedDomain) {
                // 读回既有配置（protocol/tlsConfig 需原样保留；证书未变则短路）
                $existing = $client->getCustomDomain($matchedDomain)->body;
                if ($existing?->certConfig !== null && $existing->certConfig->certificate === $certificate) {
                    continue;
                }

                $input = new UpdateCustomDomainInput([
                    'certConfig' => new CertConfig([
                        'certName' => $certName,
                        'certificate' => $certificate,
                        'privateKey' => $certRef['key'],
                    ]),
                    'protocol' => $existing?->protocol === 'HTTP' ? 'HTTP,HTTPS' : $existing?->protocol,
                    'tlsConfig' => $existing?->tlsConfig,
                ]);
                $client->updateCustomDomain($matchedDomain, new UpdateCustomDomainRequest(['body' => $input]));
            }
        });
    }

    /** @return list<string> */
    private function findDomains(FC $client, callable $matches): array
    {
        $domains = [];
        $nextToken = null;
        do {
            $response = $client->listCustomDomains(new ListCustomDomainsRequest([
                'limit' => 100,
                'nextToken' => $nextToken,
            ]));
            $items = is_array($response->body?->customDomains ?? null) ? $response->body->customDomains : [];
            foreach ($items as $item) {
                $candidate = (string) ($item->domainName ?? '');
                if ($candidate !== '' && $matches($candidate)) {
                    $domains[] = $candidate;
                }
            }
            $nextToken = $response->body?->nextToken;
        } while (is_string($nextToken) && $nextToken !== '');

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'fc' => new FC($this->aliyunConfig($credentials, $this->endpointForRegion($credentials['region'] ?? ''))),
        };
    }

    /**
     * FC 3.0 接入点：region 为空回落杭州，否则 fcv3.{region}.aliyuncs.com（对齐 certimate）。
     */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'fcv3.cn-hangzhou.aliyuncs.com' : "fcv3.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

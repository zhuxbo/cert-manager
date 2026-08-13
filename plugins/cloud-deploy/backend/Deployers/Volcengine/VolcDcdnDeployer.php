<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 火山引擎 DCDN 全站加速（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再批量绑定到域名。对齐 certimate volcengine-dcdn：
 *   CreateCertBind {CertSource:"volc", CertId, DomainNames:[域名]}（Action=CreateCertBind, Version=2021-04-01）
 *
 * 仅实现 exact domain 核心路径；exact 模式去掉前导 "*"（"*.example.com" → ".example.com"，适配火山 DCDN 泛域名格式，
 * 与 certimate exact 分支一致）。region 默认 cn-beijing（证书中心 + DCDN 同 region）。
 *
 * region 透传：certUploader($config) 与 bind($config) 都从 config 解析 region 经 makeClient 第三参传入，
 * 使「上传到证书中心」与「绑定 DCDN」用同一 region（certimate 二者共用 config.Region）。
 */
class VolcDcdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesVolcDomains;
    use ResolvesVolcRegion;

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'dcdn';
    }

    public function label(): string
    {
        return '火山引擎 DCDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域（默认 cn-beijing）', 'type' => 'string', 'required' => false],
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
        $region = $this->resolveRegion($config, 'cn-beijing');

        // 证书中心上传（与 DCDN 同 region），uploader 复用 deployer 的注入缝 makeClient('certcenter')
        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  证书中心 InstanceId 与可选证书材料
     * @param  array{access_key_id?:string,secret_access_key?:string,project_name?:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = $this->resolveRegion($config, 'cn-beijing');
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        [$certId, $certificate] = $this->volcCertificateReference($certRef);
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $domain, $certId, $certificate, $pattern, $region) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('dcdn', $credentials, $region);
            $candidates = in_array($pattern, ['wildcard', 'certsan'], true)
                ? $this->listDcdnDomains($client, $credentials)
                : [];
            $domains = $this->matchVolcDomains($candidates, $pattern, $domain, $certificate);
            if (in_array($pattern, ['', 'exact'], true)) {
                $domains = array_map(fn (string $item): string => preg_replace('/^\*/', '', $item) ?? $item, $domains);
            }
            if ($domains === []) {
                $this->fail('未找到匹配的 DCDN 域名');
            }
            $client->callJson('CreateCertBind', '2021-04-01', [
                'CertSource' => 'volc',
                'CertId' => $certId,
                'DomainNames' => $domains,
            ]);
        });
    }

    /** @return list<string> */
    private function listDcdnDomains(VolcRestClient $client, array $credentials): array
    {
        $domains = [];
        $page = 1;
        do {
            $body = ['PageNumber' => $page, 'PageSize' => 100];
            $project = (string) ($credentials['project_name'] ?? '');
            if ($project !== '') {
                $body['ProjectName'] = [$project];
            }
            $result = $client->callJson('ListDomainConfig', '2021-04-01', $body);
            $items = is_array($result['DomainList'] ?? null) ? $result['DomainList'] : [];
            foreach ($items as $item) {
                if (! is_array($item) || ($item['Status'] ?? null) === 'Stop') {
                    continue;
                }
                $candidate = (string) ($item['Domain'] ?? '');
                if ($candidate !== '') {
                    $domains[] = $candidate;
                }
            }
            $page++;
        } while (count($items) >= 100);

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials, string $region = 'cn-beijing'): object
    {
        return match ($kind) {
            'dcdn' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'dcdn',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}

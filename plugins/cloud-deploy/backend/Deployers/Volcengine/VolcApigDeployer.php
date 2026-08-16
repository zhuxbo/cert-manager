<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 火山引擎 API 网关 APIG（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再关联到自定义域名。对齐 certimate volcengine-apig（JSON 协议）：
 *   ListCustomDomains 分页找 exact 域名 → 对每个匹配 domainId：
 *     GetCustomDomain（读 Protocol）→ UpdateCustomDomain {Id, Protocol(确保含 HTTPS), CertificateId}
 *   （Action=ListCustomDomains/GetCustomDomain/UpdateCustomDomain, Version=2021-03-03）
 *
 * 仅 exact 域名匹配（不做 certimate 的 wildcard/certsan 遍历）。region 必填；证书中心上传与 APIG 用同一 region。
 * 过滤掉 Creating/CreationFailed/Deleting/DeletionFailed 状态的域名（对齐 certimate getAllDomains）。
 */
class VolcApigDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesVolcDomains;

    private const IGNORED_STATUSES = ['Creating', 'CreationFailed', 'Deleting', 'DeletionFailed'];

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'apig';
    }

    public function label(): string
    {
        return '火山引擎 API 网关';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // RegistryCompleteness 以空 config 探活：region 优雅默认（真实 region 由 bind 强校验），不抛
        $region = (string) ($config['region'] ?? '');

        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  证书中心 InstanceId 与可选证书材料
     * @param  array{access_key_id?:string,secret_access_key?:string,project_name?:string}  $credentials
     * @param  array{region:string,domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // 业务校验在 guardSdk 之外
        $region = (string) $this->requireConfig($config, 'region');
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        [$certId, $certificate] = $this->volcCertificateReference($certRef);
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $domainIds = $this->guardSdk(fn (): array => $this->findDomainIds(
            $this->makeClient('apig', $credentials, $region),
            $pattern,
            $domain,
            $certificate,
        ));

        // 业务错误：未找到自定义域名（在 guardSdk 之外）
        if ($domainIds === []) {
            $this->fail($domain !== '' ? "未找到自定义域名 $domain" : '未找到匹配证书的自定义域名');
        }

        $this->guardSdk(function () use ($credentials, $domainIds, $certId, $region) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('apig', $credentials, $region);
            foreach ($domainIds as $domainId) {
                $this->updateDomainCertificate($client, $domainId, $certId);
            }
        });
    }

    /**
     * 分页 ListCustomDomains 找 exact 匹配的域名 id（过滤过渡态）。
     *
     * @return list<string>
     */
    private function findDomainIds(VolcRestClient $client, string $pattern, string $domain, string $certificate): array
    {
        $ids = [];
        $page = 1;
        $pageSize = 100;
        do {
            $result = $client->callJson('ListCustomDomains', '2021-03-03', [
                'PageNumber' => $page,
                'PageSize' => $pageSize,
            ]);

            $items = is_array($result['Items'] ?? null) ? $result['Items'] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $status = is_string($item['Status'] ?? null) ? $item['Status'] : '';
                if (in_array($status, self::IGNORED_STATUSES, true)) {
                    continue;
                }
                $candidate = (string) ($item['Domain'] ?? '');
                $matches = match ($pattern) {
                    '', 'exact' => $domain === '' ? $this->fail('缺少配置 domain') : $candidate === $domain,
                    'wildcard' => $domain === '' ? $this->fail('缺少配置 domain') : $this->certificateHostnamePatternMatches($domain, $candidate),
                    'certsan' => $certificate === '' ? $this->fail('certsan 匹配缺少证书材料') : $this->certificateMatchesHostname($certificate, $candidate),
                    default => $this->fail("不支持的域名匹配模式 $pattern"),
                };
                if ($matches) {
                    $id = $item['Id'] ?? null;
                    if (is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
            }
            $page++;
        } while (count($items) >= $pageSize);

        return $ids;
    }

    /** GetCustomDomain 读 Protocol → UpdateCustomDomain 确保含 HTTPS + 设新证书。 */
    private function updateDomainCertificate(VolcRestClient $client, string $domainId, string $certId): void
    {
        $detail = $client->callJson('GetCustomDomain', '2021-03-03', ['Id' => $domainId]);
        $custom = is_array($detail['CustomDomain'] ?? null) ? $detail['CustomDomain'] : [];
        $protocol = is_array($custom['Protocol'] ?? null) ? array_values($custom['Protocol']) : [];

        // 确保协议含 HTTPS（与 certimate 一致）
        $protocol = array_values(array_filter($protocol, 'is_string'));
        if (! in_array('HTTPS', $protocol, true)) {
            $protocol[] = 'HTTPS';
        }

        $client->callJson('UpdateCustomDomain', '2021-03-03', [
            'Id' => $domainId,
            'Protocol' => $protocol,
            'CertificateId' => $certId,
        ]);
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'apig' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'apig',
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

<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 华为云 Web 应用防火墙 WAF（证书服务型，region 维度，绑定云模式防护域名）。
 *
 * 对齐 certimate deployer huaweicloud-waf 的 DEPLOY_TARGET_CLOUDSERVER 核心路径：
 *   1. 经 HuaweiWafUploader 创建 WAF 证书拿 id（store_kind=huawei_waf:{region}，走 RemoteCertStore 去重）。
 *   2. bind 用 global 凭证 IAM 反查 projectId，ShowCertificate 取证书名（UpdateHost 需 certificateid+certificatename），
 *      ListHost 按 exact hostname 找 host_id，UpdateHost（PUT /v1/{project_id}/waf/instance/{host_id}）绑证书。
 *
 * WAF 为 region 服务（waf.{region}.myhuaweicloud.com，basic 凭证 + projectId）。region + domain 必填。
 * 企业项目 ID 作 enterprise_project_id 查询参数透传。WAF 字段名 certificateid/certificatename（严格对齐 certimate）。
 *
 * 与 certimate 对齐的取舍：certimate WAF 支持 cloudserver/premiumhost/certificate 三类 DeployTarget。本端点
 * **仅实现 cloudserver（云模式）+ exact**（domain 必填、精确匹配 hostname、单域名绑定），不做 premiumhost（独享模式）、
 * 不做 certificate（替换既有证书）——与插件其他端点「仅核心路径 + 仅 exact」口径一致。
 *
 * 与 certimate 的偏差（已知，刻意修正）：certimate 用上传时生成的 CertName 作 UpdateHost 的 certificatename；本实现的
 * 证书服务型 bind 只拿到证书 id（不携带 name），故 bind 内 ShowCertificate 反查证书名再传 —— 同样达成绑定且契约干净。
 */
class WafDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    private const PAGE_SIZE = 100;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'waf';
    }

    public function label(): string
    {
        return '华为云 Web 应用防火墙 WAF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '防护域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = isset($config['region']) ? (string) $config['region'] : '';

        return new HuaweiWafUploader(
            $region,
            fn (array $credentials): object => $this->makeClient('iam', $credentials),
            fn (array $credentials, string $projectId): object => $this->makeClient('waf', $credentials, $region, $projectId),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（WAF 证书 id）
     * @param  array<string,mixed>  $credentials
     * @param  array{region:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;
        $enterpriseProjectId = isset($credentials['enterprise_project_id']) ? (string) $credentials['enterprise_project_id'] : '';

        $this->guardSdk(function () use ($credentials, $region, $domain, $certId, $enterpriseProjectId) {
            $projectId = $this->resolveProjectId($credentials, $region);

            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('waf', $credentials, $region, $projectId);

            $epQuery = $enterpriseProjectId !== '' ? ['enterprise_project_id' => $enterpriseProjectId] : [];

            // ShowCertificate 反查证书名（UpdateHost 需 certificatename）。
            $certResp = $client->get("/v1/$projectId/waf/certificate/$certId", $epQuery);
            $certName = is_string($certResp['name'] ?? null) ? $certResp['name'] : '';

            // ListHost：exact 匹配 hostname（去 `*` 前缀，对齐 certimate strings.TrimPrefix("*")），分页找 host_id。
            $hostId = $this->findHostId($client, $projectId, $domain, $epQuery);
            if ($hostId === '') {
                throw new HuaweicloudApiException('HostNotFound', "未找到云模式防护域名: $domain");
            }

            // UpdateHost：绑定证书（字段名 certificateid / certificatename）。
            $client->put("/v1/$projectId/waf/instance/$hostId", [
                'certificateid' => $certId,
                'certificatename' => $certName,
            ], $epQuery);
        });
    }

    /**
     * 分页 ListHost，exact 匹配 hostname（去 `*` 前缀）返回 host_id。
     *
     * @param  array<string,string>  $epQuery
     */
    private function findHostId(HuaweicloudRestClient $client, string $projectId, string $domain, array $epQuery): string
    {
        $target = ltrim($domain, '*');
        $page = 1;

        while (true) {
            $resp = $client->get("/v1/$projectId/waf/instance", $epQuery + [
                'hostname' => $target,
                'page' => $page,
                'pagesize' => self::PAGE_SIZE,
            ]);

            $items = is_array($resp['items'] ?? null) ? $resp['items'] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $hostname = is_string($item['hostname'] ?? null) ? $item['hostname'] : '';
                $id = isset($item['id']) ? (string) $item['id'] : '';
                if ($hostname === $target && $id !== '') {
                    return $id;
                }
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
    {
        return match ($kind) {
            'iam' => new HuaweicloudRestClient(
                $this->iamHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'waf' => new HuaweicloudRestClient(
                $this->regionalHost('waf', $region),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                $projectId,
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}

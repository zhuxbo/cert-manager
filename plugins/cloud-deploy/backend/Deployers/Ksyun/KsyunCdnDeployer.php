<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 金山云 CDN（内联型）：CDN 支持 ConfigCertificate 直传 PEM（不经证书中心），故 usesRemoteCertStore=false、certUploader=null。
 *
 * 对齐 certimate ksyun-cdn 的 deployToDomain（DEPLOY_TARGET_DOMAIN + exact 匹配）：
 *   1. GET /2019-06-01/GetCdnDomains（Action=GetCdnDomains, Version=2019-06-01）分页拉全部加速域名，
 *      跳过 offline/icp_checking/icp_check_failed/locking/locked 状态。
 *   2. exact 过滤 DomainName == config.domain，得到一个或多个 DomainId。
 *   3. 逐个 POST /2016-09-01/cert/ConfigCertificate（Action=ConfigCertificate, Version=2016-09-01）
 *      {Enable:"on", DomainIds, CertificateName, ServerCertificate=证书, PrivateKey=私钥} 为该域名配置证书。
 *
 * 与 certimate 对齐的取舍：
 * - certimate 支持 domainMatchPattern（exact/wildcard/certsan）与两类 DeployTarget（domain/certificate）。
 *   本端点**仅实现 DEPLOY_TARGET_DOMAIN + exact**核心路径（domain 必填、精确匹配域名后逐个 ConfigCertificate），
 *   不做 wildcard/certsan 的「列举全部域名再泛/SAN 匹配」，也不做 DEPLOY_TARGET_CERTIFICATE（按既有证书 ID 调 SetCertificate）——
 *   与插件其他端点（AliyunDcdn/Qiniu/Baidu cdn 等）「仅 exact」的简化口径一致。如需泛域名按 SAN 批量部署，可逐个域名各配一个 target。
 * - ProjectId 选填（certimate omitempty）：未填则不传，让金山云用默认项目；填则透传过滤。
 */
class KsyunCdnDeployer extends AbstractDeployer
{
    /** 跳过的域名状态（无法配置证书；对齐 certimate getAllDomains 的 ignoredStatuses）。 */
    private const IGNORED_STATUSES = ['offline', 'icp_checking', 'icp_check_failed', 'locking', 'locked'];

    private const PAGE_SIZE = 100;

    public function provider(): string
    {
        return 'ksyun';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '金山云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
            ['key' => 'project_id', 'label' => '项目 ID（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain:string,project_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $projectId = isset($config['project_id']) ? (string) $config['project_id'] : '';
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        // 内联型：$certRef 为 {cert,key,chain} 三元组。证书本体 + 中间证书拼完整链。
        $serverCert = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $privateKey = is_array($certRef) ? trim((string) $certRef['key']) : '';

        $this->guardSdk(function () use ($credentials, $domain, $projectId, $certName, $serverCert, $privateKey) {
            /** @var KsyunRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $domainIds = $this->findDomainIds($client, $domain, $projectId);
            if ($domainIds === []) {
                throw new KsyunApiException('DomainNotFound', "未找到匹配的金山云 CDN 域名: $domain");
            }

            foreach ($domainIds as $domainId) {
                $client->post('/2016-09-01/cert/ConfigCertificate', [
                    'Action' => 'ConfigCertificate',
                    'Version' => '2016-09-01',
                    'Enable' => 'on',
                    'DomainIds' => $domainId,
                    'CertificateName' => $certName,
                    'ServerCertificate' => $serverCert,
                    'PrivateKey' => $privateKey,
                ]);
            }
        });
    }

    /**
     * 分页拉全部域名（跳过无法配置证书的状态），exact 过滤出 DomainName == $domain 的 DomainId 列表。
     *
     * @return list<string>
     */
    private function findDomainIds(KsyunRestClient $client, string $domain, string $projectId): array
    {
        $domainIds = [];
        $page = 1;

        while (true) {
            $params = [
                'Action' => 'GetCdnDomains',
                'Version' => '2019-06-01',
                'PageNumber' => (string) $page,
                'PageSize' => (string) self::PAGE_SIZE,
            ];
            if ($projectId !== '') {
                $params['ProjectId'] = $projectId;
            }

            $resp = $client->get('/2019-06-01/GetCdnDomains', $params);
            $domains = is_array($resp['Domains'] ?? null) ? $resp['Domains'] : [];

            foreach ($domains as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $status = is_string($item['DomainStatus'] ?? null) ? $item['DomainStatus'] : '';
                if (in_array($status, self::IGNORED_STATUSES, true)) {
                    continue;
                }
                $name = is_string($item['DomainName'] ?? null) ? $item['DomainName'] : '';
                $id = isset($item['DomainId']) ? (string) $item['DomainId'] : '';
                if ($name === $domain && $id !== '') {
                    $domainIds[] = $id;
                }
            }

            if (count($domains) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $domainIds;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new KsyunRestClient(
                'cdn',
                'cdn.api.ksyun.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return KsyunErrorSanitizer::sanitize($e);
    }
}

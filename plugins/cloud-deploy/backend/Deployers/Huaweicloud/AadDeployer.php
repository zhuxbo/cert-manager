<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 华为云 DDoS 高防 AAD（内联型，按域名设置证书）。
 *
 * 对齐 certimate deployer huaweicloud-aad 的 exact 路径：
 *   1. AAD v2 ListInstanceDomains 分页拉实例下域名（GET /v2/aad/instances/{instance_id}/domains?offset=&limit=），
 *      跳过 domain_status="1"（对齐 certimate ignoredStatuses），exact 匹配 domain_name 得 domain_id。
 *   2. AAD v1 SetCertForDomain（POST /v1/aad/domains/set-cert）直灌证书
 *      {op_type:0, domain_id, cert_name, cert_file=完整链, cert_key_file=私钥}。
 *
 * 内联型：AAD 直接给域名设置证书内容（不经证书托管服务），故 usesRemoteCertStore=false、certUploader=null，
 * bind 收 {cert,key,chain} 三元组（对齐 certimate 直传 certPEM/privkeyPEM）。
 *
 * AAD 为**全局服务**（aad.myhuaweicloud.com，global 凭证，无 region/projectId；certimate createSDKClients 固定
 * region=cn-north-4 + global.NewCredentialsBuilder）。instance_id + domain 必填。
 *
 * 与 certimate 对齐的取舍：certimate 支持 exact/wildcard/certsan。本端点**仅实现 exact**（单域名），与插件「仅 exact」口径一致。
 */
class AadDeployer extends AbstractDeployer
{
    private const PAGE_SIZE = 10;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'aad';
    }

    public function label(): string
    {
        return '华为云 DDoS 高防 AAD';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'instance_id', 'label' => 'DDoS 高防实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '网站域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array<string,mixed>  $credentials
     * @param  array{instance_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $instanceId = (string) $this->requireConfig($config, 'instance_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        // 内联型：$certRef 为 {cert,key,chain} 三元组。证书本体 + 中间证书拼完整链。
        $certFile = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $certKeyFile = is_array($certRef) ? trim((string) $certRef['key']) : '';
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $instanceId, $domain, $certFile, $certKeyFile, $certName) {
            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('aad', $credentials);

            $domainId = $this->findDomainId($client, $instanceId, $domain);
            if ($domainId === '') {
                throw new HuaweicloudApiException('DomainNotFound', "未找到 AAD 实例下域名: $domain");
            }

            // SetCertForDomain：op_type=0 表示新增/设置证书。
            $client->post('/v1/aad/domains/set-cert', [
                'op_type' => 0,
                'domain_id' => $domainId,
                'cert_name' => $certName,
                'cert_file' => $certFile,
                'cert_key_file' => $certKeyFile,
            ]);
        });
    }

    /**
     * 分页 ListInstanceDomains，exact 匹配 domain_name（跳过 domain_status="1"）返回 domain_id。
     */
    private function findDomainId(HuaweicloudRestClient $client, string $instanceId, string $domain): string
    {
        $offset = 0;

        while (true) {
            $resp = $client->get("/v2/aad/instances/$instanceId/domains", [
                'offset' => $offset,
                'limit' => self::PAGE_SIZE,
            ]);

            $domains = is_array($resp['domains'] ?? null) ? $resp['domains'] : [];
            foreach ($domains as $item) {
                if (! is_array($item)) {
                    continue;
                }
                // 跳过停用状态（对齐 certimate ignoredStatuses=["1"]）。
                $status = isset($item['domain_status']) ? (string) $item['domain_status'] : '';
                if ($status === '1') {
                    continue;
                }
                $name = is_string($item['domain_name'] ?? null) ? $item['domain_name'] : '';
                $id = isset($item['domain_id']) ? (string) $item['domain_id'] : '';
                if ($name === $domain && $id !== '') {
                    return $id;
                }
            }

            if (count($domains) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // AAD 全局服务（global 凭证，无 projectId）。v1/v2 共用同 host，仅 path 版本不同。
            'aad' => new HuaweicloudRestClient(
                'aad.myhuaweicloud.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}

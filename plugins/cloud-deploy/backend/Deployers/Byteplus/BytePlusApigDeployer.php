<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use stdClass;
use Throwable;

/**
 * BytePlus API 网关 APIG（证书服务型）：证书先经证书中心上传拿 id（走 RemoteCertStore 去重），
 * 再把 id 设到匹配的自定义域名。
 *
 * 对齐 certimate byteplus-apig（service=apig, version 2021-03-03，POST JSON）：
 *   - 上传：证书中心 UploadCertificate（region ap-singapore-1）→ CertId（复用 byteplus-certcenter certmgr）。
 *   - ListCustomDomains 分页查全部自定义域名，过滤 Creating/CreationFailed/Deleting/DeletionFailed 状态。
 *   - exact：filter Domain == config.domain；命中后逐个 GetCustomDomain（取 Protocol）→ UpdateCustomDomain
 *     {Id, Protocol(确保含 HTTPS), CertificateId}。
 *
 * 仅实现 exact domain 核心路径；不做 certimate 的 wildcard / certsan 匹配。
 *
 * APIG 为 region 维度（统一网关 host，但签名 region 取 config.region）。
 */
class BytePlusApigDeployer extends AbstractDeployer
{
    use ResolvesBytePlusRegion;

    /** 证书中心上传固定 ap-singapore-1（对齐 certimate alb/apig 等的 certmgr region）。 */
    private const CERTCENTER_REGION = 'ap-singapore-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'apig';
    }

    public function label(): string
    {
        return 'BytePlus API 网关';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new BytePlusCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（证书中心 CertId）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{region:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        // 查域名（SDK 调用）包 guardSdk；空结果的业务错误放 guardSdk 外，保留可读文案（不被重建成通用异常）。
        $domainIds = $this->guardSdk(function () use ($credentials, $region, $domain): array {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('apig', $credentials, $region);

            return $this->findDomainIds($client, $domain);
        });

        if ($domainIds === []) {
            $this->fail("未找到匹配的 API 网关自定义域名: $domain");
        }

        foreach ($domainIds as $domainId) {
            $this->guardSdk(function () use ($credentials, $region, $domainId, $certId) {
                /** @var BytePlusRestClient $client */
                $client = $this->makeClient('apig', $credentials, $region);
                $this->updateDomainCertificate($client, $domainId, $certId);
            });
        }
    }

    /**
     * ListCustomDomains 分页找 exact 匹配域名的 Id 列表（过滤 Creating/Deleting 等过渡态）。
     *
     * @return list<string>
     */
    private function findDomainIds(BytePlusRestClient $client, string $domain): array
    {
        $ignored = ['Creating', 'CreationFailed', 'Deleting', 'DeletionFailed'];
        $ids = [];
        $pageNumber = 1;
        $pageSize = 100;

        while (true) {
            $result = $client->openApi('POST', 'ListCustomDomains', '2021-03-03', [], [
                'PageNumber' => $pageNumber,
                'PageSize' => $pageSize,
            ]);

            $items = is_array($result->Items ?? null) ? $result->Items : [];
            foreach ($items as $item) {
                if (! $item instanceof stdClass) {
                    continue;
                }
                $status = (string) ($item->Status ?? '');
                if (in_array($status, $ignored, true)) {
                    continue;
                }
                if ((string) ($item->Domain ?? '') === $domain) {
                    $id = (string) ($item->Id ?? '');
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }

            if (count($items) < $pageSize) {
                break;
            }
            $pageNumber++;
        }

        return $ids;
    }

    /**
     * GetCustomDomain 取 Protocol → UpdateCustomDomain 设 CertificateId（确保 Protocol 含 HTTPS）。
     */
    private function updateDomainCertificate(BytePlusRestClient $client, string $domainId, string $certId): void
    {
        $getResult = $client->openApi('POST', 'GetCustomDomain', '2021-03-03', [], [
            'Id' => $domainId,
        ]);

        // GetCustomDomain 返回 CustomDomain.Protocol（list<string>，如 ["HTTP","HTTPS"]）。
        $protocol = [];
        $custom = $getResult->CustomDomain ?? null;
        if ($custom instanceof stdClass && is_array($custom->Protocol ?? null)) {
            $protocol = array_values(array_map(static fn ($v): string => (string) $v, $custom->Protocol));
        }
        if (! in_array('HTTPS', $protocol, true)) {
            $protocol[] = 'HTTPS';
        }

        $client->openApi('POST', 'UpdateCustomDomain', '2021-03-03', [], [
            'Id' => $domainId,
            'Protocol' => $protocol,
            'CertificateId' => $certId,
        ]);
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            // 证书中心：上传，固定 ap-singapore-1。
            'certcenter' => new BytePlusRestClient(
                'certificate_service',
                self::CERTCENTER_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // APIG：绑定，签名 region 取 config.region。
            'apig' => new BytePlusRestClient(
                'apig',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}

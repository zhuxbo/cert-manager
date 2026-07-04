<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * Zenlayer CDN（证书服务型）：证书先经 CDN 证书服务上传拿 certificateId（走 RemoteCertStore 去重），
 * 再绑定到 CDN 加速域名。
 *
 * 对齐 certimate zenlayer-cdn 的 deployToDomain（DEPLOY_TARGET_DOMAIN + exact）：
 *   1. 证书经 ZenlayerCertUploader 上传（CreateCertificate，service=cdn）拿 certificateId（store_kind=zenlayer_cdn）。
 *   2. DescribeDomains（domainStatus=ENABLED）分页拉加速域名，exact 过滤 domainName == config.domain 得 domainId 列表。
 *   3. 逐个 domainId：DescribeDomainCertificate 若已是该证书则跳过；否则 ModifyDomainCertificate 绑定，
 *      再 DescribeDomains 轮询 configStatus 直到 DEPLOYED（FAILED 报错）。
 *
 * 与 certimate 对齐的取舍：certimate 支持 exact / wildcard / certsan 匹配 + DEPLOY_TARGET_CERTIFICATE。
 * 本端点**仅实现 exact + DEPLOY_TARGET_DOMAIN**（domain 必填、精确域名），与插件其他端点「仅 exact」口径一致。
 * 轮询走 sleep() 注入缝（测试 no-op），上限 30 次（与 certimate 10s 间隔等价的有界收敛）。
 */
class ZenlayerCdnDeployer extends AbstractDeployer
{
    private const PAGE_SIZE = 100;

    private const POLL_MAX_ATTEMPTS = 30;

    public function provider(): string
    {
        return 'zenlayer';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'Zenlayer CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new ZenlayerCertUploader(
            fn (array $credentials): object => $this->makeClient('cdn', $credentials),
            'zenlayer_cdn',
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（Zenlayer CDN certificateId）
     * @param  array{access_key_id:string,access_key_password:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var ZenlayerRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $domainIds = $this->findDomainIds($client, $domain);
            if ($domainIds === []) {
                throw new ZenlayerApiException('DomainNotFound', "未找到匹配的 Zenlayer CDN 域名: $domain");
            }

            foreach ($domainIds as $domainId) {
                $this->updateDomainCertificate($client, $domainId, $certId);
            }
        });
    }

    /**
     * DescribeDomains（ENABLED）分页拉域名，exact 过滤出 domainName == $domain 的 domainId 列表。
     *
     * @return list<string>
     */
    private function findDomainIds(ZenlayerRestClient $client, string $domain): array
    {
        $domainIds = [];
        $page = 1;

        while (true) {
            $resp = $client->call('DescribeDomains', [
                'domainStatus' => 'ENABLED',
                'pageNum' => $page,
                'pageSize' => self::PAGE_SIZE,
            ]);
            $dataSet = is_array($resp['dataSet'] ?? null) ? $resp['dataSet'] : [];

            foreach ($dataSet as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = is_string($item['domainName'] ?? null) ? $item['domainName'] : '';
                $id = isset($item['domainId']) ? (string) $item['domainId'] : '';
                if ($name === $domain && $id !== '') {
                    $domainIds[] = $id;
                }
            }

            if (count($dataSet) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $domainIds;
    }

    /**
     * 绑定单个域名证书：已是该证书则跳过；否则 ModifyDomainCertificate + 轮询 configStatus 到 DEPLOYED。
     */
    private function updateDomainCertificate(ZenlayerRestClient $client, string $domainId, string $certId): void
    {
        // 查询既有证书，避免重复绑定
        $descResp = $client->call('DescribeDomainCertificate', ['domainId' => $domainId]);
        $certificate = is_array($descResp['certificate'] ?? null) ? $descResp['certificate'] : [];
        if (($certificate['certificateId'] ?? null) === $certId) {
            return;
        }

        // 修改域名证书
        $client->call('ModifyDomainCertificate', ['domainId' => $domainId, 'certificateId' => $certId]);

        // 轮询部署状态：DEPLOYED 成功 / FAILED 报错
        for ($attempt = 0; $attempt < self::POLL_MAX_ATTEMPTS; $attempt++) {
            $pollResp = $client->call('DescribeDomains', ['domainIds' => [$domainId], 'pageNum' => 1, 'pageSize' => 1]);
            $dataSet = is_array($pollResp['dataSet'] ?? null) ? $pollResp['dataSet'] : [];
            if ($dataSet === []) {
                throw new ZenlayerApiException('DomainNotFound', "轮询时未找到 Zenlayer CDN 域名: $domainId");
            }

            $status = is_string($dataSet[0]['configStatus'] ?? null) ? $dataSet[0]['configStatus'] : '';
            if ($status === 'DEPLOYED') {
                return;
            }
            if ($status === 'FAILED') {
                throw new ZenlayerApiException('DeployFailed', "Zenlayer CDN 域名 $domainId 证书部署失败");
            }

            $this->sleep(10);
        }

        throw new ZenlayerApiException('DeployTimeout', "Zenlayer CDN 域名 $domainId 证书部署超时");
    }

    /** 轮询间隔（秒）；测试 override 为 no-op。 */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new ZenlayerRestClient(
                'cdn',
                '2022-11-20',
                $credentials['access_key_id'] ?? '',
                $credentials['access_key_password'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return ZenlayerErrorSanitizer::sanitize($e);
    }
}

<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Ga\V20191120\Ga;
use AlibabaCloud\SDK\Ga\V20191120\Models\AssociateAdditionalCertificatesWithListenerRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\AssociateAdditionalCertificatesWithListenerRequest\certificates as associateCertificates;
use AlibabaCloud\SDK\Ga\V20191120\Models\ListListenerCertificatesRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\ListListenersRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateAdditionalCertificateWithListenerRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateListenerRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateListenerRequest\certificates;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 GA（全球加速，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 ga.UpdateListener 把证书设为指定 HTTPS 监听器的默认证书。
 *
 * 与 alb/nlb 的差异（对齐 certimate aliyun-ga 实情）：
 * - GA 不分地域：endpoint 固定 ga.cn-hangzhou.aliyuncs.com、请求 RegionId 固定 'cn-hangzhou'，故 config **无 region**。
 * - 证书入参在 UpdateListenerRequest.certificates[].id（注意键名是 `id` 而非 alb 的 `certificateId`），值同样吃
 *   **完整 CertIdentifier 字符串**，不拆 certId+region。
 * - accelerator_id 是 certimate 部署到监听器的必填配置（也是未来 SNI 扩展证书路径所需），故纳入 config 并校验；
 *   但简化路径用的 UpdateListener 接口本身**不**接收 accelerator_id（仅 listener_id + region + certificates）。
 *
 * 支持 listener 与 accelerator 两种目标；后者分页列出 HTTPS 监听并批量设置主证书。
 */
class AliyunGaDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    /** GA 全局服务，地域固定杭州（与 certimate 一致）。 */
    private const REGION_ID = 'cn-hangzhou';

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'ga';
    }

    public function label(): string
    {
        return '阿里云全球加速';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => false, 'default' => 'listener'],
            ['key' => 'accelerator_id', 'label' => '全球加速实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听 ID', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $this->casRegion($config));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 certificates[].id）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{accelerator_id:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // accelerator_id 为 certimate 部署到监听器的必填配置，先校验存在（UpdateListener 本身不发送它）
        $acceleratorId = (string) $this->requireConfig($config, 'accelerator_id');
        $target = strtolower((string) ($config['deploy_target'] ?? 'listener'));
        $listenerId = (string) ($config['listener_id'] ?? '');
        if ($target === 'listener' && $listenerId === '') {
            $this->requireConfig($config, 'listener_id');
        }
        if (! in_array($target, ['listener', 'accelerator'], true)) {
            $this->fail("Aliyun GA 不支持的部署目标: $target");
        }
        $certificateId = (string) $certRef;
        $domain = (string) ($config['domain'] ?? '');

        $this->guardSdk(function () use ($credentials, $target, $acceleratorId, $listenerId, $domain, $certificateId) {
            /** @var Ga $client */
            $client = $this->makeClient('ga', $credentials);
            $listenerIds = $target === 'accelerator'
                ? $this->findAcceleratorListeners($client, $acceleratorId)
                : [$listenerId];
            foreach ($listenerIds as $matchedListenerId) {
                if ($domain === '') {
                    $client->updateListener(new UpdateListenerRequest([
                        'regionId' => self::REGION_ID,
                        'listenerId' => $matchedListenerId,
                        'certificates' => [new certificates(['id' => $certificateId])],
                    ]));
                } else {
                    $this->updateListenerSniCertificate($client, $acceleratorId, $matchedListenerId, $domain, $certificateId);
                }
            }
        });
    }

    private function updateListenerSniCertificate(
        Ga $client,
        string $acceleratorId,
        string $listenerId,
        string $domain,
        string $certificateId,
    ): void {
        $additional = [];
        $nextToken = null;
        do {
            $response = $client->listListenerCertificates(new ListListenerCertificatesRequest([
                'regionId' => self::REGION_ID,
                'acceleratorId' => $acceleratorId,
                'listenerId' => $listenerId,
                'nextToken' => $nextToken,
                'maxResults' => 20,
            ]));
            $items = is_array($response->body?->certificates ?? null) ? $response->body->certificates : [];
            foreach ($items as $item) {
                if (! ($item->isDefault ?? false)) {
                    $additional[] = $item;
                }
            }
            $nextToken = $response->body->nextToken ?? null;
        } while ($items !== [] && is_string($nextToken) && $nextToken !== '');

        foreach ($additional as $item) {
            if ((string) ($item->certificateId ?? '') === $certificateId) {
                return;
            }
        }
        foreach ($additional as $item) {
            if ((string) ($item->domain ?? '') === $domain) {
                $client->updateAdditionalCertificateWithListener(new UpdateAdditionalCertificateWithListenerRequest([
                    'regionId' => self::REGION_ID,
                    'acceleratorId' => $acceleratorId,
                    'listenerId' => $listenerId,
                    'certificateId' => $certificateId,
                    'domain' => $domain,
                ]));

                return;
            }
        }

        $client->associateAdditionalCertificatesWithListener(new AssociateAdditionalCertificatesWithListenerRequest([
            'regionId' => self::REGION_ID,
            'acceleratorId' => $acceleratorId,
            'listenerId' => $listenerId,
            'certificates' => [new associateCertificates(['id' => $certificateId, 'domain' => $domain])],
        ]));
    }

    /** @return list<string> */
    private function findAcceleratorListeners(Ga $client, string $acceleratorId): array
    {
        $listenerIds = [];
        for ($page = 1; ; $page++) {
            $response = $client->listListeners(new ListListenersRequest([
                'regionId' => self::REGION_ID,
                'acceleratorId' => $acceleratorId,
                'pageNumber' => $page,
                'pageSize' => 50,
            ]));
            $items = is_array($response->body?->listeners ?? null) ? $response->body->listeners : [];
            foreach ($items as $item) {
                if (strcasecmp((string) ($item->protocol ?? ''), 'HTTPS') !== 0) {
                    continue;
                }
                $id = (string) ($item->listenerId ?? '');
                if ($id !== '') {
                    $listenerIds[] = $id;
                }
            }
            if (count($items) < 50) {
                break;
            }
        }

        return $listenerIds;
    }

    protected function makeClient(string $kind, array $credentials): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            'ga' => new Ga($this->aliyunConfig($credentials, 'ga.cn-hangzhou.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

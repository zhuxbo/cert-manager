<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Alb\V20200616\Alb;
use AlibabaCloud\SDK\Alb\V20200616\Models\AssociateAdditionalCertificatesWithListenerRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\AssociateAdditionalCertificatesWithListenerRequest\certificates as associateCertificates;
use AlibabaCloud\SDK\Alb\V20200616\Models\DissociateAdditionalCertificatesFromListenerRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\DissociateAdditionalCertificatesFromListenerRequest\certificates as dissociateCertificates;
use AlibabaCloud\SDK\Alb\V20200616\Models\GetListenerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\GetLoadBalancerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\ListListenerCertificatesRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\ListListenersRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\UpdateListenerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\UpdateListenerAttributeRequest\certificates;
use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetCertificateDetailRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 阿里云 ALB（应用型负载均衡，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 alb.UpdateListenerAttribute 把证书关联到指定 HTTPS/QUIC 监听器。
 *
 * 与 dcdn/vod 的差异：ALB 监听 API 直接吃**完整 CertIdentifier 字符串**（"{certId}-{region}"）作为
 * CertificateId，**不**拆 certId+region（对齐 certimate aliyun-alb 的 updateListenerCertificate）。故 bind 把
 * remote_cert_id 原样塞进 Certificates[].CertificateId，无需 ParsesCasCertIdentifier。
 *
 * 支持 listener 与 loadbalancer；后者列出 HTTPS/QUIC 监听并批量更新。SNI 由证书材料 opt-in 契约接入。
 */
class AliyunAlbDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'alb';
    }

    public function label(): string
    {
        return '阿里云 ALB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => false, 'default' => 'listener'],
            ['key' => 'load_balancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => false],
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
        // 上传器复用 deployer 的注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $this->casRegion($config));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 CertificateId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{region:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $target = strtolower((string) ($config['deploy_target'] ?? 'listener'));
        $listenerId = (string) ($config['listener_id'] ?? '');
        $loadBalancerId = (string) ($config['load_balancer_id'] ?? '');
        if ($target === 'listener' && $listenerId === '') {
            $this->requireConfig($config, 'listener_id');
        }
        if ($target === 'loadbalancer' && $loadBalancerId === '') {
            $this->requireConfig($config, 'load_balancer_id');
        }
        if (! in_array($target, ['listener', 'loadbalancer'], true)) {
            $this->fail("Aliyun ALB 不支持的部署目标: $target");
        }
        $certificatePem = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $certificateId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        $domain = (string) ($config['domain'] ?? '');

        $this->guardSdk(function () use ($credentials, $region, $target, $listenerId, $loadBalancerId, $domain, $certificatePem, $certificateId) {
            /** @var Alb $client */
            $client = $this->makeClient('alb', $credentials, $region);
            /** @var Cas $cas */
            $cas = $this->makeClient('cas', $credentials);
            $listenerIds = $target === 'loadbalancer'
                ? $this->findLoadBalancerListeners($client, $loadBalancerId)
                : [$listenerId];
            foreach ($listenerIds as $matchedListenerId) {
                if ($domain === '') {
                    $client->updateListenerAttribute(new UpdateListenerAttributeRequest([
                        'listenerId' => $matchedListenerId,
                        'certificates' => [new certificates(['certificateId' => $certificateId])],
                    ]));
                } else {
                    $this->updateListenerSniCertificate($client, $cas, $matchedListenerId, $domain, $certificateId, $certificatePem);
                }
            }
        });
    }

    private function updateListenerSniCertificate(
        Alb $client,
        Cas $cas,
        string $listenerId,
        string $domain,
        string $certificateId,
        string $certificatePem,
    ): void {
        $extensions = [];
        $nextToken = null;
        do {
            $response = $client->listListenerCertificates(new ListListenerCertificatesRequest([
                'nextToken' => $nextToken,
                'maxResults' => 100,
                'listenerId' => $listenerId,
                'certificateType' => 'Server',
            ]));
            $items = is_array($response->body?->certificates ?? null) ? $response->body->certificates : [];
            foreach ($items as $item) {
                if (($item->isDefault ?? false)
                    || strcasecmp((string) ($item->certificateType ?? ''), 'Server') !== 0
                    || strcasecmp((string) ($item->status ?? ''), 'Associated') !== 0) {
                    continue;
                }
                $extensions[] = $item;
            }
            $nextToken = $response->body->nextToken ?? null;
        } while ($items !== [] && is_string($nextToken) && $nextToken !== '');

        $newSans = $this->certificateSans($certificatePem);
        $alreadyAssociated = false;
        $toDissociate = [];
        foreach ($extensions as $extension) {
            $oldId = (string) ($extension->certificateId ?? '');
            if ($oldId === $certificateId) {
                $alreadyAssociated = true;

                continue;
            }
            $oldBareId = explode('-', $oldId, 2)[0];
            if (! ctype_digit($oldBareId)) {
                continue;
            }
            $detail = $cas->getCertificateDetail(new GetCertificateDetailRequest([
                'certificateId' => (int) $oldBareId,
            ]));
            $oldSans = array_values(array_filter(array_map('trim', explode(',', (string) ($detail->body?->domain ?? '')))));
            sort($oldSans);
            $matchedSans = $oldSans === $newSans && in_array($domain, $newSans, true);
            $notAfter = (int) ($detail->body?->notAfter ?? 0);
            if ($matchedSans || ($notAfter > 0 && $notAfter < time() * 1000)) {
                $toDissociate[] = $oldId;
            }
        }

        if (! $alreadyAssociated) {
            $this->waitForListenerReady($client, $listenerId);
            $client->associateAdditionalCertificatesWithListener(new AssociateAdditionalCertificatesWithListenerRequest([
                'listenerId' => $listenerId,
                'certificates' => [new associateCertificates(['certificateId' => $certificateId])],
            ]));
        }

        foreach (array_chunk($toDissociate, 10) as $chunk) {
            $this->waitForListenerReady($client, $listenerId);
            $client->dissociateAdditionalCertificatesFromListener(new DissociateAdditionalCertificatesFromListenerRequest([
                'listenerId' => $listenerId,
                'certificates' => array_map(
                    static fn (string $id): dissociateCertificates => new dissociateCertificates(['certificateId' => $id]),
                    $chunk,
                ),
            ]));
        }
    }

    /** @return list<string> */
    private function certificateSans(string $certificatePem): array
    {
        $parsed = @openssl_x509_parse($certificatePem);
        $sans = [];
        $subjectAlternativeName = is_array($parsed) ? (string) ($parsed['extensions']['subjectAltName'] ?? '') : '';
        foreach (explode(',', $subjectAlternativeName) as $entry) {
            $entry = trim($entry);
            if (str_starts_with(strtoupper($entry), 'DNS:')) {
                $sans[] = trim(substr($entry, 4));
            }
        }
        sort($sans);

        return $sans;
    }

    private function waitForListenerReady(Alb $client, string $listenerId): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $response = $client->getListenerAttribute(new GetListenerAttributeRequest(['listenerId' => $listenerId]));
            if ((string) ($response->body?->listenerStatus ?? '') !== 'Configuring') {
                return;
            }
            usleep(1000000);
        }
        $this->fail("Aliyun ALB 监听器长时间处于 Configuring: $listenerId");
    }

    /** @return list<string> */
    private function findLoadBalancerListeners(Alb $client, string $loadBalancerId): array
    {
        $client->getLoadBalancerAttribute(new GetLoadBalancerAttributeRequest([
            'loadBalancerId' => $loadBalancerId,
        ]));
        $listenerIds = [];
        foreach (['HTTPS', 'QUIC'] as $protocol) {
            $nextToken = null;
            do {
                $response = $client->listListeners(new ListListenersRequest([
                    'nextToken' => $nextToken,
                    'maxResults' => 100,
                    'loadBalancerIds' => [$loadBalancerId],
                    'listenerProtocol' => $protocol,
                ]));
                $items = is_array($response->body?->listeners ?? null) ? $response->body->listeners : [];
                foreach ($items as $item) {
                    $id = (string) ($item->listenerId ?? '');
                    if ($id !== '') {
                        $listenerIds[] = $id;
                    }
                }
                $nextToken = $response->body->nextToken ?? null;
            } while (is_string($nextToken) && $nextToken !== '');
        }

        return $listenerIds;
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            'alb' => new Alb($this->aliyunConfig($credentials, $region !== '' ? "alb.$region.aliyuncs.com" : 'alb.cn-hangzhou.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

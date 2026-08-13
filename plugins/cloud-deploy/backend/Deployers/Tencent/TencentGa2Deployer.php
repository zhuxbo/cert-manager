<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use TencentCloud\Common\CommonClient;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificateRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云全球加速 GA2（GlobalAccelerator v20250115，证书服务型）。
 *
 * 对齐 certimate tencentcloud-ga2（部署目标=监听器 listener）：证书先经 SSL 上传拿 CertificateId
 * （走 RemoteCertStore 去重），再把该 CertId 绑定到 GA2 监听器：
 *   1. ga2.DescribeListeners（按 listener-id 过滤）拿监听器当前 ServerCertificates。
 *   2. 对每个已绑证书，ssl.DescribeCertificate 取其 SAN —— 与新证书 SAN 完全一致的视为「同域名旧证书」
 *      需替换（从列表剔除）；其余保留。若新 CertId 已在列表中则直接返回（幂等）。
 *   3. ga2.ModifyListener 把 ServerCertificates 设为「保留的 + 新 CertId」。
 * 新证书 SAN 经 ssl.DescribeCertificate(新 CertId) 获取（证书服务型 bind 只有 CertId、无 PEM）。
 *
 * GA2 无模块化 PHP 包（tencentcloud/ga 未安装），用已装 tencentcloud/common 的 CommonClient（泛型 TC3
 * 签名）按 service=ga2 / version=2025-01-15 调 DescribeListeners / ModifyListener；SSL 走已装
 * tencentcloud/ssl 的 SslClient。复用 TencentSslUploader（storeKind=tencent_ssl）。
 *
 * config：accelerator_id（必填，全球加速实例 ID）/ listener_id（必填，监听器 ID）/ endpoint（选填，
 * 国际站可填 ga2.intl.tencentcloudapi.com，同时令 SSL 走 ssl.intl）。
 */
class TencentGa2Deployer extends AbstractDeployer
{
    private const GA2_SERVICE = 'ga2';

    private const GA2_VERSION = '2025-01-15';

    private const GA2_ENDPOINT = 'ga2.tencentcloudapi.com';

    private const GA2_INTL_ENDPOINT = 'ga2.intl.tencentcloudapi.com';

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'ga2';
    }

    public function label(): string
    {
        return '腾讯云全球加速 GA2';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'accelerator_id', 'label' => '全球加速实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听器 ID', 'type' => 'string', 'required' => true],
            ['key' => 'endpoint', 'label' => '接口端点（选填，国际站填 ga2.intl.tencentcloudapi.com）', 'type' => 'string', 'required' => false, 'destination' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（上传到 SSL 的 CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{accelerator_id:string,listener_id:string,endpoint?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $acceleratorId = (string) $this->requireConfig($config, 'accelerator_id');
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        $endpoint = isset($config['endpoint']) ? (string) $config['endpoint'] : '';
        $newCertId = (string) $certRef;

        // 1. 查监听器当前绑定的证书（SDK 调用包 guardSdk；业务判定在 guardSdk 外，避免被脱敏吞掉可读文案）
        $listenerSet = $this->guardSdk(function () use ($credentials, $endpoint, $acceleratorId, $listenerId) {
            $ga2 = $this->makeClient('ga2', $credentials + ['endpoint' => $endpoint]);
            $describeResp = $ga2->callJson('DescribeListeners', [
                'GlobalAcceleratorId' => $acceleratorId,
                'Filters' => [
                    ['Name' => 'listener-id', 'Values' => [$listenerId]],
                ],
                'Offset' => 0,
                'Limit' => 1,
            ]);

            return is_array($describeResp['ListenerSet'] ?? null) ? $describeResp['ListenerSet'] : [];
        });

        if ($listenerSet === []) {
            $this->fail("未找到监听器 $listenerId");
        }
        $currentCertIds = is_array($listenerSet[0]['ServerCertificates'] ?? null)
            ? $listenerSet[0]['ServerCertificates']
            : [];
        $currentCertIds = array_values(array_filter(array_map('strval', $currentCertIds), fn ($id) => $id !== ''));

        // 新证书已绑定 → 幂等返回
        if (in_array($newCertId, $currentCertIds, true)) {
            return;
        }

        // 2. 计算需保留的旧证书（剔除与新证书 SAN 一致的同域名旧证书）。SAN 查询是 SDK 调用，包 guardSdk。
        $keptCertIds = $this->guardSdk(fn (): array => $this->resolveKeptCertIds(
            $credentials, $endpoint, $currentCertIds, $newCertId,
        ));
        $serverCertificates = array_values(array_unique([...$keptCertIds, $newCertId]));

        // 3. 修改监听器证书
        $this->guardSdk(function () use ($credentials, $endpoint, $acceleratorId, $listenerId, $serverCertificates) {
            $ga2 = $this->makeClient('ga2', $credentials + ['endpoint' => $endpoint]);

            return $ga2->callJson('ModifyListener', [
                'GlobalAcceleratorId' => $acceleratorId,
                'ListenerId' => $listenerId,
                'ServerCertificates' => $serverCertificates,
            ]);
        });
    }

    /**
     * 取某云端证书的 SAN 列表（ssl.DescribeCertificate）；不存在/失败返回空数组。
     *
     * @return list<string>
     */
    protected function describeCertSans(SslClient $ssl, string $certId): array
    {
        if ($certId === '') {
            return [];
        }

        try {
            $req = new DescribeCertificateRequest;
            $req->deserialize(['CertificateId' => $certId]);
            $resp = $ssl->DescribeCertificate($req);
        } catch (Throwable $e) {
            // 证书不存在等 → 视为无 SAN（保留该旧证书，不误删）
            return [];
        }

        $san = $resp->getSubjectAltName();
        $out = [];
        foreach ($san as $domain) {
            $domain = trim((string) $domain);
            if ($domain !== '') {
                $out[] = $domain;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 计算需保留的旧证书 id（剔除与新证书 SAN 完全一致的同域名旧证书）。SAN 经 ssl.DescribeCertificate 取。
     *
     * @param  array<string,mixed>  $credentials
     * @param  list<string>  $currentCertIds
     * @return list<string>
     */
    protected function resolveKeptCertIds(array $credentials, string $endpoint, array $currentCertIds, string $newCertId): array
    {
        /** @var SslClient $ssl */
        $ssl = $this->makeClient('ssl', $credentials + ['endpoint' => $endpoint]);
        $newCertSans = $this->describeCertSans($ssl, $newCertId);

        $kept = [];
        foreach ($currentCertIds as $oldCertId) {
            $oldSans = $this->describeCertSans($ssl, $oldCertId);
            if ($oldSans !== [] && $newCertSans !== [] && $this->sansMatch($oldSans, $newCertSans)) {
                continue; // 同域名 → 替换
            }
            $kept[] = $oldCertId;
        }

        return $kept;
    }

    /**
     * 两组 SAN 是否为同一集合（无序比较）。
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    protected function sansMatch(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $endpoint = strtolower(rtrim(trim((string) ($credentials['endpoint'] ?? '')), '.'));
        if ($endpoint !== '' && ! in_array($endpoint, [self::GA2_ENDPOINT, self::GA2_INTL_ENDPOINT], true)) {
            throw new OutboundDestinationException('endpoint_not_allowed');
        }
        $intl = $endpoint === self::GA2_INTL_ENDPOINT;

        $http = new HttpProfile;
        $http->setReqTimeout(15);
        if ($kind === 'ga2' && $endpoint !== '') {
            $http->setEndpoint($endpoint);
        } elseif ($kind === 'ssl' && $intl) {
            // 国际站 SSL 走独立端点（对齐 certimate）
            $http->setEndpoint('ssl.intl.tencentcloudapi.com');
        }
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            'ga2' => new CommonClient(self::GA2_SERVICE, self::GA2_VERSION, $cred, '', $profile),
            'ssl' => new SslClient($cred, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

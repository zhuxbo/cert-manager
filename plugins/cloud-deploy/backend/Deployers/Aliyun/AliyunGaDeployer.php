<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Ga\V20191120\Ga;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateListenerRequest;
use AlibabaCloud\SDK\Ga\V20191120\Models\UpdateListenerRequest\certificates;
use Darabonba\OpenApi\Models\Config;
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
 * 简化：仅实现「指定 listener_id 设主证书」核心路径（对应 certimate DEPLOY_TARGET_LISTENER 且无 SNI）；
 * 不做遍历加速器所有 HTTPS 监听 / SNI 扩展证书（Associate/Update Additional）（留后续）。
 */
class AliyunGaDeployer extends AbstractDeployer
{
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
            ['key' => 'accelerator_id', 'label' => '全球加速实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听 ID', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 certificates[].id）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{accelerator_id:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // accelerator_id 为 certimate 部署到监听器的必填配置，先校验存在（UpdateListener 本身不发送它）
        $this->requireConfig($config, 'accelerator_id');
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        $certificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $listenerId, $certificateId) {
            /** @var Ga $client */
            $client = $this->makeClient('ga', $credentials);
            $client->updateListener(new UpdateListenerRequest([
                'regionId' => self::REGION_ID,
                'listenerId' => $listenerId,
                'certificates' => [new certificates(['id' => $certificateId])],
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $ak = $credentials['access_key_id'] ?? '';
        $sk = $credentials['access_key_secret'] ?? '';

        return match ($kind) {
            'cas' => new Cas(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => 'cas.aliyuncs.com',
            ])),
            'ga' => new Ga(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => 'ga.cn-hangzhou.aliyuncs.com',
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}

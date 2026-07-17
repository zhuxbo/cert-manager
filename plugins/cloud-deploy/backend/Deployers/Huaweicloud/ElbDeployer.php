<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 华为云弹性负载均衡 ELB（证书服务型，region 维度，绑定监听器）。
 *
 * 对齐 certimate deployer huaweicloud-elb 的 DEPLOY_TARGET_LISTENER 核心路径：
 *   1. 经 HuaweiElbUploader 创建 ELB 证书拿 certificate.id（store_kind=huawei_elb:{region}，走 RemoteCertStore 去重）。
 *      （上传器内先 IAM 反查 projectId 再 CreateCertificate。）
 *   2. bind 用 global 凭证 IAM 反查 projectId，ShowListener 后 UpdateListener（PUT /v3/{project_id}/elb/listeners/{id}）
 *      把 default_tls_container_ref 指向新证书 id。
 *
 * ELB 为 region 服务（elb.{region}.myhuaweicloud.com，basic 凭证 + projectId）。region + listener_id 必填。
 *
 * 与 certimate 对齐的取舍：certimate ELB 支持 loadbalancer/listener/certificate 三类 DeployTarget，listener 路径
 * 还会处理 SNI（同 SAN 证书替换）。本端点**仅实现 listener + default_tls_container_ref 核心路径**（exact，单监听器），
 * 不做 loadbalancer 的「列举监听器批量」、不做 SNI 替换、不做 certificate 的「替换既有证书」——与插件其他端点
 * 「仅核心绑定路径」口径一致。如需绑负载均衡器下全部监听器，可逐个监听器各配一个 target。
 */
class ElbDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'elb';
    }

    public function label(): string
    {
        return '华为云弹性负载均衡 ELB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听器 ID', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // ELB 证书 region 维度：据 config.region 构造（空 config 探活时 region 空，storeKind 回落 default、不抛）。
        $region = isset($config['region']) ? (string) $config['region'] : '';

        return new HuaweiElbUploader(
            $region,
            fn (array $credentials): object => $this->makeClient('iam', $credentials),
            fn (array $credentials, string $projectId): object => $this->makeClient('elb', $credentials, $region, $projectId),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（ELB certificate.id）
     * @param  array<string,mixed>  $credentials
     * @param  array{region:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $listenerId, $certId) {
            $projectId = $this->resolveProjectId($credentials, $region);

            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('elb', $credentials, $region, $projectId);

            // ShowListener（确认存在；core 路径不处理 SNI）。
            $client->get("/v3/$projectId/elb/listeners/$listenerId");

            // UpdateListener：default_tls_container_ref 指向新证书。
            $client->put("/v3/$projectId/elb/listeners/$listenerId", [
                'listener' => [
                    'default_tls_container_ref' => $certId,
                ],
            ]);
        });
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
            'elb' => new HuaweicloudRestClient(
                $this->regionalHost('elb', $region),
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

<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeHostDeployRecordDetailRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云对象存储 COS（证书服务型 + 一键部署）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 SSL（ssl/v20191205）**DeployCertificateInstance** 以 ResourceType='cos' +
 * InstanceIdList=["{region}|{bucket}|{domain}"] 一键部署到 COS 自定义域名（区别于
 * cdn/clb 的 per-product 绑定接口）。这是腾讯 SSL 统一部署模式：UploadCertificate 拿
 * CertId → DeployCertificateInstance(ResourceType, InstanceIdList) → 轮询部署任务详情。
 *
 * InstanceId 拼装规则（certimate tencentcloud-cos 实测）：`{region}|{bucket}|{domain}`，
 * 三段管道符拼接，domain 为 COS 自定义加速域名（不支持泛域名）。
 *
 * 异步处理：DeployCertificateInstance 返回 DeployRecordId，certimate 轮询
 * DescribeHostDeployRecordDetail 直到 succeeded+failed==total（任一 failed 即报错）。
 * 本端点对齐——bind 内 guardSdk 后 pollDeployRecord() 有界轮询（最多 maxPollAttempts 次、
 * 每次间隔 pollIntervalSeconds 秒，防无限等）；轮询超时不算失败（部署任务已提交，
 * 腾讯侧异步落地），仅记一次"未在等待窗口内完成"——抛业务错误让上层可见但不丢任务。
 *
 * 简化（相对 certimate）：① 不做部署前 DescribeHostCosInstanceList 去重预检（certimate
 * 为规避 issue#897 重复部署报错，本插件 RemoteCertStore 已按 fingerprint 去重 CertId，
 * 重复 DeployCertificateInstance 同实例腾讯侧幂等覆盖，风险低）；② Status 固定 1（启用）。
 *
 * region 维度：DeployCertificateInstance 接口本身是全局 SSL 服务（client 空 region 即可，
 * region 仅作为 InstanceId 拼装的一段），与 CLB/WAF 的 region 维度 client 不同。
 */
class TencentCosDeployer extends AbstractDeployer
{
    /** 轮询部署任务最大次数（超过即视为等待窗口超时，不阻塞无限等）。 */
    protected int $maxPollAttempts = 30;

    /** 每次轮询间隔秒数（certimate 用 10s；测试子类置 0 免真实 sleep）。 */
    protected int $pollIntervalSeconds = 10;

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'cos';
    }

    public function label(): string
    {
        return '腾讯云 COS';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'bucket', 'label' => '存储桶名', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名（不支持泛域名）', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 腾讯 SSL 上传是全局服务（空 region）
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{region:string,bucket:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $domain = (string) $this->requireConfig($config, 'domain');

        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials);

        // SDK 调用单独 guardSdk（异常脱敏）；返回的 recordId 用于后续轮询
        $recordId = $this->guardSdk(function () use ($client, $region, $bucket, $domain, $certRef) {
            $req = new DeployCertificateInstanceRequest;
            $req->deserialize([
                'CertificateId' => $certRef,
                'ResourceType' => 'cos',
                // InstanceId 三段拼接：region|bucket|domain（certimate 实测格式）
                'InstanceIdList' => ["$region|$bucket|$domain"],
                'Status' => 1,
            ]);

            return $client->DeployCertificateInstance($req)->getDeployRecordId();
        });

        // 部署任务 ID 缺失（极少数实例无需异步落地）→ 触发即成功，不轮询
        if ($recordId === null || $recordId === '') {
            return;
        }

        // 轮询在 guardSdk 之外：每次 describe 的 SDK 异常各自脱敏，但终态/失败判定的业务错误需透传
        $this->pollDeployRecord($client, (string) $recordId);
    }

    /**
     * 有界轮询部署任务详情，直到所有子任务完成（succeeded+failed==total）。
     * 任一 failed 抛错；超过 maxPollAttempts 仍未完成抛"等待超时"（任务已提交，仅未在窗口内落地）。
     * 每次 DescribeHostDeployRecordDetail 单独 guardSdk 脱敏，终态判定的业务错误由 poller 在 guard 外抛。
     *
     * @param  SslClient  $client
     */
    protected function pollDeployRecord(object $client, string $recordId): void
    {
        TencentDeployRecordPoller::poll(
            fn () => $this->guardSdk(function () use ($client, $recordId) {
                $req = new DescribeHostDeployRecordDetailRequest;
                $req->deserialize(['DeployRecordId' => $recordId, 'Limit' => 200]);

                return $client->DescribeHostDeployRecordDetail($req);
            }),
            $this->maxPollAttempts,
            $this->pollIntervalSeconds,
            fn (int $seconds) => $this->sleep($seconds),
        );
    }

    /** 抽出 sleep 便于测试子类 override 为 no-op（避免真实等待）。 */
    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL DeployCertificateInstance 为全局服务，region 取空
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

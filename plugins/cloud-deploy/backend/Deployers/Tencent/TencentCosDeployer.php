<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\ResumesRemoteJob;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeHostCosInstanceListRequest;
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
 * bind 先按 CertificateId + bucket + domain 查询 DescribeHostCosInstanceList，已绑定且为
 * ENABLED 时直接收敛，规避重复部署错误；预检失败与 Certimate 一致不阻断部署。
 * 创建部署任务后在短窗内查询一次，未终态则持久化 DeployRecordId，由 resumePoll
 * 续查同一任务，不重复发起部署。Status 固定 1（启用）。
 *
 * region 维度：DeployCertificateInstance 接口本身是全局 SSL 服务（client 空 region 即可，
 * region 仅作为 InstanceId 拼装的一段），与 CLB/WAF 的 region 维度 client 不同。
 */
class TencentCosDeployer extends AbstractDeployer implements HasPollBudget, ResumesRemoteJob
{
    use UsesTencentEndpoint;

    /** SSL client 请求超时（秒）= §G2.3 预算 T 单一来源（长轮询专用；非轮询腾讯端点保持 15）。 */
    public const CLIENT_TIMEOUT_SECONDS = 10;

    /** bind 短窗首查次数（G2 压窗）：未终态即抛 DeployPollPendingException 走重试/sweep-B 续查同一 recordId。 */
    protected int $maxPollAttempts = 1;

    /** resumePoll 续查次数（无前置建任务，预算宽松）。 */
    protected int $resumePollAttempts = 3;

    /** 每次轮询间隔秒数（测试子类置 0 免真实 sleep）。 */
    protected int $pollIntervalSeconds = 5;

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
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
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
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{region:string,bucket:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $region = (string) $this->requireConfig($config, 'region');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $domain = (string) $this->requireConfig($config, 'domain');

        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials);

        if ($this->isAlreadyBound($client, (string) $certRef, $bucket, $domain)) {
            return;
        }

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
        if ($recordId <= 0) {
            return;
        }

        // 轮询在 guardSdk 之外：每次 describe 的 SDK 异常各自脱敏，但终态/失败判定的业务错误需透传
        $this->pollDeployRecord($client, (string) $recordId, $this->maxPollAttempts);
    }

    /**
     * G2 续查：重试/sweep-B 复扫续查**同一** DeployRecordId（不重建部署任务）。全成功收敛；失败子任务抛
     * DeployBusinessException；窗口耗尽抛 DeployPollPendingException（同 recordId 续期）。
     */
    public function resumePoll(string $remoteJobId, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials);
        $this->pollDeployRecord($client, $remoteJobId, $this->resumePollAttempts);
    }

    public function pollBudget(): PollBudget
    {
        // N_upload=1（SSL UploadCertificate）+ N_pre=2（COS 去重预检 + 创建部署任务）
        return new PollBudget(
            clientTimeoutSeconds: self::CLIENT_TIMEOUT_SECONDS,
            uploadCalls: 1,
            preIterCalls: 2,
            bindIterations: $this->maxPollAttempts,
            intervalSeconds: $this->pollIntervalSeconds,
        );
    }

    /**
     * 查询当前证书在 COS 的已绑定实例。预检是去重优化，查询失败时按 Certimate
     * 语义继续创建部署任务，不把读路异常误判为已绑定。
     */
    private function isAlreadyBound(object $client, string $certificateId, string $bucket, string $domain): bool
    {
        $limit = 100;
        $offset = 0;

        try {
            do {
                $response = $this->guardSdk(function () use ($client, $certificateId, $limit, $offset) {
                    $req = new DescribeHostCosInstanceListRequest;
                    $req->deserialize([
                        'OldCertificateId' => $certificateId,
                        'ResourceType' => 'cos',
                        'IsCache' => 0,
                        'Offset' => $offset,
                        'Limit' => $limit,
                    ]);

                    return $client->DescribeHostCosInstanceList($req);
                });

                $instances = $response->getInstanceList() ?? [];
                foreach ($instances as $instance) {
                    if ((string) $instance->getBucket() === $bucket
                        && (string) $instance->getDomain() === $domain
                        && (string) $instance->getStatus() === 'ENABLED') {
                        return true;
                    }
                }

                $offset += $limit;
            } while (count($instances) === $limit);
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * 有界轮询部署任务详情，直到所有子任务完成（succeeded+failed==total）。任一 failed 抛业务错误；
     * 窗口耗尽抛 DeployPollPendingException（携 recordId、guardSdk 之外）走重试/sweep-B 续查。
     * 每次 DescribeHostDeployRecordDetail 单独 guardSdk 脱敏。
     *
     * @param  SslClient  $client
     */
    protected function pollDeployRecord(object $client, string $recordId, int $attempts): void
    {
        TencentDeployRecordPoller::poll(
            fn () => $this->guardSdk(function () use ($client, $recordId) {
                $req = new DescribeHostDeployRecordDetailRequest;
                $req->deserialize(['DeployRecordId' => $recordId, 'Limit' => 200]);

                return $client->DescribeHostDeployRecordDetail($req);
            }),
            $recordId,
            $attempts,
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
        // 长轮询端点：请求超时收至 10s（§G2.3 预算 T；非轮询腾讯端点保持 15）。
        $http->setReqTimeout(self::CLIENT_TIMEOUT_SECONDS);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL DeployCertificateInstance 为全局服务，region 取空
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}

<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Cert;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\CloudDeploy\Deployers\Contracts\CertificateDeliveryMode;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployerInterface;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Contracts\PersistsOpaqueInlineJobId;
use Plugins\CloudDeploy\Deployers\Contracts\PreparesCertUploaderForJob;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Plugins\CloudDeploy\Deployers\Contracts\ResumesRemoteJob;
use Plugins\CloudDeploy\Deployers\Contracts\SelectsCertificateDeliveryMode;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Services\RemoteCertStore;
use Plugins\CloudDeploy\Support\TenantConsistency;
use Throwable;

class CloudDeployJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    // tries=5（原 3）：G2 后长轮询超窗改抛 DeployPollPendingException 走重试通道（占 attempt），
    // 需更多 attempt 覆盖云端异步落地 + 吸收 freeze release。**不加 maxExceptions**——maxExceptions
    // 会在首个 pending 异常终结重试链（主控裁决，见 development.md 五节「CloudDeployJob tries」）。
    public int $tries = 5;

    // 单次 handle 上限 55s < worker --timeout 60、< retry_after 600；SIGALRM 优雅退出（依赖 pcntl，
    // 与既有 --timeout 60 同前提）。各长轮询 deployer 的 bind 最坏耗时 ≤50s（CloudDeployPollBudgetTest 锁死）。
    public int $timeout = 55;

    /** pending jobId 的数据库 TTL：> sweep-B 7 天节流 + freeze/延迟余量；每次 catch 续期。 */
    private const PENDING_TTL_DAYS = 10;

    /** 动态内联端点的 bind 已接触私钥；异常消息一律不允许离开 Job。 */
    private const DYNAMIC_INLINE_FAILURE_MESSAGE = '动态证书部署失败，请检查云端配置或稍后重试';

    /** failed() 无法重新判定交付模式时，绝不回退到可能含私钥的原始异常。 */
    private const UNRESOLVED_FAILURE_MESSAGE = '部署重试耗尽，无法确认错误详情';

    public function __construct(
        public int $targetId,
        public int $certId,
        public string $trigger = 'auto',
        public bool $force = false,
    ) {}

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * WithoutOverlapping 按 target 串行，消除 sweep×trigger 并发双推。
     * dontRelease()：拿不到锁即丢弃本次（不 release、不增 attempts，避免与 freeze release 叠加耗尽 tries=3）；
     * 另一同 target Job 正在处理，丢弃安全，下次 sweep 再来。经 HasUpgradeFreezeMiddleware::middleware()
     * 合并到 SkipWhenUpgradeFrozen 之后。
     *
     * @return array<int,object>
     */
    public function customMiddleware(): array
    {
        return [(new WithoutOverlapping((string) $this->targetId))->dontRelease()->expireAfter(60)];
    }

    public function handle(): void
    {
        $target = CloudDeployTarget::withoutGlobalScopes()->find($this->targetId);
        $cert = Cert::find($this->certId);
        $access = $target ? CloudDeployAccess::withoutGlobalScopes()->find($target->access_id) : null;

        if (! $target || ! $cert || ! $access) {
            $this->skipLog('target_missing', 'target/cert/access 已不存在');

            return;
        }

        // 第 4 处租户校验（Job 无请求 scope）
        if (! TenantConsistency::check($target->user_id, $target->access_id, $target->order_id)) {
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'tenant_mismatch', '租户不一致');

            return;
        }

        // DB 真值确认 active（绕 retrieved 副作用；dispatch 后 cert 可能失效）
        $status = DB::table('certs')->where('id', $cert->id)->value('status');
        if ($status !== 'active') {
            $this->skipLog('not_active', "证书状态 $status 非 active");

            return;
        }

        // 前置 fail-closed：缺私钥（自带 CSR）/ SM2（国密标准接口不支持）。
        // 更新 last_cert_id 标记“已处理”，配合 sweep 主条件 last_cert_id != latest 防每天重扫。
        if (empty($cert->private_key)) {
            $target->update(['last_cert_id' => $cert->id, 'last_status' => 'failed', 'last_error' => '证书无私钥，无法推送', 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'missing_private_key', '证书无私钥，无法推送；请使用系统生成 CSR 的证书');
            $this->notifyBusinessFailure($target, $access, 'missing_private_key');

            return;
        }
        if (strtolower((string) $cert->encryption_alg) === 'sm2') {
            $target->update(['last_cert_id' => $cert->id, 'last_status' => 'failed', 'last_error' => '国密证书暂不支持', 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'unsupported_algorithm', '国密 SM2 证书暂不支持推送云平台');
            $this->notifyBusinessFailure($target, $access, 'unsupported_algorithm');

            return;
        }

        $chain = $cert->intermediate_cert; // accessor；缺链返回 null
        if (empty($chain)) {
            // 缺链 fail closed：记 missing_chain，靠 Chain::created 补触发（CloudChainBackfillJob）
            $target->update(['last_cert_id' => $cert->id, 'last_status' => 'failed', 'last_error' => '缺中间证书', 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'missing_chain', '缺中间证书，等待补全');

            return;
        }

        // 幂等：已成功推过这张证书（非强制）
        if (! $this->force && (int) $target->last_cert_id === (int) $cert->id && $target->last_status === 'success') {
            return;
        }

        $deployer = app(Registry::class)->resolveDeployer($access->provider, $target->product);
        $credentials = $access->credentials; // encrypted:array → decrypted
        $config = $target->config ?? [];
        $deliveryMode = $deployer instanceof SelectsCertificateDeliveryMode
            ? $deployer->certificateDeliveryMode($config)
            : ($deployer->usesRemoteCertStore() ? CertificateDeliveryMode::RemoteStore : CertificateDeliveryMode::Inline);

        // G2：只从 target 数据库字段读 pending；无效内容先清库再走 bind。
        $pending = $this->readPending($target, (int) $cert->id, $deployer, $deliveryMode);
        $remoteCertId = is_array($pending) && is_string($pending['remote_cert_id'] ?? null) ? $pending['remote_cert_id'] : null;

        try {
            if (is_array($pending) && $deployer instanceof ResumesRemoteJob) {
                // 续查**同一** jobId（不重建云端任务）：成功收敛 / 终态失败转业务终态 / 仍 pending 再抛续期
                $deployer->resumePoll((string) $pending['job_id'], $credentials, $config);
                $target->update(['pending_job' => null, 'last_cert_id' => $cert->id, 'last_status' => 'success', 'last_error' => null, 'last_deployed_at' => now()]);
                $this->writeLog($target, $cert, $access, 'success', true, $remoteCertId, null, null);

                return;
            }

            if ($deliveryMode === CertificateDeliveryMode::RemoteStore) {
                // 透传 config：region 维度的上传器（SLB）需据 region 构造（endpoint + storeKind/cert_id 编码）
                $uploader = $deployer instanceof PreparesCertUploaderForJob
                    ? $deployer->certUploaderForJob($config, $credentials)
                    : $deployer->certUploader($config);
                $remoteCertId = app(RemoteCertStore::class)->ensure(
                    $uploader, $access->id, $target->user_id, $cert->id, (string) $cert->fingerprint,
                    (string) $cert->cert, (string) $cert->private_key, (string) $chain, $credentials,
                );
                // Opt-in deployer 仅获 leaf + 中间链做 SAN/资源匹配；私钥仍只进入 uploader。
                $bindRef = $deployer instanceof ReceivesRemoteCertificateMaterial
                    ? ['remote_cert_id' => $remoteCertId, 'cert' => (string) $cert->cert, 'chain' => (string) $chain]
                    : $remoteCertId;
                $deployer->bind($bindRef, $credentials, $config);
            } else {
                $remoteCertId = null;
                $deployer->bind(
                    ['cert' => (string) $cert->cert, 'key' => (string) $cert->private_key, 'chain' => (string) $chain],
                    $credentials, $config,
                );
            }

            $target->update([
                'pending_job' => null,
                'last_cert_id' => $cert->id, 'last_status' => 'success',
                'last_error' => null, 'last_deployed_at' => now(),
            ]);
            $this->writeLog($target, $cert, $access, 'success', true, $remoteCertId, null, null);
        } catch (DeployPollPendingException $e) {
            $pendingJobId = is_array($pending) ? $pending['job_id'] : $e->remoteJobId;

            // 动态内联 bind 已接触私钥：默认拒绝把上游 jobId 落库。只有显式 opt-in 且能把标识
            // 规范化为安全 opaque ID 的端点可以沿用 pending/resume 链；APIGW 等其余端点仍 fail closed。
            if ($deployer instanceof SelectsCertificateDeliveryMode && $deliveryMode === CertificateDeliveryMode::Inline) {
                $pendingJobId = $deployer instanceof PersistsOpaqueInlineJobId
                    ? $deployer->canonicalOpaqueInlineJobId((string) $pendingJobId)
                    : null;
                if ($pendingJobId === null) {
                    $msg = self::DYNAMIC_INLINE_FAILURE_MESSAGE;
                    $target->update([
                        'pending_job' => null,
                        'last_status' => 'failed', 'last_cert_id' => $cert->id,
                        'last_error' => $msg, 'last_deployed_at' => now(),
                    ]);
                    $this->writeLog($target, $cert, $access, 'failed', true, null, 'business_error', $msg);
                    $this->notifyBusinessFailure($target, $access, 'business_error');

                    return;
                }
            }

            // 云端任务已提交、未在窗口内达终态：持久化 jobId 供重试/sweep-B 续查（不重建），占 attempt 退避重试。
            // 复用 failed 态（不加新枚举/不迁移）；写 last_cert_id + last_deployed_at 使 sweep 走 B（7 天）而非 A（每天）
            $target->update([
                'pending_job' => [
                    'job_id' => $pendingJobId,
                    'cert_id' => (int) $cert->id,
                    'remote_cert_id' => is_string($remoteCertId) ? $remoteCertId : null,
                    'expires_at' => now()->addDays(self::PENDING_TTL_DAYS)->timestamp,
                ],
                'last_status' => 'failed', 'last_cert_id' => $cert->id,
                'last_error' => '云端部署任务处理中，待确认', 'last_deployed_at' => now(),
            ]);
            $this->writeLog($target, $cert, $access, 'failed', false, null, 'poll_pending', '云端部署任务处理中，待确认');

            throw $e; // 占 attempt 走 backoff 重试（下次 resumePoll 续查同一 jobId）
        } catch (DeployBusinessException $e) {
            $msg = $this->safeExceptionMessage($e, $deployer, $deliveryMode);
            $target->update(['pending_job' => null, 'last_status' => 'failed', 'last_cert_id' => $cert->id, 'last_error' => mb_substr($msg, 0, 255), 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'business_error', $msg);
            $this->notifyBusinessFailure($target, $access, 'business_error');

            return;
        } catch (Throwable $e) {
            // 瞬态失败：**不清 pending**（若在 resumePoll 阶段网络抖动，pending 须留给下次续查）
            $msg = $this->safeExceptionMessage($e, $deployer, $deliveryMode);
            // 标记 last_cert_id：让重试耗尽后 sweep 走条件 B（7 天节流）而非条件 A（每天），防瞬态失败每天 dispatch + 每天发邮件
            $target->update(['last_status' => 'failed', 'last_cert_id' => $cert->id, 'last_error' => mb_substr($msg, 0, 255), 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', false, null, 'deploy_error', $msg);

            throw $e; // 触发退避重试
        }
    }

    /**
     * 读取且严格校验数据库 pending jobId；任一字段或续查能力无效时先清库。
     *
     * @return array{job_id:string,cert_id:int,remote_cert_id:?string,expires_at:int}|null
     */
    private function readPending(CloudDeployTarget $target, int $certId, DeployerInterface $deployer, CertificateDeliveryMode $deliveryMode): ?array
    {
        if ($target->getRawOriginal('pending_job') === null) {
            return null;
        }

        $pending = $target->pending_job;
        $valid = is_array($pending)
            && is_string($pending['job_id'] ?? null)
            && trim($pending['job_id']) !== ''
            && is_int($pending['cert_id'] ?? null)
            && $pending['cert_id'] > 0
            && $pending['cert_id'] === $certId
            && array_key_exists('remote_cert_id', $pending)
            && ($pending['remote_cert_id'] === null || is_string($pending['remote_cert_id']))
            && is_int($pending['expires_at'] ?? null)
            && $pending['expires_at'] > 0
            && $pending['expires_at'] > now()->timestamp
            && $deployer instanceof ResumesRemoteJob;

        if (! $valid) {
            $target->update(['pending_job' => null]);

            return null;
        }

        if ($deployer instanceof SelectsCertificateDeliveryMode && $deliveryMode === CertificateDeliveryMode::Inline) {
            $canonicalJobId = $deployer instanceof PersistsOpaqueInlineJobId
                ? $deployer->canonicalOpaqueInlineJobId($pending['job_id'])
                : null;
            if ($canonicalJobId === null) {
                $target->update(['pending_job' => null]);

                return null;
            }
            $pending['job_id'] = $canonicalJobId;
        }

        return $pending;
    }

    /**
     * 业务终态失败通知 target 所属 user（context 白名单 4 字段：不放 deployer message 原文，防 AK 外溢）。
     * 复用既有 code cloud_deploy_failed（Builder/模板/偏好均在位，零新增）；error_code 区分场景。
     */
    private function notifyBusinessFailure(CloudDeployTarget $target, CloudDeployAccess $access, string $errorCode): void
    {
        app(NotificationCenter::class)->dispatch(
            new NotificationIntent(
                'cloud_deploy_failed', 'user', (int) $target->user_id,
                ['product' => $target->product, 'domain' => $target->config['domain'] ?? '-', 'access_name' => $access->name ?? '-', 'error_code' => $errorCode],
            )
        );
    }

    public function failed(Throwable $e): void
    {
        // poll_pending 耗尽：区分 error_code（通知文案通用，上下文表明「任务已提交云端待确认」降误报感）；
        // **不清 pending_job**——留给 sweep-B 续查同一 jobId（收敛链关键，§G2.2 / §G2.5）。
        $errorCode = $e instanceof DeployPollPendingException ? 'poll_pending' : 'retries_exhausted';

        $target = CloudDeployTarget::withoutGlobalScopes()->find($this->targetId);
        $msg = $this->safeExceptionMessageForTarget($e, $target);
        if ($target) {
            // G4：补写 last_deployed_at——failed() 仅末次 attempt 兑现（SIGALRM 击杀链前几次 attempt
            // handle catch 不跑、target 不写），不补则新 target 的 NULL 落 sweep 条件 A/B 双盲区。
            // 设 last_cert_id：重试耗尽后 sweep 走条件 B（7 天节流），不每天重扫、不每天发邮件。
            $target->update(['last_status' => 'failed', 'last_cert_id' => $this->certId, 'last_error' => mb_substr($msg, 0, 255), 'last_deployed_at' => now()]);

            // 设计 §8.3：重试耗尽写一条终态 is_final=true 失败行（handle 每次重试只写 is_final=false）
            $cert = Cert::find($this->certId);
            $access = CloudDeployAccess::withoutGlobalScopes()->find($target->access_id);
            if ($cert && $access) {
                $this->writeLog($target, $cert, $access, 'failed', true, null, $errorCode, $msg);
                // 重试耗尽通知 target 所属 user（context 白名单：不放 message 原文，避免 AK 外溢）
                $this->notifyBusinessFailure($target, $access, $errorCode);
            } else {
                $this->skipLog($errorCode, $msg);
            }
        } else {
            $this->skipLog($errorCode, $msg);
        }
        Log::error('[cloud-deploy.failed] 推送重试耗尽', ['target' => $this->targetId, 'cert' => $this->certId, 'message' => $msg]);
    }

    private function safeExceptionMessage(Throwable $e, DeployerInterface $deployer, CertificateDeliveryMode $deliveryMode): string
    {
        if ($deployer instanceof SelectsCertificateDeliveryMode && $deliveryMode === CertificateDeliveryMode::Inline) {
            return self::DYNAMIC_INLINE_FAILURE_MESSAGE;
        }

        return $e->getMessage() ?: $e::class;
    }

    private function safeExceptionMessageForTarget(Throwable $e, ?CloudDeployTarget $target): string
    {
        if ($target === null) {
            return self::UNRESOLVED_FAILURE_MESSAGE;
        }

        try {
            $access = CloudDeployAccess::withoutGlobalScopes()->find($target->access_id);
            if ($access === null) {
                return self::UNRESOLVED_FAILURE_MESSAGE;
            }

            $deployer = app(Registry::class)->resolveDeployer($access->provider, $target->product);
            $config = $target->config ?? [];
            $deliveryMode = $deployer instanceof SelectsCertificateDeliveryMode
                ? $deployer->certificateDeliveryMode($config)
                : ($deployer->usesRemoteCertStore() ? CertificateDeliveryMode::RemoteStore : CertificateDeliveryMode::Inline);

            return $this->safeExceptionMessage($e, $deployer, $deliveryMode);
        } catch (Throwable) {
            // 注册表或动态模式计算失败时，无法排除异常已接触私钥；fail closed。
            return self::UNRESOLVED_FAILURE_MESSAGE;
        }
    }

    private function skipLog(string $code, string $message): void
    {
        CloudDeployLog::create([
            'user_id' => 0, 'target_id' => $this->targetId, 'order_id' => 0, 'cert_id' => $this->certId,
            'provider' => '-', 'product' => '-', 'trigger' => $this->trigger, 'status' => 'failed',
            'attempt_no' => (int) $this->attempts(), 'is_final' => true, 'error_code' => $code,
            'message' => $message, 'deployed_at' => now(),
        ]);
    }

    private function writeLog(CloudDeployTarget $target, Cert $cert, CloudDeployAccess $access, string $status, bool $isFinal, ?string $remoteCertId, ?string $errorCode, ?string $message): void
    {
        CloudDeployLog::create([
            'user_id' => $target->user_id, 'target_id' => $target->id, 'order_id' => $target->order_id, 'cert_id' => $cert->id,
            'provider' => $access->provider, 'product' => $target->product,
            'resource_summary' => $target->config['domain'] ?? null, 'access_name' => $access->name,
            'trigger' => $this->trigger, 'status' => $status,
            'attempt_no' => (int) $this->attempts(), 'is_final' => $isFinal,
            'remote_cert_id' => $remoteCertId, 'error_code' => $errorCode,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'deployed_at' => now(),
        ]);
    }
}

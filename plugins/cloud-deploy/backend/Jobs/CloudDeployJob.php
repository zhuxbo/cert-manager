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
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
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

    public int $tries = 3;

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

            return;
        }
        if (strtolower((string) $cert->encryption_alg) === 'sm2') {
            $target->update(['last_cert_id' => $cert->id, 'last_status' => 'failed', 'last_error' => '国密证书暂不支持', 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'unsupported_algorithm', '国密 SM2 证书暂不支持推送云平台');

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

        try {
            if ($deployer->usesRemoteCertStore()) {
                // 透传 config：region 维度的上传器（SLB）需据 region 构造（endpoint + storeKind/cert_id 编码）
                $remoteCertId = app(RemoteCertStore::class)->ensure(
                    $deployer->certUploader($target->config ?? []), $access->id, $target->user_id, $cert->id, (string) $cert->fingerprint,
                    (string) $cert->cert, (string) $cert->private_key, (string) $chain, $credentials,
                );
                $deployer->bind($remoteCertId, $credentials, $target->config ?? []);
            } else {
                $remoteCertId = null;
                $deployer->bind(
                    ['cert' => (string) $cert->cert, 'key' => (string) $cert->private_key, 'chain' => (string) $chain],
                    $credentials, $target->config ?? [],
                );
            }

            $target->update([
                'last_cert_id' => $cert->id, 'last_status' => 'success',
                'last_error' => null, 'last_deployed_at' => now(),
            ]);
            $this->writeLog($target, $cert, $access, 'success', true, $remoteCertId, null, null);
        } catch (DeployBusinessException $e) {
            $msg = $e->getMessage() ?: $e::class;
            $target->update(['last_status' => 'failed', 'last_cert_id' => $cert->id, 'last_error' => mb_substr($msg, 0, 255), 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', true, null, 'business_error', $msg);

            return;
        } catch (Throwable $e) {
            $msg = $e->getMessage() ?: $e::class;
            // 标记 last_cert_id：让重试耗尽后 sweep 走条件 B（7 天节流）而非条件 A（每天），防瞬态失败每天 dispatch + 每天发邮件
            $target->update(['last_status' => 'failed', 'last_cert_id' => $cert->id, 'last_error' => mb_substr($msg, 0, 255), 'last_deployed_at' => now()]);
            $this->writeLog($target, $cert, $access, 'failed', false, null, 'deploy_error', $msg);

            throw $e; // 触发退避重试
        }
    }

    public function failed(Throwable $e): void
    {
        $msg = $e->getMessage() ?: $e::class;
        $target = CloudDeployTarget::withoutGlobalScopes()->find($this->targetId);
        if ($target) {
            // 设 last_cert_id：重试耗尽后 sweep 走条件 B（7 天节流），不每天重扫、不每天发邮件
            $target->update(['last_status' => 'failed', 'last_cert_id' => $this->certId, 'last_error' => mb_substr($msg, 0, 255)]);

            // 设计 §8.3：重试耗尽写一条终态 is_final=true 失败行（handle 每次重试只写 is_final=false）
            $cert = Cert::find($this->certId);
            $access = CloudDeployAccess::withoutGlobalScopes()->find($target->access_id);
            if ($cert && $access) {
                $this->writeLog($target, $cert, $access, 'failed', true, null, 'retries_exhausted', $msg);

                // 重试耗尽通知 target 所属 user（context 白名单：不放 message 原文，避免 AK 外溢）
                app(NotificationCenter::class)->dispatch(
                    new NotificationIntent(
                        'cloud_deploy_failed', 'user', (int) $target->user_id,
                        ['product' => $target->product, 'domain' => $target->config['domain'] ?? '-', 'access_name' => $access->name ?? '-', 'error_code' => 'retries_exhausted'],
                    )
                );
            } else {
                $this->skipLog('retries_exhausted', $msg);
            }
        } else {
            $this->skipLog('retries_exhausted', $msg);
        }
        Log::error('[cloud-deploy.failed] 推送重试耗尽', ['target' => $this->targetId, 'cert' => $this->certId, 'message' => $msg]);
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

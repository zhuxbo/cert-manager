<?php

namespace App\Services\Notification;

use App\Models\Admin;
use App\Services\Notification\DTOs\NotificationIntent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 通用运维/健康 admin 告警共享件（包0 前置，E1~E6 监控命令与后续 F/G/H 复用）。
 *
 * 职责：把「运维告警」标准化为一次经 NotificationCenter 的 system_alert 通知投递，附带
 * Cache 状态指纹去重，避免持续异常态刷屏。本服务只做传输层，不背聚合/阈值判定（归调用方）。
 * 异步加密（ShouldBeEncrypted）、afterCommit、onQueue('notifications') 全部由 NotificationJob
 * 继承，本服务无需自理。携密不入库的机制化兜底在 SystemAlertNotificationBuilder。
 *
 * 关键契约（勿改）：
 *  - 去重时序固定「预检 → 解析 admin（无则不占键）→ dispatch 成功后才置键」，对齐
 *    ReconcilePendingCommand::alertMaxedOrders / FundAuditCommand::sendAlertEmail 的
 *    「先确认可达、后置去重标记」范式：dispatch 失败绝不占键，否则整个 TTL 静默丢告警。
 *  - 置键经 DB::afterCommit 包裹：无事务时 Laravel 立即执行闭包（console 调用方语义不变）；
 *    事务内调用（如 TaskJob 外层 DB::transaction 内告警）回滚时键不落——与 dispatch 的
 *    NotificationJob afterCommit 语义对齐，消除「回滚丢告警（Job 被丢弃）但键已占 → 整 TTL 静默」。
 *  - admin_email 写端接线：构造 intent 时必须传 admin_email = adminEmail ?: admin->email
 *    （镜像 FundAuditCommand::sendAlertEmail 的 $targetEmail 写端），否则 site.adminEmail 为
 *    运维分发别名（非任何 Admin 登录邮箱）时，MailChannel 回落 Admin::first()->email 投错地址。
 *  - 去重语义 = 状态指纹：同 dedupeKey 下指纹相同 → TTL 内不重发；指纹变化 → 立即再发并覆盖；
 *    TTL 到期 → 重提醒一次。恢复即清键（clearDedupe）是各调用方 healthy 分支义务。
 *  - dedupeTtlHours 契约：必须 ≥ 3× 调用方巡检周期（防 TTL≈周期时去重形同虚设）。
 *  - 并发说明：get→put 非原子是有意取舍——占位后置正是为「dispatch 失败不占键」。调用方已
 *    不全是单 cron 串行（Deploy callback 失败告警为并发 HTTP 入口），竞态最坏后果是同一订单
 *    重复一封告警（fail-open 到无害方向），可接受。
 */
class SystemAlert
{
    private const CACHE_PREFIX = 'system_alert:';

    /**
     * 发送一条运维告警（去重 + 状态指纹）。
     *
     * @param  string  $category  告警类别标识（如 ca_credentials / payment_health），仅作来源标记
     * @param  string  $title  标题（外部可控文本由 Builder 截断 ≤100 + Blade 转义）
     * @param  string  $message  正文（Builder 截断 ≤500 + Blade 转义）
     * @param  array<string, mixed>  $details  结构化上下文（Builder 标量化 + denylist + PEM 掩码兜底）
     * @param  string|null  $dedupeKey  去重键；null/'' 表示不去重（每次都发）
     * @param  int  $dedupeTtlHours  去重 TTL 小时数（契约：必须 ≥ 3× 调用方巡检周期）
     * @param  string|null  $fingerprint  固定指纹；传入则覆盖内容指纹，防计数/偏差型 churn 击穿去重
     * @return bool 是否实际派发了告警（false = 去重跳过 / 无 admin / dispatch 失败）
     */
    public function send(
        string $category,
        string $title,
        string $message,
        array $details = [],
        ?string $dedupeKey = null,
        int $dedupeTtlHours = 24,
        ?string $fingerprint = null
    ): bool {
        $useDedupe = $dedupeKey !== null && $dedupeKey !== '';
        $fingerprintValue = null;

        // 1. 去重预检：指纹相同（状态未变）→ TTL 内不重发；指纹变化 → 放行再发。
        if ($useDedupe) {
            $fingerprintValue = $fingerprint
                ?? sha1((string) json_encode([$category, $title, $message, $details]));

            if (Cache::get(self::CACHE_PREFIX.$dedupeKey) === $fingerprintValue) {
                return false;
            }
        }

        // 2. 解析 admin（单一源 Admin::resolveAlertTarget，原 4 份内联之一）：
        //    site.adminEmail 优先；未配置时查找首个邮箱非空的 Admin；无归属 Admin/目标邮箱则返回 false，不占键。
        //    写端接线：$targetEmail（= adminEmail ?: admin->email，由 resolveAlertTarget 返回）必须显式入 context
        //    的 admin_email（Builder 的 email 映射只是读端），site.adminEmail 为分发别名时防 MailChannel 投错登录邮箱。
        ['admin' => $admin, 'email' => $targetEmail] = Admin::resolveAlertTarget();

        if (! $admin || ! $targetEmail) {
            Log::warning('[system_alert] 未找到管理员邮箱，跳过告警', [
                'category' => $category,
                'title' => $title,
            ]);

            return false;
        }

        try {
            app(NotificationCenter::class)->dispatch(new NotificationIntent(
                'system_alert',
                'admin',
                $admin->id,
                [
                    'category' => $category,
                    'title' => $title,
                    'message' => $message,
                    'details' => $details,
                    'admin_email' => $targetEmail,
                ]
            ));
        } catch (Throwable $e) {
            Log::warning('[system_alert] 告警派发失败', [
                'category' => $category,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // 4. dispatch 正常返回后才置键（键值 = 状态指纹快照）。
        //    经 DB::afterCommit 包裹：无事务时立即执行；事务内调用随提交落键、回滚即丢弃，
        //    与 dispatch 的 NotificationJob afterCommit 语义对齐（回滚时 Job 与键同生共死）。
        if ($useDedupe) {
            $key = self::CACHE_PREFIX.$dedupeKey;
            DB::afterCommit(
                fn () => Cache::put($key, $fingerprintValue, now()->addHours($dedupeTtlHours))
            );
        }

        return true;
    }

    /**
     * 清除去重键——各监控命令 healthy 分支义务：恢复后再异常立即告警。
     */
    public function clearDedupe(string $dedupeKey): void
    {
        if ($dedupeKey === '') {
            return;
        }

        Cache::forget(self::CACHE_PREFIX.$dedupeKey);
    }
}

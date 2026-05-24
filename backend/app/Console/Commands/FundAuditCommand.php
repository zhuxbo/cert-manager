<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\FundAudit\FundInvariants;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 资金审计全量对账命令（资金安全四道网的"事后发现层"）
 *
 * 调度：每天 03:00 跑一次（错开 schedule:auto-renew 00:00）
 *
 * 责任划分：物理阻断由 DB 唯一索引（funds.pay_sn / transactions.type+transaction_id）+
 * Fund.status CAS UPDATE 承担；本命令仅做"事后发现"——发现违反 → 邮件告警 + 可选
 * freeze 涉事用户。SSL 证书业务有 30 天撤回期，每天一次足够。
 *
 * 设计要点：
 *
 * - 命令始终 return 0：违反不算崩溃，靠邮件告警驱动人工介入；命令崩溃（DB 连不上等）
 *   才返回 1 让外层重试
 * - 邮件链路走 NotificationCenter（同 TaskJob::failed 模式），code=finance_audit
 * - Log::error 兜底：模板未配置时 NotificationCenter 仅 logSkip，落日志保证可观测
 * - --freeze-on-violation：仅 freeze L1/L3/L4 涉事 user（L2 是事件唯一不针对 user）
 */
class FundAuditCommand extends Command
{
    protected $signature = 'finance:audit {--freeze-on-violation : 发现违反时自动 freeze 涉事用户}';

    protected $description = '资金审计全量对账（4 条 invariant），违反则邮件告警';

    /**
     * 单条 invariant 输出 rows 上限（避免日志爆炸）
     */
    private const ROWS_DISPLAY_LIMIT = 10;

    public function handle(FundInvariants $invariants): int
    {
        try {
            $violations = $invariants->all();
        } catch (Throwable $e) {
            // 命令本身崩溃（DB 连不上等）→ 返回 1 让外层重试
            $this->error('finance:audit 执行失败: '.$e->getMessage());
            Log::error('finance:audit crashed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }

        if (empty($violations)) {
            $this->info('资金审计校验通过');

            return self::SUCCESS;
        }

        $this->reportViolations($violations);
        $this->sendAlertEmail($violations);

        if ($this->option('freeze-on-violation')) {
            $this->freezeAffectedUsers($violations);
        }

        // 命令本身不 fail（schedule 不 retry），由邮件告警驱动人工介入
        return self::SUCCESS;
    }

    /**
     * 输出违反详情到 console + 系统日志
     */
    private function reportViolations(array $violations): void
    {
        foreach ($violations as $v) {
            $this->error('['.$v['layer'].'] '.$v['message']);

            $rows = $v['rows'] ?? [];
            $shown = array_slice($rows, 0, self::ROWS_DISPLAY_LIMIT);
            foreach ($shown as $row) {
                $this->line('  '.json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            if (count($rows) > self::ROWS_DISPLAY_LIMIT) {
                $this->line('  ...（共 '.count($rows).' 行，仅显示前 '.self::ROWS_DISPLAY_LIMIT.' 行）');
            }
        }

        Log::error('finance:audit violations', [
            'count' => count($violations),
            'violations' => $violations,
        ]);
    }

    /**
     * 邮件告警给 site.adminEmail（同 TaskJob::failed 模式）
     *
     * NotificationCenter 在模板未配置时仅 logSkip 不抛错，前面 Log::error 已落兜底。
     */
    private function sendAlertEmail(array $violations): void
    {
        try {
            $adminEmail = get_system_setting('site', 'adminEmail');
            $admin = null;
            if ($adminEmail) {
                $admin = Admin::where('email', $adminEmail)->first();
            }
            $admin ??= Admin::first();

            if (! $admin?->email) {
                $this->warn('未找到管理员邮箱，跳过邮件告警（已落 Log::error）');

                return;
            }

            $targetEmail = $adminEmail ?: $admin->email;

            // 提取关键信息减小通知体积（rows 限制 10 行避免邮件超长）
            $compact = array_map(function ($v) {
                $rows = array_slice($v['rows'] ?? [], 0, self::ROWS_DISPLAY_LIMIT);

                return [
                    'layer' => $v['layer'],
                    'message' => $v['message'],
                    'rows' => $rows,
                    'rows_total' => count($v['rows'] ?? []),
                ];
            }, $violations);

            $intent = new NotificationIntent(
                'finance_audit',
                'admin',
                $admin->id,
                [
                    'admin_email' => $targetEmail,
                    'violation_count' => count($violations),
                    'violations' => $compact,
                    'detected_at' => now()->toDateTimeString(),
                ],
                ['mail']
            );

            app(NotificationCenter::class)->dispatch($intent);
        } catch (Throwable $e) {
            // 邮件失败不影响命令本身（Log::error 已兜底）
            $this->warn('邮件告警发送失败: '.$e->getMessage());
            Log::warning('finance:audit alert email failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 把违反项中涉及的 user_id 全部置为 status=0（禁用）
     *
     * 仅来源于 L1/L3/L4 的 rows.user_id（L2 rows 是 type+transaction_id，无 user_id）。
     * 单表 update，不涉及资金，不开事务。
     */
    private function freezeAffectedUsers(array $violations): void
    {
        $userIds = collect($violations)
            ->flatMap(fn ($v) => collect($v['rows'] ?? [])->pluck('user_id')->filter())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($userIds)) {
            return;
        }

        DB::table('users')
            ->whereIn('id', $userIds)
            ->update(['status' => 0]);

        $this->warn('已 freeze '.count($userIds).' 个涉事用户');
        Log::warning('finance:audit froze users', ['user_ids' => $userIds]);
    }
}

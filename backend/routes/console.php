<?php

use App\Utils\UpgradeFreezeLock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// 升级 freeze 期间跳过定时任务，避免 migrate 中途运行 Command 引发错误
$skipWhenFrozen = fn () => UpgradeFreezeLock::isFrozen();

// M6：schedule 命令非零退出时落 Log::error（弱信号兜底可见性——多数命令自 catch 返 SUCCESS，
// 主信号是 M1 心跳 + M3 拨测）。仅挂 validate/auto-renew/reconcile-pending（backup/finance/E 系自带告警）。
$logScheduleFailure = fn (string $name) => function () use ($name) {
    Log::error("[schedule.failed] $name 非零退出");
};

// 默认的 inspire 命令
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly()->skip($skipWhenFrozen);

// SSL证书管理系统定时任务调度
// 证书验证任务 - 每分钟执行（生产由 1 分钟 cron 调 schedule:run，sub-minute 不会触发；如需 30 秒需改用常驻 schedule:work）
// 互斥由 ValidateCommand 内部 Cache::add + Cache::put 心跳续期实现（支持长任务，不在此处加 withoutOverlapping）
Schedule::command('schedule:validate')
    ->everyMinute()
    ->skip($skipWhenFrozen)
    ->name('validate-certificates')
    ->description('自动验证处理中的证书')
    ->onFailure($logScheduleFailure('schedule:validate'));

// 无验证信息订单同步 - 每天 9/15/21 点执行
// 处理 dcv 或 validation 为空的 processing/approving 订单（codesign/docsign/smime 等无 DCV 产品），
// 这类订单被 schedule:validate 的「dcv 且 validation 都非空」查询排除，需独立兜底同步；与其互为补集不重叠
Schedule::command('schedule:sync')
    ->cron('0 9,15,21 * * *')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('sync-no-dcv-orders')
    ->description('同步无验证信息（dcv/validation 为空）的处理中订单');

// 证书过期通知任务 - 每天上午9点执行
Schedule::command('schedule:expire')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('expire-certificates')
    ->description('处理证书过期通知');

// 缓存清理任务 - 每天凌晨2点执行
Schedule::command('schedule:purge')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('purge-expired-data')
    ->description('清理过期数据');

// CNAME委托DNS清理任务 - 每天凌晨6点执行
Schedule::command('delegation:cleanup')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('cleanup-delegation-dns')
    ->description('清理非processing状态订单的委托DNS记录');

// CNAME委托健康周巡检 - 每周一 07:00 执行（错开 cleanup 06:00 / expire 09:00 / balance-forecast 周一 09:30）
// 无 active 证书的失效委托清理；两阶段+熔断防 dnsTools 系统性停摆误删。
// 委托失效的用户通知由 schedule:auto-renew 在真正发起续签/重签前检查并触发。
Schedule::command('delegation:check')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('check-delegation-health')
    ->description('CNAME委托健康周巡检（无用记录清理 + 停摆熔断）');

// 自动续费/重签任务 - 每天0点执行，commit 分散在0~8点
Schedule::command('schedule:auto-renew')
    ->dailyAt('00:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('auto-renew-certificates')
    ->description('自动续费/重签即将到期的证书')
    ->onFailure($logScheduleFailure('schedule:auto-renew'));

// 自动部署/签发持续未解决失败提醒 - 每天 08:00（错开 auto-renew 00:00 / purge 02:00 / stuck-orders 06:30 / expire 09:00）
// 事件驱动告警在失败发生时按订单去重发一封；本命令基于「订单最后一条上报仍为 failure」状态判定，
// 复用同一 per-order 去重键 + 固定指纹，由 TTL 裁决「TTL 内一封、到期仍未解决再一封」，覆盖客户端触顶静默期
Schedule::command('schedule:deploy-failure-reminder')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('deploy-failure-reminder')
    ->description('自动部署/签发持续未解决失败提醒（订单终态或证书过期后停止）');

// 余额前瞻预警 - 每周一 09:30 执行（未来 30 天自动续费余额不足则每用户一封，预估上限）
// 周一 09:30：错开 auto-renew 00:00 / backup 02:00 / audit 03:00，且避开 schedule:expire 的 09:00
// （withoutOverlapping 按命令名互斥、不挡不同命令同刻并发）；weekly 天然「每用户每周期一封」去重
Schedule::command('schedule:balance-forecast')
    ->weeklyOn(1, '09:30')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('balance-forecast')
    ->description('未来 30 天自动续费余额前瞻预警（预估上限）');

// 数据库备份任务 - 每天凌晨2点执行，保留 7 天
Schedule::command('schedule:backup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('backup-database')
    ->description('备份数据库核心数据（剔除日志/运行时表与 certs 敏感列）');

// 资金审计全量对账 - 每天凌晨 3 点执行（错开 auto-renew 00:00 / backup 02:00）
// 违反 → 邮件告警 + Log::error 兜底；命令本身不 fail（不被 retry）
Schedule::command('finance:audit')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('finance-audit')
    ->description('资金审计全量对账（4 条 invariant），违反则邮件告警');

// 已扣费但尚未提交上游的 pending 订单对账
Schedule::command('schedule:reconcile-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('reconcile-pending-orders')
    ->description('对账并重发卡在 pending 且无 api_id 的订单 commit')
    ->onFailure($logScheduleFailure('schedule:reconcile-pending'));

// T1 僵尸 executing 任务重派兜底 - 每 5 分钟（恢复类，非心跳；freeze 期 skip、结束后追平，
// 与 reconcile-pending 同挂 skip 无偏态窗口）
Schedule::command('schedule:sweep-stale-tasks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('sweep-stale-tasks')
    ->description('重派卡死的僵尸 executing 任务（缺陷1/2 兜底）')
    ->onFailure($logScheduleFailure('schedule:sweep-stale-tasks'));

// T6 ACME 订单对账 - 每 5 分钟（镜像 reconcile-pending；恢复类，freeze 期 skip、结束后追平）
Schedule::command('schedule:reconcile-acme')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('reconcile-acme-orders')
    ->description('对账并重发卡在 pending 且无 api_id 的 ACME 订单 commit')
    ->onFailure($logScheduleFailure('schedule:reconcile-acme'));

// O4 孤儿单清理 - 每小时（恢复类清理非紧急，freeze 期 skip、结束后追平；hourly 保证每 5min 的 T5
// 转人工先于 pending 收尾接手，防两自动化拆台）。unpaid delete 默认开、pending 退款默认关（arm-switch）。
Schedule::command('schedule:sweep-orphan-orders')
    ->hourly()
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('sweep-orphan-orders')
    ->description('清理 channel=auto 卡死的 unpaid（删除恢复）/ pending 到顶（退款）孤儿单')
    ->onFailure($logScheduleFailure('schedule:sweep-orphan-orders'));

// ============================================================
// 健康监控命令群——freeze 期一律 skip
// ============================================================

// E1 上游 CA 凭证健康心跳 - 每 15 分钟（只读探测，仅鉴权维度告警）
Schedule::command('schedule:ca-healthcheck')
    ->everyFifteenMinutes()
    ->skip($skipWhenFrozen)
    ->name('ca-healthcheck')
    ->description('上游 CA 凭证健康心跳（凭证失效告警）');

// E2 产品属性漂移同步 - 每天 04:30（错开 auto-renew 00:00 / backup 02:00 / finance 03:00）
Schedule::command('schedule:import-product')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('import-product')
    ->description('逐来源同步产品属性漂移（仅 update，失败聚合告警）');

// E3 充值渠道健康 - 每天 05:00（支付证书 notAfter + 配置完整性）
Schedule::command('schedule:payment-health')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('payment-health')
    ->description('充值渠道支付证书到期与配置完整性监控');

// E4 服务器时钟监控 - 每小时（HTTP Date 头 + 法定人数 ≥2 源一致）
Schedule::command('schedule:clock-check')
    ->hourly()
    ->skip($skipWhenFrozen)
    ->name('clock-check')
    ->description('服务器时钟偏差监控（≥2 源一致才告警）');

// E5 failed_jobs 阈值监控 - 每天 05:30（24h 窗口增量计数）
Schedule::command('schedule:failed-jobs-check')
    ->dailyAt('05:30')
    ->skip($skipWhenFrozen)
    ->name('failed-jobs-check')
    ->description('failed_jobs 24h 窗口增量计数超阈告警');

// E5 配套：failed_jobs 清理 - 每周（保留 prune_retention_hours 小时，默认 14 天）
Schedule::command('queue:prune-failed', ['--hours' => (int) config('monitoring.failed_jobs.prune_retention_hours', 336)])
    ->weekly()
    ->skip($skipWhenFrozen)
    ->name('prune-failed-jobs')
    ->description('清理过期 failed_jobs（保留 14 天供排障）');

// E6 卡单聚合告警 - 每天 06:30（processing/approving 分档，只读）
Schedule::command('schedule:stuck-orders')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('stuck-orders')
    ->description('聚合 processing/approving 长期卡单告警（按 validation_type 分档）');

// ============================================================
// H1 升级看门狗（自愈命令）——与上方所有命令有意不对称：
//   - evenInMaintenanceMode()：artisan down 期 scheduler 默认跳过事件，自愈命令必须绕过；
//   - **不挂** ->skip($skipWhenFrozen)：升级冻结期恰是它要收拾残局的时刻，挂了就自废武功。
// 每分钟探测 status.json：running 且超时且升级进程已死 → up + unfreeze + 告警（PID 活则不动作）。
// ============================================================
Schedule::command('upgrade:watchdog')
    ->everyMinute()
    ->evenInMaintenanceMode()
    ->name('upgrade-watchdog')
    ->description('升级进程死后自动解除维护/冻结（SIGKILL/OOM 自愈）');

// ============================================================
// M1 调度器心跳（P0-4.1）——继 watchdog 后第二个有意 freeze 存活者：
//   - evenInMaintenanceMode()：与 watchdog 同款，freeze/down 全窗跳动，unfreeze 后即新鲜；
//   - **不挂** ->skip($skipWhenFrozen)：挂了则 freeze 期心跳停 → /api/health 判 stale 503
//     → M3 拨测/外部监控在每次升级窗误报「scheduler 死」。
// 写 Cache::forever('schedule:heartbeat')，供 /api/health 判活；health 侧 freeze 期不评估 stale（双保险）。
// ============================================================
Schedule::command('schedule:heartbeat')
    ->everyMinute()
    ->evenInMaintenanceMode()
    ->name('scheduler-heartbeat')
    ->description('调度器心跳（写 Cache，供 /api/health 判活）');

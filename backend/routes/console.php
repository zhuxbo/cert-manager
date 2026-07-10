<?php

use App\Utils\UpgradeFreezeLock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// 升级 freeze 期间跳过定时任务，避免 migrate 中途运行 Command 引发错误
$skipWhenFrozen = fn () => UpgradeFreezeLock::isFrozen();

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
    ->description('自动验证处理中的证书');

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

// 自动续费/重签任务 - 每天0点执行，commit 分散在0~8点
Schedule::command('schedule:auto-renew')
    ->dailyAt('00:00')
    ->withoutOverlapping()
    ->skip($skipWhenFrozen)
    ->name('auto-renew-certificates')
    ->description('自动续费/重签即将到期的证书');

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
    ->description('对账并重发卡在 pending 且无 api_id 的订单 commit');

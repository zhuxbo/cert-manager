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
// 证书验证任务 - 每分钟执行
Schedule::command('schedule:validate')
    ->everyMinute()
    ->skip($skipWhenFrozen)
    ->name('validate-certificates')
    ->description('自动验证处理中的证书');

// 证书过期通知任务 - 每天上午9点执行
Schedule::command('schedule:expire')
    ->dailyAt('09:00')
    ->skip($skipWhenFrozen)
    ->name('expire-certificates')
    ->description('处理证书过期通知');

// 缓存清理任务 - 每天凌晨2点执行
Schedule::command('schedule:purge')
    ->dailyAt('02:00')
    ->skip($skipWhenFrozen)
    ->name('purge-expired-data')
    ->description('清理过期数据');

// CNAME委托DNS清理任务 - 每天凌晨6点执行
Schedule::command('delegation:cleanup')
    ->dailyAt('06:00')
    ->skip($skipWhenFrozen)
    ->name('cleanup-delegation-dns')
    ->description('清理非processing状态订单的委托DNS记录');

// 自动续费/重签任务 - 每天0点执行，commit 分散在0~8点
Schedule::command('schedule:auto-renew')
    ->dailyAt('00:00')
    ->skip($skipWhenFrozen)
    ->name('auto-renew-certificates')
    ->description('自动续费/重签即将到期的证书');

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

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// 默认的 inspire 命令
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// SSL证书管理系统定时任务调度
// 证书验证任务 - 每分钟执行
Schedule::command('schedule:validate')
    ->everyMinute()
    ->name('validate-certificates')
    ->description('自动验证处理中的证书');

// 证书过期通知任务 - 每天上午9点执行
Schedule::command('schedule:expire')
    ->dailyAt('09:00')
    ->name('expire-certificates')
    ->description('处理证书过期通知');

// 缓存清理任务 - 每天凌晨2点执行
Schedule::command('schedule:purge')
    ->dailyAt('02:00')
    ->name('purge-expired-data')
    ->description('清理过期数据');

// CNAME委托健康检查任务 - 每天执行一次
Schedule::command('delegation:check')
    ->dailyAt('05:30')
    ->name('check-cname-delegations')
    ->description('检查CNAME委托有效性');

// CNAME委托DNS清理任务 - 每天凌晨6点执行
Schedule::command('delegation:cleanup')
    ->dailyAt('06:00')
    ->name('cleanup-delegation-dns')
    ->description('清理非processing状态订单的委托DNS记录');

// 自动续费/重签任务 - 每小时执行
Schedule::command('schedule:auto-renew')
    ->hourly()
    ->name('auto-renew-certificates')
    ->description('自动续费/重签即将到期的证书');

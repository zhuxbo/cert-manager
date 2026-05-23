<?php

namespace App\Providers;

use App\Models\Cert;
use App\Observers\CertObserver;
use App\Services\Binary\BinaryLocator;
use App\Services\LogBuffer;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 加载所有辅助函数文件
        $helperPath = app_path('Helpers');
        foreach (glob($helperPath.'/*.php') as $file) {
            require_once $file;
        }

        // 数据库 session timezone 与 app.timezone 同源（仅 mysql）
        // mysql 不强制依赖时区表（mysql_tzinfo_to_sql），统一用数字偏移。
        $tz = config('app.timezone');
        $offsetSec = (new \DateTimeZone($tz))->getOffset(new \DateTime);
        $sign = $offsetSec >= 0 ? '+' : '-';
        $absSec = abs($offsetSec);
        $numericOffset = sprintf('%s%02d:%02d', $sign, intdiv($absSec, 3600), intdiv($absSec, 60) % 60);

        config(['database.connections.mysql.timezone' => $numericOffset]);

        // 系统二进制定位器（探测 + memoize，全进程复用）
        $this->app->singleton(BinaryLocator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cert::observe(CertObserver::class);

        // 队列任务执行完毕后刷新日志缓冲区
        Queue::after(function (JobProcessed $event) {
            LogBuffer::flush();
        });

        Queue::failing(function (JobFailed $event) {
            LogBuffer::flush();
        });

        // 异常后 release 重试场景（不触发 after/failing）
        Queue::exceptionOccurred(function (JobExceptionOccurred $event) {
            LogBuffer::flush();
        });

        // CLI (Artisan) 场景兜底刷新
        $this->app->terminating(function () {
            LogBuffer::flush();
        });
    }
}

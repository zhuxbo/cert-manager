<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 手动触发的"立即备份"异步任务。
 * 进度写入 Cache（key = BackupService::JOB_CACHE_PREFIX.token），前端轮询查看状态。
 */
class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $token,
        public int $adminId
    ) {}

    public function handle(BackupService $service): void
    {
        $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 3600);

        if (! $lock->get()) {
            $service->setJobProgress($this->token, [
                'status' => 'failed',
                'message' => '已有备份/恢复任务在执行，请稍后再试',
                'admin_id' => $this->adminId,
                'updated_at' => now()->toDateTimeString(),
            ]);

            return;
        }

        try {
            $service->setJobProgress($this->token, [
                'status' => 'running',
                'stage' => 'dumping',
                'message' => '正在导出数据库...',
                'admin_id' => $this->adminId,
                'updated_at' => now()->toDateTimeString(),
            ]);

            // keep 参数由 BackupCommand 自行回落到 config('database.backup.keep_days')
            $exitCode = Artisan::call('schedule:backup');
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                $service->setJobProgress($this->token, [
                    'status' => 'failed',
                    'message' => '备份失败',
                    'output' => $output,
                    'admin_id' => $this->adminId,
                    'updated_at' => now()->toDateTimeString(),
                ]);

                return;
            }

            $service->setJobProgress($this->token, [
                'status' => 'completed',
                'message' => '备份完成',
                'output' => $output,
                'admin_id' => $this->adminId,
                'updated_at' => now()->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            Log::error('CreateBackupJob failed', ['error' => $e->getMessage()]);
            $service->setJobProgress($this->token, [
                'status' => 'failed',
                'message' => '备份失败: '.$e->getMessage(),
                'admin_id' => $this->adminId,
                'updated_at' => now()->toDateTimeString(),
            ]);
        } finally {
            $lock->release();
        }
    }
}

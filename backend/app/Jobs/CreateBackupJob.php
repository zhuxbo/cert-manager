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
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    /** 备份只受进程管道 idle timeout 限制，不设置总时长上限。 */
    public int $timeout = 0;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(
        public string $token,
        public int $adminId,
    ) {}

    public function handle(BackupService $service): void
    {
        $service->setJobProgress($this->token, [
            'status' => 'running',
            'stage' => 'dumping',
            'message' => '正在导出数据库...',
            'admin_id' => $this->adminId,
            'updated_at' => now()->toDateTimeString(),
        ]);

        try {
            $exitCode = Artisan::call('schedule:backup');
            $output = trim(Artisan::output());
            if ($exitCode !== 0) {
                $service->setJobProgress($this->token, [
                    'status' => 'failed',
                    'stage' => 'error',
                    'message' => '备份失败',
                    'output' => $output,
                    'admin_id' => $this->adminId,
                    'updated_at' => now()->toDateTimeString(),
                ]);

                return;
            }

            $service->setJobProgress($this->token, [
                'status' => 'completed',
                'stage' => 'done',
                'message' => '备份完成',
                'output' => $output,
                'admin_id' => $this->adminId,
                'updated_at' => now()->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            Log::error('CreateBackupJob failed', ['exception' => $e::class]);
            $service->setJobProgress($this->token, [
                'status' => 'failed',
                'stage' => 'error',
                'message' => '备份失败',
                'admin_id' => $this->adminId,
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
    }
}

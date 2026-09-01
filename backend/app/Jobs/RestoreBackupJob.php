<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Services\Backup\BackupService;
use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    /** 原生管道自行执行 idle timeout；0 禁用 Laravel worker 的总时长 alarm。 */
    public int $timeout = 0;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(
        public string $token,
        public string $backupId,
        public bool $allowSchemaDifference,
        public string $actor,
    ) {}

    public function handle(): void
    {
        $backupService = app(BackupService::class);
        $lastStage = 'preflight';

        try {
            app(AtomicRestoreService::class)->restore(
                new RestoreRequest(
                    $this->backupId,
                    $this->allowSchemaDifference,
                    $this->actor,
                ),
                function (array $payload) use ($backupService, &$lastStage): void {
                    $stage = (string) ($payload['stage'] ?? 'preflight');
                    $lastStage = $stage;
                    $backupService->setJobProgress($this->token, [
                        'status' => $stage === 'complete' ? 'completed' : 'running',
                        'stage' => $stage,
                        'message' => (string) ($payload['message'] ?? ''),
                        'metrics' => is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [],
                        'backup_id' => $this->backupId,
                        'actor' => $this->actor,
                        'updated_at' => (string) ($payload['updated_at'] ?? now()->toDateTimeString()),
                    ]);
                },
            );
        } catch (Throwable $e) {
            Log::error('RestoreBackupJob failed', [
                'stage' => $lastStage,
                'exception' => $e::class,
                'backup_id' => $this->backupId,
            ]);
            $backupService->setJobProgress($this->token, [
                'status' => 'failed',
                'stage' => $lastStage,
                'message' => $this->failureMessage($e),
                'backup_id' => $this->backupId,
                'actor' => $this->actor,
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
    }

    private function failureMessage(Throwable $e): string
    {
        foreach ([
            '数据库已验证、运行时清理待重试',
            '恢复预检需要确认 Schema 差异',
            '已有备份/恢复任务在执行，请稍后再试',
        ] as $safe) {
            if (str_contains($e->getMessage(), $safe)) {
                return '恢复失败: '.$safe;
            }
        }

        return '恢复失败，请使用同步 CLI 重试并查看详细错误';
    }
}

<?php

declare(strict_types=1);

use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();
    $this->atomicRestore = new Task10AtomicRestoreJobFake;
    $this->app->instance(AtomicRestoreService::class, $this->atomicRestore);
});

it('只把 DTO 交给统一服务并将服务进度写入状态缓存', function (): void {
    $job = new RestoreBackupJob(
        token: str_repeat('a', 32),
        backupId: 'backup_20260830_120000',
        allowSchemaDifference: true,
        actor: 'admin:7',
    );

    $job->handle();

    $progress = app(BackupService::class)->getJobProgress(str_repeat('a', 32));
    expect($this->atomicRestore->requests)->toHaveCount(1)
        ->and($this->atomicRestore->requests[0]->backupId)->toBe('backup_20260830_120000')
        ->and($this->atomicRestore->requests[0]->allowSchemaDifference)->toBeTrue()
        ->and($this->atomicRestore->requests[0]->actor)->toBe('admin:7')
        ->and($progress['status'])->toBe('completed')
        ->and($progress['stage'])->toBe('complete')
        ->and($progress['backup_id'])->toBe('backup_20260830_120000')
        ->and($progress)->not->toHaveKey('mode');
});

it('服务异常只记录分类上下文并保持服务给出的最后阶段', function (): void {
    Log::spy();
    $this->atomicRestore->failure = new RuntimeException('database secret sql should not be logged');
    $token = str_repeat('b', 32);

    (new RestoreBackupJob($token, 'backup_20260830_120000', false, 'admin:8'))->handle();

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['stage'])->toBe('import')
        ->and($progress['message'])->toContain('恢复失败')
        ->and($progress['message'])->not->toContain('database secret sql');
    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'RestoreBackupJob failed'
            && ($context['stage'] ?? null) === 'import'
            && ($context['exception'] ?? null) === RuntimeException::class
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'database secret sql');
    });
});

it('保留 freeze release 所需的 worker 重试边界且删除旧恢复实现属性', function (): void {
    $defaults = (new ReflectionClass(RestoreBackupJob::class))->getDefaultProperties();

    expect($defaults['tries'] ?? null)->toBe(5)
        ->and($defaults['maxExceptions'] ?? null)->toBe(1)
        ->and($defaults['timeout'] ?? null)->toBe(0)
        ->and($defaults)->not->toHaveKey('mode');
});

final class Task10AtomicRestoreJobFake
{
    /** @var list<RestoreRequest> */
    public array $requests = [];

    public ?Throwable $failure = null;

    public function restore(RestoreRequest $request, callable $progress): RestoreResult
    {
        $this->requests[] = $request;
        $progress([
            'stage' => 'import',
            'message' => '流式导入',
            'metrics' => ['input_bytes' => 10],
            'updated_at' => now()->toDateTimeString(),
        ]);
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $progress([
            'stage' => 'complete',
            'message' => '数据库恢复完成',
            'metrics' => ['tables' => 2],
            'updated_at' => now()->toDateTimeString(),
        ]);

        return new RestoreResult('restored', 'aabbccddeeff', [], ['tables' => 2]);
    }
}

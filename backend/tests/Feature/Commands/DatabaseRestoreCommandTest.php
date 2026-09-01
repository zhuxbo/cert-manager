<?php

declare(strict_types=1);

use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreResult;

beforeEach(function () {
    $this->restoreCommandFake = new Task10AtomicRestoreCommandFake;
    $this->app->instance(AtomicRestoreService::class, $this->restoreCommandFake);
});

it('同步 CLI 调用统一服务并输出阶段和结果', function (): void {
    $this->artisan('database:restore', [
        'backup' => 'backup_20260830_120000',
        '--allow-schema-difference' => true,
    ])->expectsOutputToContain('[preflight]')
        ->expectsOutputToContain('[complete]')
        ->expectsOutputToContain('恢复完成')
        ->assertSuccessful();

    expect($this->restoreCommandFake->request)->not->toBeNull()
        ->and($this->restoreCommandFake->request->backupId)->toBe('backup_20260830_120000')
        ->and($this->restoreCommandFake->request->allowSchemaDifference)->toBeTrue()
        ->and($this->restoreCommandFake->request->actor)->toBe('cli');
});

it('同步 CLI 原样报告 Schema 确认阻断并返回失败', function (): void {
    $this->restoreCommandFake->failure = new RuntimeException('恢复预检需要确认 Schema 差异');

    $this->artisan('database:restore', ['backup' => 'backup_20260830_120000'])
        ->expectsOutputToContain('恢复预检需要确认 Schema 差异')
        ->assertFailed();
});

final class Task10AtomicRestoreCommandFake
{
    public ?RestoreRequest $request = null;

    public ?Throwable $failure = null;

    public function restore(RestoreRequest $request, callable $progress): RestoreResult
    {
        $this->request = $request;
        $progress([
            'stage' => 'preflight',
            'message' => '检查备份',
            'metrics' => [],
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

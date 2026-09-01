<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreRequest;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

final class DatabaseRestoreCommand extends Command
{
    protected $signature = 'database:restore
        {backup : 备份 ID，例如 backup_20260830_120000}
        {--allow-schema-difference : 确认接受备份 Schema 与当前数据库存在差异}';

    protected $description = '同步执行或续接原子数据库恢复';

    public function handle(): int
    {
        $request = new RestoreRequest(
            (string) $this->argument('backup'),
            (bool) $this->option('allow-schema-difference'),
            'cli',
        );

        try {
            $result = app(AtomicRestoreService::class)->restore(
                $request,
                function (array $payload): void {
                    $stage = (string) ($payload['stage'] ?? 'unknown');
                    $message = (string) ($payload['message'] ?? '');
                    $this->line("[{$stage}] {$message}");
                },
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return CommandAlias::FAILURE;
        }

        foreach ($result->warnings as $warning) {
            $this->warn('[warning:'.$warning['code'].'] '.$warning['message']);
        }
        $this->info("恢复完成（{$result->operation}，token={$result->restoreToken}）");

        return CommandAlias::SUCCESS;
    }
}

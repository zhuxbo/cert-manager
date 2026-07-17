<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PluginRollbackUnrecordedMigrationsCommand extends Command
{
    protected $signature = 'plugin:rollback-unrecorded-migrations
        {name : 插件名称}
        {--migration=* : 需要兜底执行 down() 的迁移文件名（不含 .php）}';

    protected $description = 'Rollback plugin migrations that may have applied DDL before Laravel recorded them';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            $this->error("无效的插件名: $name");

            return self::FAILURE;
        }

        $migrationNames = array_values(array_unique(array_map(
            'strval',
            (array) $this->option('migration')
        )));

        if ($migrationNames === []) {
            $this->info('没有需要兜底回滚的插件迁移');

            return self::SUCCESS;
        }

        foreach ($migrationNames as $migrationName) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $migrationName)) {
                $this->error("无效的迁移名: $migrationName");

                return self::FAILURE;
            }
        }

        $migrationsPath = base_path("../plugins/$name/backend/migrations");
        if (! is_dir($migrationsPath)) {
            throw new RuntimeException("插件迁移目录不存在: $migrationsPath");
        }

        $files = collect(File::files($migrationsPath))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->keyBy(fn ($file) => $file->getFilenameWithoutExtension());

        foreach (array_reverse($migrationNames) as $migrationName) {
            $file = $files->get($migrationName);
            if (! $file) {
                throw new RuntimeException("插件迁移文件不存在: $migrationName");
            }

            $migration = require $file->getPathname();
            if (! is_object($migration) || ! method_exists($migration, 'down')) {
                throw new RuntimeException("插件迁移缺少 down 方法: $migrationName");
            }

            $migration->down();
            DB::table('migrations')->where('migration', $migrationName)->delete();
            $this->info("已兜底回滚插件迁移: $migrationName");
        }

        return self::SUCCESS;
    }
}

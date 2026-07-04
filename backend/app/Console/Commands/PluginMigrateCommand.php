<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PluginMigrateCommand extends Command
{
    protected $signature = 'plugin:migrate
        {name : 插件名称}
        {--marker= : 迁移执行标记文件路径}';

    protected $description = 'Run pending plugin migrations with an execution marker';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            $this->error("无效的插件名: $name");

            return self::FAILURE;
        }

        $migrationsPath = base_path("../plugins/$name/backend/migrations");
        if (! is_dir($migrationsPath)) {
            return self::SUCCESS;
        }

        $files = collect(File::files($migrationsPath))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->sortBy(fn ($file) => $file->getFilenameWithoutExtension())
            ->values();

        $migrationNames = $files->map(fn ($file) => $file->getFilenameWithoutExtension())->all();
        $recorded = DB::table('migrations')
            ->whereIn('migration', $migrationNames)
            ->pluck('migration')
            ->all();

        $pending = $files->reject(fn ($file) => in_array($file->getFilenameWithoutExtension(), $recorded, true));
        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        $attempted = [];
        $batch = ((int) DB::table('migrations')->max('batch')) + 1;

        foreach ($pending as $file) {
            $migrationName = $file->getFilenameWithoutExtension();
            $attempted[] = $migrationName;
            $this->writeMarker($attempted, $migrationName);

            $migration = require $file->getPathname();
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                throw new RuntimeException("插件迁移缺少 up 方法: $migrationName");
            }

            $migration->up();
            DB::table('migrations')->insert([
                'migration' => $migrationName,
                'batch' => $batch,
            ]);
            $this->info("已执行插件迁移: $migrationName");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $attempted
     */
    private function writeMarker(array $attempted, string $current): void
    {
        $marker = $this->markerPath();
        if ($marker === null) {
            return;
        }

        File::ensureDirectoryExists(dirname($marker));
        file_put_contents($marker, json_encode([
            'attempted' => $attempted,
            'current' => $current,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function markerPath(): ?string
    {
        $marker = $this->option('marker');
        if (! is_string($marker) || $marker === '') {
            return null;
        }

        $base = storage_path('app/plugin-migration-markers');
        if (! str_starts_with($marker, $base.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('插件迁移 marker 路径非法');
        }

        return $marker;
    }
}

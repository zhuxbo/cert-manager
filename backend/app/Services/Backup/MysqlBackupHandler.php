<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

class MysqlBackupHandler implements BackupHandlerInterface
{
    private const IDLE_TIMEOUT_SECONDS = 60;

    public function __construct(private readonly NativeProcessPipeline $pipeline) {}

    public function backup(array $config, string $outputPath, array $ignoreTables, array $toolchain): PipelineResult
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('mysql 备份缺少 database 配置');
        }

        $cnfPath = $this->writeCnfFile($config);
        try {
            $dumpCommand = $this->dumpCommand(
                $toolchain['mysqldump']['path'],
                $toolchain['mysqldump']['series'],
                $cnfPath,
                $database,
                $ignoreTables,
            );

            return $this->pipeline->dumpToGzip(
                $dumpCommand,
                [$toolchain['gzip']['path'], '-1', '-c'],
                $outputPath,
                static function (): void {},
                self::IDLE_TIMEOUT_SECONDS,
            );
        } finally {
            @unlink($cnfPath);
        }
    }

    private function writeCnfFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mysqldump_');
        if ($path === false) {
            throw new RuntimeException('无法创建临时配置文件');
        }

        $escape = fn (string $value): string => str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\r'],
            $value,
        );
        $content = "[client]\n"
            .'host='.$escape((string) ($config['host'] ?? '127.0.0.1'))."\n"
            .'port='.$escape((string) ($config['port'] ?? '3306'))."\n"
            .'user='.$escape((string) ($config['username'] ?? ''))."\n"
            .'password="'.$escape((string) ($config['password'] ?? '')).'"'."\n"
            .'default-character-set='.$escape((string) ($config['charset'] ?? 'utf8mb4'))."\n";

        if (! chmod($path, 0600) || file_put_contents($path, $content, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('无法写入临时配置文件');
        }

        return $path;
    }

    /** @return list<string> */
    private function dumpCommand(
        string $binary,
        string $series,
        string $cnfPath,
        string $database,
        array $ignoreTables,
    ): array {
        $command = [
            $binary,
            "--defaults-extra-file=$cnfPath",
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
        ];
        if (in_array($series, ['8.0', '8.4'], true)) {
            $command[] = '--column-statistics=0';
        }
        $command = array_merge($command, [
            '--default-character-set=utf8mb4',
            '--hex-blob',
            '--add-drop-table',
        ]);
        foreach ($ignoreTables as $table) {
            $command[] = "--ignore-table=$database.$table";
        }
        $command[] = $database;

        return $command;
    }
}

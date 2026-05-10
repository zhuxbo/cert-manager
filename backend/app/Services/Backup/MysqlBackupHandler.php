<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * MySQL 备份处理器：mysqldump → gzip。
 *
 * 流程（与原 BackupCommand 完全一致，仅做代码组织迁移）：
 *  1. 写临时凭据文件（避免密码出现在 ps 输出）
 *  2. mysqldump 流式输出 → gzip 文件
 *  3. 删除临时凭据
 *
 * 客户端探测复用 {@see BackupService::ensureMysqlClient}（含 open_basedir 解锁逻辑）。
 */
class MysqlBackupHandler implements BackupHandlerInterface
{
    public function __construct(private BackupService $backupService) {}

    public function ensureClient(): string
    {
        return $this->backupService->ensureMysqlClient('mysqldump');
    }

    public function backup(array $config, string $outputPath, array $ignoreTables): string
    {
        $bin = $this->ensureClient();
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('mysql 备份缺少 database 配置');
        }

        $cnfPath = $this->writeCnfFile($config);

        try {
            $this->runDumpGzip($bin, $cnfPath, $database, $ignoreTables, $outputPath);
        } finally {
            @unlink($cnfPath);
        }

        return $outputPath;
    }

    /**
     * 写临时凭据文件，避免密码出现在 ps 输出里。
     */
    private function writeCnfFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mysqldump_');
        if ($path === false) {
            throw new RuntimeException('无法创建临时配置文件');
        }

        $escape = fn (string $v) => str_replace(['\\', '"'], ['\\\\', '\\"'], $v);

        $content = "[client]\n"
            .'host='.($config['host'] ?? '127.0.0.1')."\n"
            .'port='.($config['port'] ?? '3306')."\n"
            .'user='.($config['username'] ?? '')."\n"
            .'password="'.$escape((string) ($config['password'] ?? '')).'"'."\n"
            .'default-character-set='.($config['charset'] ?? 'utf8mb4')."\n";

        file_put_contents($path, $content);
        chmod($path, 0600);

        return $path;
    }

    /**
     * mysqldump 流式写入 gzip 文件，一次完成。
     */
    private function runDumpGzip(
        string $bin,
        string $cnfPath,
        string $database,
        array $ignoreTables,
        string $outputPath
    ): void {
        $args = [
            $bin,
            "--defaults-extra-file=$cnfPath",
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--column-statistics=0',
            '--default-character-set=utf8mb4',
            '--hex-blob',
            '--add-drop-table',
        ];

        foreach ($ignoreTables as $t) {
            $args[] = "--ignore-table=$database.$t";
        }

        $args[] = $database;

        $gz = gzopen($outputPath, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("无法创建 gzip 文件: $outputPath");
        }

        $process = new Process($args);
        $process->setTimeout(3600);

        try {
            $process->run(function ($type, $buffer) use ($gz) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
        } finally {
            gzclose($gz);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump 失败: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * 数据库备份处理器接口（仅 mysql 实现）。
 *
 * 实现：
 * - {@see MysqlBackupHandler} — mysqldump + gzip
 *
 * BackupCommand 入口按 `database.connections.<default>.driver` 选择对应 Handler。
 */
interface BackupHandlerInterface
{
    /**
     * 执行备份并写入明确的 .sql.gz.part 临时文件。
     *
     * @param  array<string, mixed>  $config  database.connections.<conn> 整段
     * @param  string  $outputPath  目标 .sql.gz.part 临时文件路径
     * @param  array<int, string>  $ignoreTables  本次备份要排除的表名
     * @param  array<string, mixed>  $toolchain  由命令入口一次检查并确认 supported 的工具链报告
     */
    public function backup(array $config, string $outputPath, array $ignoreTables, array $toolchain): PipelineResult;
}

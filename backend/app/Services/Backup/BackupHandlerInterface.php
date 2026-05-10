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
     * 探测客户端二进制工具，返回真实路径；找不到时抛 RuntimeException。
     *
     * 子类实现需保证：返回的路径可以直接作为 Symfony Process 第一个参数。
     */
    public function ensureClient(): string;

    /**
     * 执行备份，写入 outputPath 并返回最终文件路径。
     *
     * @param  array<string, mixed>  $config  database.connections.<conn> 整段
     * @param  string  $outputPath  目标 .sql.gz 文件路径
     * @param  array<int, string>  $ignoreTables  本次备份要排除的表名
     */
    public function backup(array $config, string $outputPath, array $ignoreTables): string;
}

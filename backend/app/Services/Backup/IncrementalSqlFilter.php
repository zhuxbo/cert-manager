<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * 将 mysqldump 输出的 SQL 流转换为"增量恢复"语义：
 *  - 剔除 DROP TABLE、CREATE TABLE（可能跨行，直到以 `;` 结尾）、LOCK/UNLOCK TABLES
 *  - INSERT INTO → INSERT IGNORE INTO（已存在主键的行直接跳过）
 *  - 其余内容原样保留（注释、SET 语句、结构语句等）
 *
 * 输入是 gzip 压缩的 .sql.gz 文件；输出为未压缩 .sql 临时文件。
 * 设计为"逐行状态机" —— mysqldump 的 CREATE TABLE 总是以 "\n);\n" 或 "\n) ENGINE=...;\n" 结尾，单条 INSERT
 * 也是一条以 ";" 结尾的逻辑语句（可能横跨多行，因为 VALUES 内含 BLOB/大文本）。
 */
class IncrementalSqlFilter
{
    /** 非 CREATE TABLE 之外、不希望保留的行前缀 */
    private const SKIP_SINGLE_LINE_PREFIXES = [
        'DROP TABLE ',
        'DROP TABLE IF EXISTS',
        'LOCK TABLES ',
        'UNLOCK TABLES',
        '/*!40000 ALTER TABLE',
    ];

    /**
     * 将 $sourceGz 过滤到 $destSql。
     * 返回统计信息。
     *
     * @return array{skipped_create:int,skipped_drop:int,rewritten_insert:int,total_statements:int}
     */
    public function filter(string $sourceGz, string $destSql): array
    {
        $in = gzopen($sourceGz, 'rb');
        if ($in === false) {
            throw new RuntimeException("无法打开 gzip 源: $sourceGz");
        }

        $out = fopen($destSql, 'wb');
        if ($out === false) {
            gzclose($in);
            throw new RuntimeException("无法写入目标: $destSql");
        }

        $stats = [
            'skipped_create' => 0,
            'skipped_drop' => 0,
            'rewritten_insert' => 0,
            'total_statements' => 0,
        ];

        // 状态：是否正在跳过一条跨行的 CREATE TABLE 语句
        $skippingCreate = false;

        try {
            while (! gzeof($in)) {
                $line = gzgets($in);
                if ($line === false) {
                    break;
                }

                // 处于 CREATE TABLE 跨行跳过态：吞到分号结尾行为止
                if ($skippingCreate) {
                    if (rtrim($line) !== '' && str_ends_with(rtrim($line), ';')) {
                        $skippingCreate = false;
                        $stats['skipped_create']++;
                    }

                    continue;
                }

                $trim = ltrim($line);

                // CREATE TABLE — 开始跨行跳过
                if (stripos($trim, 'CREATE TABLE') === 0) {
                    // 单行 CREATE TABLE ... ; 的罕见情况：当前行自带分号
                    if (str_ends_with(rtrim($line), ';')) {
                        $stats['skipped_create']++;
                    } else {
                        $skippingCreate = true;
                    }

                    continue;
                }

                // 单行跳过前缀
                $skipped = false;
                foreach (self::SKIP_SINGLE_LINE_PREFIXES as $prefix) {
                    if (stripos($trim, $prefix) === 0) {
                        if (str_starts_with(strtoupper($prefix), 'DROP')) {
                            $stats['skipped_drop']++;
                        }
                        $skipped = true;
                        break;
                    }
                }
                if ($skipped) {
                    continue;
                }

                // INSERT INTO → INSERT IGNORE INTO
                // 注意大小写与前导空白；不能破坏已经是 INSERT IGNORE / INSERT DELAYED 的情况
                if (preg_match('/^(\s*)INSERT\s+INTO\s+/i', $line)) {
                    $line = preg_replace('/^(\s*)INSERT\s+INTO\s+/i', '$1INSERT IGNORE INTO ', $line, 1);
                    $stats['rewritten_insert']++;
                    $stats['total_statements']++;
                }

                fwrite($out, $line);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }

        return $stats;
    }
}

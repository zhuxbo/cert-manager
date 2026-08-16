<?php

namespace App\Services\UserData;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;

class UserDataImporter
{
    private OutputInterface $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * 干跑模式：检测冲突
     */
    public function dryRun(string $filePath, int $userId): bool
    {
        $this->validateFile($filePath, $userId);

        $this->output->writeln("解析文件：$filePath");
        $statements = $this->parseStatements($filePath);

        $totalRecords = 0;
        $totalConflicts = 0;
        $report = [];

        foreach ($statements as $table => $inserts) {
            $ids = $this->extractPrimaryKeys($table, $inserts);
            $rowCount = ! empty($ids) ? count($ids) : $this->countInsertRows($inserts);
            $totalRecords += $rowCount;

            if (empty($ids)) {
                $report[] = [$table, $rowCount.'条', '无法检测', '自增ID表，重复导入会产生重复数据'];

                continue;
            }

            // 检查表是否存在
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $report[] = [$table, count($ids).'条', '表不存在', '⚠'];

                continue;
            }

            // 分批检查冲突
            $conflictIds = [];
            foreach (array_chunk($ids, 1000) as $chunk) {
                $existing = DB::table($table)->whereIn('id', $chunk)->pluck('id')->all();
                $conflictIds = array_merge($conflictIds, $existing);
            }

            $conflictCount = count($conflictIds);
            $totalConflicts += $conflictCount;

            if ($conflictCount > 0) {
                $idPreview = implode(', ', array_slice($conflictIds, 0, 5));
                if ($conflictCount > 5) {
                    $idPreview .= '...';
                }
                $report[] = [$table, count($ids).'条', "$conflictCount 条冲突", "ID: $idPreview"];
            } else {
                $report[] = [$table, count($ids).'条', '0 条冲突', '✓'];
            }
        }

        // 输出报告
        $this->output->writeln("\n冲突检测报告：");
        $this->printTable(['表名', '记录数', '冲突', '详情'], $report);
        $this->output->writeln("\n总计：$totalConflicts 条冲突 / $totalRecords 条记录");

        if ($totalConflicts === 0) {
            $this->output->writeln('<info>无冲突，可以安全导入</info>');

            return true;
        }

        $this->output->writeln('<comment>存在冲突，导入时冲突记录将被跳过</comment>');

        return false;
    }

    /**
     * 实际导入
     */
    public function import(string $filePath, int $userId): void
    {
        $this->validateFile($filePath, $userId);

        $this->output->writeln("导入文件：$filePath");
        $statements = $this->parseStatements($filePath);

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $report = [];

            foreach ($statements as $table => $inserts) {
                $imported = 0;
                $skipped = 0;

                // 检测目标表列，用于剔除不存在的列
                $targetColumns = null;
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $targetColumns = collect(DB::getSchemaBuilder()->getColumns($table))
                        ->pluck('name')->flip()->all();
                }

                foreach ($inserts as $sql) {
                    $sql = $this->adaptColumns($sql, $table, $targetColumns);
                    $rowCount = preg_match_all('/\)\s*,\s*\(/', $sql) + 1;

                    try {
                        DB::unprepared($sql);
                        $imported += $rowCount;
                    } catch (\Throwable $e) {
                        $skipped += $rowCount;
                        if (str_contains($e->getMessage(), 'Duplicate entry')) {
                            // 冲突跳过
                            continue;
                        }
                        $this->output->writeln("<comment>警告 [$table]：{$e->getMessage()}</comment>");
                    }
                }

                if ($imported > 0 || $skipped > 0) {
                    $report[] = [$table, $imported, $skipped];
                    $this->output->writeln("导入 {$table}：$imported 条".($skipped > 0 ? "，跳过 $skipped 条" : ''));
                }
            }

            $this->output->writeln("\n<info>导入完成</info>");
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * 适配列差异：剔除目标表不存在的列及对应 VALUES
     */
    private function adaptColumns(string $sql, string $table, ?array $targetColumns): string
    {
        if ($targetColumns === null) {
            return $sql;
        }

        // 提取列列表
        if (! preg_match('/INSERT INTO `\w+` \(([^)]+)\) VALUES/s', $sql, $colMatch)) {
            return $sql;
        }

        $columns = array_map(fn ($c) => trim($c, " `\t\n\r"), explode(',', $colMatch[1]));

        // 找出需要移除的列索引
        $removeIndexes = [];
        foreach ($columns as $i => $col) {
            if (! isset($targetColumns[$col])) {
                $removeIndexes[] = $i;
            }
        }

        if (empty($removeIndexes)) {
            return $sql;
        }

        $removedNames = array_map(fn ($i) => $columns[$i], $removeIndexes);
        $this->output->writeln("<comment>  跳过列 [$table]：".implode(', ', $removedNames).'</comment>');

        // 构建新的列列表
        $keepColumns = [];
        foreach ($columns as $i => $col) {
            if (! in_array($i, $removeIndexes)) {
                $keepColumns[] = "`$col`";
            }
        }
        $newColumnList = implode(', ', $keepColumns);

        // 提取 VALUES 部分并逐行移除对应位置的值
        if (! preg_match('/VALUES\s*(.+);$/s', $sql, $valMatch)) {
            return $sql;
        }

        $rows = $this->splitValueRows($valMatch[1]);

        $newRows = [];
        foreach ($rows as $rowStr) {
            $values = $this->parseValueRow($rowStr);
            $kept = [];
            foreach ($values as $i => $val) {
                if (! in_array($i, $removeIndexes)) {
                    $kept[] = $val;
                }
            }
            $newRows[] = '('.implode(', ', $kept).')';
        }

        return "INSERT INTO `$table` ($newColumnList) VALUES\n".implode(",\n", $newRows).';';
    }

    /**
     * 查找用户的导出文件
     *
     * @return string[] 文件路径列表（按时间排序）
     */
    public static function findExportFiles(int $userId): array
    {
        $exportDir = storage_path('app/private/exports/users');
        $pattern = "$exportDir/{$userId}_*.sql";
        $files = glob($pattern) ?: [];
        sort($files);

        return $files;
    }

    /**
     * 验证文件
     */
    private function validateFile(string $filePath, int $userId): void
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("文件不存在：$filePath");
        }

        // 读取头部注释验证 user_id
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("无法打开导入文件：$filePath");
        }
        $foundUserId = false;

        for ($i = 0; $i < 10; $i++) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            if (preg_match('/^-- user_id:\s*(\d+)/', $line, $matches)) {
                if ((int) $matches[1] !== $userId) {
                    fclose($handle);

                    throw new \RuntimeException("文件中的 user_id ({$matches[1]}) 与指定的 user_id ($userId) 不匹配");
                }
                $foundUserId = true;
                break;
            }
        }

        fclose($handle);

        if (! $foundUserId) {
            throw new \RuntimeException('文件格式无效：未找到 user_id 标记');
        }
    }

    /**
     * 解析 SQL 文件，按表名分组 INSERT 语句
     * 使用引号感知的解析，正确处理 VALUES 中包含分号的数据
     *
     * @return array<string, string[]> 表名 => INSERT 语句列表
     */
    private function parseStatements(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $statements = [];
        $length = strlen($content);
        $search = 'INSERT INTO `';
        $pos = 0;

        while (($pos = strpos($content, $search, $pos)) !== false) {
            $tableStart = $pos + strlen($search);
            $tableEnd = strpos($content, '`', $tableStart);
            if ($tableEnd === false) {
                break;
            }
            $table = substr($content, $tableStart, $tableEnd - $tableStart);

            // 从表名之后开始，找到引号外的分号作为语句结束
            $start = $pos;
            $i = $tableEnd + 1;
            $inQuote = false;
            $quoteChar = '';
            $escaped = false;

            while ($i < $length) {
                $char = $content[$i];

                if ($escaped) {
                    $escaped = false;
                    $i++;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    $i++;

                    continue;
                }

                if (! $inQuote && ($char === '\'' || $char === '"')) {
                    $inQuote = true;
                    $quoteChar = $char;
                    $i++;

                    continue;
                }

                if ($inQuote && $char === $quoteChar) {
                    // MySQL 双写转义：'' 或 ""
                    if (isset($content[$i + 1]) && $content[$i + 1] === $quoteChar) {
                        $i += 2;

                        continue;
                    }
                    $inQuote = false;
                    $i++;

                    continue;
                }

                if (! $inQuote && $char === ';') {
                    $i++;

                    break;
                }

                $i++;
            }

            $statements[$table][] = substr($content, $start, $i - $start);
            $pos = $i;
        }

        return $statements;
    }

    /**
     * 提取导出文件中各表的雪花 ID（供 purge 清理孤立数据用）
     *
     * @return array<string, int[]> 表名 => ID 列表
     */
    public function extractTableIds(string $filePath): array
    {
        $statements = $this->parseStatements($filePath);
        $result = [];

        foreach ($statements as $table => $inserts) {
            $ids = $this->extractPrimaryKeys($table, $inserts);
            if (! empty($ids)) {
                $result[$table] = $ids;
            }
        }

        return $result;
    }

    /**
     * 统计 INSERT 语句中的实际行数
     */
    private function countInsertRows(array $inserts): int
    {
        $count = 0;
        foreach ($inserts as $sql) {
            $count += preg_match_all('/\)\s*,\s*\(/', $sql) + 1;
        }

        return $count;
    }

    /**
     * 从 INSERT 语句中提取主键 ID
     */
    private function extractPrimaryKeys(string $table, array $inserts): array
    {
        $ids = [];

        foreach ($inserts as $sql) {
            // 找到 id 列在列列表中的位置
            if (! preg_match('/INSERT INTO `\w+` \(([^)]+)\) VALUES/s', $sql, $colMatch)) {
                continue;
            }

            $columns = array_map(fn ($c) => trim($c, " `\t\n\r"), explode(',', $colMatch[1]));
            $idIndex = array_search('id', $columns);
            if ($idIndex === false) {
                continue;
            }

            // 提取 VALUES 部分并按行分割
            if (! preg_match('/VALUES\s*(.+);$/s', $sql, $valMatch)) {
                continue;
            }

            $rows = $this->splitValueRows($valMatch[1]);

            foreach ($rows as $rowStr) {
                // 解析该行的值，提取第 $idIndex 个
                $values = $this->parseValueRow($rowStr);
                if (isset($values[$idIndex])) {
                    $val = trim($values[$idIndex], " '\"");
                    if (is_numeric($val)) {
                        $ids[] = (int) $val;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * 将 VALUES 部分按行分割（引号感知，不会被字段值中的 ),( 干扰）
     *
     * @param  string  $valuesStr  VALUES 后的完整内容（含外层括号和末尾分号）
     * @return string[] 每行去掉外层括号后的内容
     */
    private function splitValueRows(string $valuesStr): array
    {
        $rows = [];
        $length = strlen($valuesStr);
        $i = 0;

        while ($i < $length) {
            // 找到行开头的 (
            $openPos = strpos($valuesStr, '(', $i);
            if ($openPos === false) {
                break;
            }

            // 从 ( 之后开始，找到引号外的 )
            $j = $openPos + 1;
            $inQuote = false;
            $quoteChar = '';
            $escaped = false;

            while ($j < $length) {
                $char = $valuesStr[$j];

                if ($escaped) {
                    $escaped = false;
                    $j++;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    $j++;

                    continue;
                }

                if (! $inQuote && ($char === '\'' || $char === '"')) {
                    $inQuote = true;
                    $quoteChar = $char;
                    $j++;

                    continue;
                }

                if ($inQuote && $char === $quoteChar) {
                    if (isset($valuesStr[$j + 1]) && $valuesStr[$j + 1] === $quoteChar) {
                        $j += 2;

                        continue;
                    }
                    $inQuote = false;
                    $j++;

                    continue;
                }

                if (! $inQuote && $char === ')') {
                    // 提取括号内的内容
                    $rows[] = substr($valuesStr, $openPos + 1, $j - $openPos - 1);
                    $i = $j + 1;

                    break;
                }

                $j++;
            }

            if ($j >= $length) {
                break;
            }
        }

        return $rows;
    }

    /**
     * 解析一行 VALUES 中的值（处理引号内逗号）
     */
    private function parseValueRow(string $row): array
    {
        $values = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $escaped = false;

        for ($i = 0; $i < strlen($row); $i++) {
            $char = $row[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escaped = true;

                continue;
            }

            if (! $inQuote && ($char === '\'' || $char === '"')) {
                $inQuote = true;
                $quoteChar = $char;
                $current .= $char;

                continue;
            }

            if ($inQuote && $char === $quoteChar) {
                // MySQL 双写转义：'' 或 ""
                if (isset($row[$i + 1]) && $row[$i + 1] === $quoteChar) {
                    $current .= $char.$row[$i + 1];
                    $i++;

                    continue;
                }
                $inQuote = false;
                $current .= $char;

                continue;
            }

            if (! $inQuote && $char === ',') {
                $values[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $values[] = trim($current);

        return $values;
    }

    /**
     * 简易表格输出
     */
    private function printTable(array $headers, array $rows): void
    {
        // 计算列宽
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $separator = '+'.implode('+', array_map(fn ($w) => str_repeat('-', $w + 2), $widths)).'+';

        $this->output->writeln($separator);
        $headerLine = '|';
        foreach ($headers as $i => $header) {
            $headerLine .= ' '.str_pad($header, $widths[$i] + mb_strlen($header) - strlen($header)).' |';
        }
        $this->output->writeln($headerLine);
        $this->output->writeln($separator);

        foreach ($rows as $row) {
            $line = '|';
            foreach ($row as $i => $cell) {
                $cell = (string) $cell;
                $line .= ' '.str_pad($cell, $widths[$i] + mb_strlen($cell) - strlen($cell)).' |';
            }
            $this->output->writeln($line);
        }

        $this->output->writeln($separator);
    }
}

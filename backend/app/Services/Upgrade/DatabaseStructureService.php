<?php

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 数据库结构校验服务
 * 用于升级后校验数据库结构是否与标准一致
 */
class DatabaseStructureService
{
    /**
     * 标准结构 JSON 文件路径
     */
    protected string $structurePath = 'database/structure.json';

    /**
     * 解析连接名：null 时回落到当前默认连接
     */
    protected function resolveConnection(?string $connection): string
    {
        return $connection ?: (string) Config::get('database.default');
    }

    /**
     * 检测数据库结构差异
     *
     * @param  string|null  $connection  数据库连接名（null 用 default 连接）
     * @return array{has_diff: bool, diff: array, summary: array}
     */
    public function check(?string $connection = null): array
    {
        $connection = $this->resolveConnection($connection);

        // 非 mysql driver 跳过（仅支持 mysql；mariadb 走 mysql 路径）
        $driver = (string) Config::get("database.connections.$connection.driver");
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            Log::info('[DatabaseStructure] 非 mysql driver，跳过结构校验', ['driver' => $driver]);

            return [
                'has_diff' => false,
                'diff' => [],
                'summary' => ['skipped' => true, 'driver' => $driver],
            ];
        }
        $standardPath = base_path($this->structurePath);

        if (! File::exists($standardPath)) {
            Log::warning('[DatabaseStructure] 标准结构文件不存在', ['path' => $standardPath]);

            return [
                'has_diff' => false,
                'diff' => [],
                'summary' => ['error' => '标准结构文件不存在'],
            ];
        }

        $standard = json_decode(File::get($standardPath), true);
        $current = $this->exportCurrentStructure($connection);
        $diff = $this->compareStructures($standard, $current);
        $summary = $this->generateSummary($diff);

        return [
            'has_diff' => $this->hasDifferences($diff),
            'diff' => $diff,
            'summary' => $summary,
        ];
    }

    /**
     * 自动修复缺失的结构（仅 ADD 操作）
     *
     * @param  string|null  $connection  数据库连接名（null 用 default 连接）
     * @return array{success: bool, executed: array, errors: array}
     */
    public function fix(?string $connection = null): array
    {
        $connection = $this->resolveConnection($connection);
        $result = $this->check($connection);

        if (! $result['has_diff']) {
            return [
                'success' => true,
                'executed' => [],
                'errors' => [],
            ];
        }

        $statements = $this->generateAddStatements($result['diff']);
        $executed = [];
        $errors = [];

        foreach ($statements as $sql) {
            try {
                DB::connection($connection)->statement($sql);
                $executed[] = $sql;
                Log::info('[DatabaseStructure] 执行 SQL 成功', ['sql' => substr($sql, 0, 200)]);
            } catch (\Exception $e) {
                $errors[] = [
                    'sql' => $sql,
                    'error' => $e->getMessage(),
                ];
                Log::error('[DatabaseStructure] 执行 SQL 失败', [
                    'sql' => $sql,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'executed' => $executed,
            'errors' => $errors,
        ];
    }

    /**
     * 导出当前数据库结构（仅 mysql）。
     */
    public function exportCurrentStructure(string $connection): array
    {
        $driver = (string) Config::get("database.connections.$connection.driver");

        return match ($driver) {
            'mysql', 'mariadb' => $this->exportMysqlStructure($connection),
            default => throw new \RuntimeException("不支持的 driver: {$driver}（仅支持 mysql）"),
        };
    }

    /**
     * MySQL 完整结构导出（保留原行为，structure.json 比对逻辑依赖此格式）。
     */
    protected function exportMysqlStructure(string $connection): array
    {
        $database = Config::get("database.connections.$connection.database");

        $structure = [
            'tables' => [],
        ];

        $tables = DB::connection($connection)
            ->select('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT, AUTO_INCREMENT
 FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = ?', [$database]);

        $excludeTables = Config::get('upgrade.exclude_tables', [
            'migrations', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens',
            'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring', 'queue_batches',
        ]);

        foreach ($tables as $table) {
            $tableName = $table->TABLE_NAME;

            // 跳过系统表
            if (in_array($tableName, $excludeTables)) {
                continue;
            }

            $structure['tables'][$tableName] = [
                'engine' => $table->ENGINE,
                'collation' => $table->TABLE_COLLATION,
                'comment' => $table->TABLE_COMMENT,
                'columns' => $this->getTableColumns($connection, $database, $tableName),
                'indexes' => $this->getTableIndexes($connection, $database, $tableName),
                'foreign_keys' => $this->getTableForeignKeys($connection, $database, $tableName),
            ];
        }

        return $structure;
    }

    /**
     * 获取表的列信息
     */
    protected function getTableColumns(string $connection, string $database, string $table): array
    {
        $columns = DB::connection($connection)
            ->select('SELECT * FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
 ORDER BY ORDINAL_POSITION', [$database, $table]);

        $result = [];
        foreach ($columns as $column) {
            $result[$column->COLUMN_NAME] = [
                'position' => $column->ORDINAL_POSITION,
                'type' => $column->COLUMN_TYPE,
                'nullable' => $column->IS_NULLABLE === 'YES',
                'default' => $column->COLUMN_DEFAULT,
                'extra' => $column->EXTRA,
                'comment' => $column->COLUMN_COMMENT,
            ];
        }

        return $result;
    }

    /**
     * 获取表的索引信息
     */
    protected function getTableIndexes(string $connection, string $database, string $table): array
    {
        $indexes = DB::connection($connection)
            ->select('SELECT * FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
 ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$database, $table]);

        $result = [];
        foreach ($indexes as $index) {
            $indexName = $index->INDEX_NAME;

            if (! isset($result[$indexName])) {
                $result[$indexName] = [
                    'unique' => ! $index->NON_UNIQUE,
                    'type' => $index->INDEX_TYPE,
                    'columns' => [],
                    'sub_parts' => [],
                ];
            }

            $result[$indexName]['columns'][] = $index->COLUMN_NAME;
            $result[$indexName]['sub_parts'][] = $index->SUB_PART;
        }

        return $result;
    }

    /**
     * 获取表的外键信息
     */
    protected function getTableForeignKeys(string $connection, string $database, string $table): array
    {
        $foreignKeys = DB::connection($connection)
            ->select('SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
 kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
 rc.UPDATE_RULE, rc.DELETE_RULE
 FROM information_schema.KEY_COLUMN_USAGE kcu
 JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
 ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
 WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
 AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
 ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION', [$database, $table]);

        $result = [];
        foreach ($foreignKeys as $fk) {
            $constraintName = $fk->CONSTRAINT_NAME;

            if (! isset($result[$constraintName])) {
                $result[$constraintName] = [
                    'columns' => [],
                    'references' => [
                        'table' => $fk->REFERENCED_TABLE_NAME,
                        'columns' => [],
                    ],
                    'on_delete' => $fk->DELETE_RULE,
                    'on_update' => $fk->UPDATE_RULE,
                ];
            }

            $result[$constraintName]['columns'][] = $fk->COLUMN_NAME;
            $result[$constraintName]['references']['columns'][] = $fk->REFERENCED_COLUMN_NAME;
        }

        return $result;
    }

    /**
     * 对比两个结构
     */
    public function compareStructures(array $standard, array $current): array
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'table_differences' => [],
        ];

        $standardTables = $standard['tables'] ?? [];
        $currentTables = $current['tables'] ?? [];

        // 找出缺失的表
        foreach ($standardTables as $tableName => $tableSchema) {
            if (! isset($currentTables[$tableName])) {
                $diff['missing_tables'][$tableName] = $tableSchema;
            } else {
                $tableDiff = $this->compareTableStructure($tableSchema, $currentTables[$tableName]);
                if (! empty($tableDiff)) {
                    $diff['table_differences'][$tableName] = $tableDiff;
                }
            }
        }

        // 找出多余的表
        foreach ($currentTables as $tableName => $tableSchema) {
            if (! isset($standardTables[$tableName])) {
                $diff['extra_tables'][$tableName] = $tableSchema;
            }
        }

        return $diff;
    }

    /**
     * 对比表结构
     */
    protected function compareTableStructure(array $standard, array $current): array
    {
        $diff = [
            'missing_columns' => [],
            'extra_columns' => [],
            'modified_columns' => [],
            'missing_indexes' => [],
            'extra_indexes' => [],
            'modified_indexes' => [],
            'missing_foreign_keys' => [],
            'extra_foreign_keys' => [],
        ];

        // 对比列
        foreach ($standard['columns'] as $columnName => $columnDef) {
            if (! isset($current['columns'][$columnName])) {
                $diff['missing_columns'][$columnName] = $columnDef;
            } elseif ($this->isColumnDifferent($columnDef, $current['columns'][$columnName])) {
                $diff['modified_columns'][$columnName] = [
                    'standard' => $columnDef,
                    'current' => $current['columns'][$columnName],
                ];
            }
        }

        foreach ($current['columns'] as $columnName => $columnDef) {
            if (! isset($standard['columns'][$columnName])) {
                $diff['extra_columns'][$columnName] = $columnDef;
            }
        }

        // 对比索引
        foreach ($standard['indexes'] as $indexName => $indexDef) {
            if (! isset($current['indexes'][$indexName])) {
                $diff['missing_indexes'][$indexName] = $indexDef;
            } elseif ($this->isIndexDifferent($indexDef, $current['indexes'][$indexName])) {
                $diff['modified_indexes'][$indexName] = [
                    'standard' => $indexDef,
                    'current' => $current['indexes'][$indexName],
                ];
            }
        }

        foreach ($current['indexes'] as $indexName => $indexDef) {
            if (! isset($standard['indexes'][$indexName])) {
                $diff['extra_indexes'][$indexName] = $indexDef;
            }
        }

        // 对比外键
        foreach ($standard['foreign_keys'] as $fkName => $fkDef) {
            if (! isset($current['foreign_keys'][$fkName])) {
                $diff['missing_foreign_keys'][$fkName] = $fkDef;
            }
        }

        foreach ($current['foreign_keys'] as $fkName => $fkDef) {
            if (! isset($standard['foreign_keys'][$fkName])) {
                $diff['extra_foreign_keys'][$fkName] = $fkDef;
            }
        }

        return array_filter($diff);
    }

    /**
     * 判断列是否不同
     */
    protected function isColumnDifferent(array $standard, array $current): bool
    {
        // MySQL 5.7 整型必须有显示宽度如 int(11)、bigint(20)，
        // MySQL 8.0 废弃了显示宽度只显示 int、bigint，
        // 标准化后忽略此差异避免跨版本检测时产生误报
        $standardType = $this->normalizeIntegerType($standard['type']);
        $currentType = $this->normalizeIntegerType($current['type']);

        if ($standardType !== $currentType ||
        $standard['nullable'] !== $current['nullable'] ||
        $standard['default'] !== $current['default'] ||
        $standard['extra'] !== $current['extra']) {
            return true;
        }

        // 注释比对可配置
        if (Config::get('upgrade.behavior.strict_comment_check', false) &&
        $standard['comment'] !== $current['comment']) {
            return true;
        }

        return false;
    }

    /**
     * 判断索引是否不同
     */
    protected function isIndexDifferent(array $standard, array $current): bool
    {
        return $standard['unique'] !== $current['unique'] ||
        $standard['type'] !== $current['type'] ||
        $standard['columns'] !== $current['columns'] ||
        $standard['sub_parts'] !== $current['sub_parts'];
    }

    /**
     * 描述列的具体差异
     */
    public function describeColumnDifferences(array $standard, array $current): string
    {
        $differences = [];

        $standardType = $this->normalizeIntegerType($standard['type']);
        $currentType = $this->normalizeIntegerType($current['type']);
        if ($standardType !== $currentType) {
            $differences[] = "类型 {$current['type']} => {$standard['type']}";
        }

        if ($standard['nullable'] !== $current['nullable']) {
            $currentNull = $current['nullable'] ? 'NULL' : 'NOT NULL';
            $standardNull = $standard['nullable'] ? 'NULL' : 'NOT NULL';
            $differences[] = "$currentNull => $standardNull";
        }

        if ($standard['default'] !== $current['default']) {
            $currentDefault = $current['default'] === null ? 'NULL' : "'{$current['default']}'";
            $standardDefault = $standard['default'] === null ? 'NULL' : "'{$standard['default']}'";
            $differences[] = "默认值 $currentDefault => $standardDefault";
        }

        if ($standard['extra'] !== $current['extra']) {
            $currentExtra = $current['extra'] ?: '(无)';
            $standardExtra = $standard['extra'] ?: '(无)';
            $differences[] = "Extra $currentExtra => $standardExtra";
        }

        if (empty($differences)) {
            return '(未知差异)';
        }

        return implode(', ', $differences);
    }

    /**
     * 描述索引的具体差异
     */
    public function describeIndexDifferences(array $standard, array $current): string
    {
        $differences = [];

        if ($standard['unique'] !== $current['unique']) {
            $currentKind = $current['unique'] ? 'UNIQUE' : 'INDEX';
            $standardKind = $standard['unique'] ? 'UNIQUE' : 'INDEX';
            $differences[] = "$currentKind => $standardKind";
        }

        if ($standard['type'] !== $current['type']) {
            $differences[] = "类型 {$current['type']} => {$standard['type']}";
        }

        if ($standard['columns'] !== $current['columns']) {
            $differences[] = '列 ('.implode(',', $current['columns']).') => ('.implode(',', $standard['columns']).')';
        }

        if ($standard['sub_parts'] !== $current['sub_parts']) {
            $differences[] = '前缀长度 '.json_encode($current['sub_parts']).' => '.json_encode($standard['sub_parts']);
        }

        if (empty($differences)) {
            return '(未知差异)';
        }

        return implode(', ', $differences);
    }

    /**
     * 标准化整型类型，去除显示宽度
     *
     * MySQL 5.7 显示 int(11)、bigint(20) 等，MySQL 8.0 去除了显示宽度。
     * 标准化后统一为无宽度格式以排除版本差异。
     */
    protected function normalizeIntegerType(string $type): string
    {
        return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i', '$1', $type);
    }

    /**
     * 转义默认值中的单引号
     */
    protected function escapeDefaultValue(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * 判断是否有差异
     */
    protected function hasDifferences(array $diff): bool
    {
        return ! empty($diff['missing_tables']) ||
        ! empty($diff['table_differences']);
    }

    /**
     * 生成差异摘要
     */
    protected function generateSummary(array $diff): array
    {
        $summary = [
            'missing_tables' => array_keys($diff['missing_tables'] ?? []),
            'extra_tables' => array_keys($diff['extra_tables'] ?? []),
            'missing_columns' => [],
            'extra_columns' => [],
            'modified_columns' => [],
            'missing_indexes' => [],
            'extra_indexes' => [],
            'modified_indexes' => [],
            'missing_foreign_keys' => [],
            'extra_foreign_keys' => [],
            'can_auto_fix' => true,
            'manual_actions' => [], // 需要手动处理的操作
        ];

        foreach ($diff['table_differences'] ?? [] as $tableName => $tableDiff) {
            foreach ($tableDiff['missing_columns'] ?? [] as $col => $def) {
                $summary['missing_columns'][] = "$tableName.$col";
            }
            foreach ($tableDiff['extra_columns'] ?? [] as $col => $def) {
                $summary['extra_columns'][] = "$tableName.$col";
            }
            foreach ($tableDiff['modified_columns'] ?? [] as $col => $def) {
                $diffDesc = $this->describeColumnDifferences($def['standard'], $def['current']);
                $summary['modified_columns'][] = "$tableName.$col: $diffDesc";
                $summary['can_auto_fix'] = false;
                $summary['manual_actions'][] = "修改列 $tableName.$col";
            }
            foreach ($tableDiff['missing_indexes'] ?? [] as $idx => $def) {
                $summary['missing_indexes'][] = "$tableName.$idx";
            }
            foreach ($tableDiff['extra_indexes'] ?? [] as $idx => $def) {
                $summary['extra_indexes'][] = "$tableName.$idx";
                $summary['can_auto_fix'] = false;
                $summary['manual_actions'][] = "删除多余索引 $tableName.$idx";
            }
            foreach ($tableDiff['modified_indexes'] ?? [] as $idx => $def) {
                $summary['modified_indexes'][] = "$tableName.$idx";
                $summary['can_auto_fix'] = false;
                $summary['manual_actions'][] = "修改索引 $tableName.$idx";
            }
            foreach ($tableDiff['missing_foreign_keys'] ?? [] as $fk => $def) {
                $summary['missing_foreign_keys'][] = "$tableName.$fk";
                // 外键添加可能有依赖问题，标记为需要手动处理
                $summary['can_auto_fix'] = false;
                $summary['manual_actions'][] = "添加外键 $tableName.$fk";
            }
            foreach ($tableDiff['extra_foreign_keys'] ?? [] as $fk => $def) {
                $summary['extra_foreign_keys'][] = "$tableName.$fk";
                $summary['can_auto_fix'] = false;
                $summary['manual_actions'][] = "删除多余外键 $tableName.$fk";
            }
        }

        // 有多余的表或列，不能自动删除
        if (! empty($summary['extra_tables'])) {
            $summary['can_auto_fix'] = false;
            foreach ($summary['extra_tables'] as $table) {
                $summary['manual_actions'][] = "删除多余表 $table";
            }
        }
        if (! empty($summary['extra_columns'])) {
            $summary['can_auto_fix'] = false;
            foreach ($summary['extra_columns'] as $col) {
                $summary['manual_actions'][] = "删除多余列 $col";
            }
        }

        return $summary;
    }

    /**
     * 生成 ADD 语句
     */
    public function generateAddStatements(array $diff, bool $skipForeignKeys = false): array
    {
        $statements = [];
        $foreignKeyStatements = [];

        // 创建缺失的表（不含外键，外键延迟添加）
        foreach ($diff['missing_tables'] ?? [] as $tableName => $tableSchema) {
            $statements[] = $this->generateCreateTableStatement($tableName, $tableSchema, false);

            if (! $skipForeignKeys) {
                foreach ($tableSchema['foreign_keys'] ?? [] as $fkName => $fkDef) {
                    $foreignKeyStatements[] = $this->generateAddForeignKeyStatement($tableName, $fkName, $fkDef);
                }
            }
        }

        // 添加缺失的列、索引、外键
        foreach ($diff['table_differences'] ?? [] as $tableName => $tableDiff) {
            foreach ($tableDiff['missing_columns'] ?? [] as $columnName => $columnDef) {
                $statements[] = $this->generateAddColumnStatement($tableName, $columnName, $columnDef);
            }

            foreach ($tableDiff['missing_indexes'] ?? [] as $indexName => $indexDef) {
                $statements[] = $this->generateAddIndexStatement($tableName, $indexName, $indexDef);
            }

            if (! $skipForeignKeys) {
                foreach ($tableDiff['missing_foreign_keys'] ?? [] as $fkName => $fkDef) {
                    $foreignKeyStatements[] = $this->generateAddForeignKeyStatement($tableName, $fkName, $fkDef);
                }
            }
        }

        // 最后添加外键（确保所有被引用的表都已创建）
        $statements = array_merge($statements, $foreignKeyStatements);

        return $statements;
    }

    /**
     * 生成 CREATE TABLE 语句
     *
     * @param  bool  $includeForeignKeys  是否包含外键约束（默认 true，但建表时建议 false 以避免依赖问题）
     */
    protected function generateCreateTableStatement(string $tableName, array $tableSchema, bool $includeForeignKeys = true): string
    {
        $lines = ["CREATE TABLE `$tableName` ("];
        $columnLines = [];

        foreach ($tableSchema['columns'] as $columnName => $columnDef) {
            $line = " `$columnName` {$columnDef['type']}";
            $line .= $columnDef['nullable'] ? ' NULL' : ' NOT NULL';

            if ($columnDef['default'] !== null) {
                if (preg_match('/^current_timestamp(\(\d+\))?$/i', $columnDef['default'])) {
                    $line .= " DEFAULT {$columnDef['default']}";
                } else {
                    $line .= " DEFAULT '".$this->escapeDefaultValue($columnDef['default'])."'";
                }
            }

            if ($columnDef['extra']) {
                $line .= " {$columnDef['extra']}";
            }

            if ($columnDef['comment']) {
                $line .= " COMMENT '".$this->escapeDefaultValue($columnDef['comment'])."'";
            }

            $columnLines[] = $line;
        }

        foreach ($tableSchema['indexes'] as $indexName => $indexDef) {
            if ($indexName === 'PRIMARY') {
                $columnParts = [];
                foreach ($indexDef['columns'] as $i => $column) {
                    $subPart = $indexDef['sub_parts'][$i] ?? null;
                    $columnParts[] = $subPart ? "`$column`($subPart)" : "`$column`";
                }
                $columns = implode(', ', $columnParts);
                $columnLines[] = " PRIMARY KEY ($columns)";
            } else {
                $indexType = '';
                if ($indexDef['type'] === 'FULLTEXT') {
                    $indexType = 'FULLTEXT ';
                } elseif ($indexDef['type'] === 'SPATIAL') {
                    $indexType = 'SPATIAL ';
                } elseif ($indexDef['unique']) {
                    $indexType = 'UNIQUE ';
                }

                $columnParts = [];
                foreach ($indexDef['columns'] as $i => $column) {
                    $subPart = $indexDef['sub_parts'][$i] ?? null;
                    $columnParts[] = $subPart ? "`$column`($subPart)" : "`$column`";
                }
                $columns = implode(', ', $columnParts);
                $columnLines[] = " {$indexType}KEY `$indexName` ($columns)";
            }
        }

        // 添加外键约束（仅当 includeForeignKeys 为 true 时）
        if ($includeForeignKeys) {
            foreach ($tableSchema['foreign_keys'] ?? [] as $fkName => $fkDef) {
                $columns = implode(', ', array_map(fn ($c) => "`$c`", $fkDef['columns']));
                $refTable = $fkDef['references']['table'];
                $refColumns = implode(', ', array_map(fn ($c) => "`$c`", $fkDef['references']['columns']));
                $onDelete = $fkDef['on_delete'] ?? 'NO ACTION';
                $onUpdate = $fkDef['on_update'] ?? 'NO ACTION';
                $columnLines[] = " CONSTRAINT `$fkName` FOREIGN KEY ($columns) REFERENCES `$refTable` ($refColumns) ON DELETE $onDelete ON UPDATE $onUpdate";
            }
        }

        $lines[] = implode(",\n", $columnLines);
        // 仅指定引擎，不指定字符集和排序规则，由目标数据库决定（不同 MySQL 版本默认值不同）
        $engine = $tableSchema['engine'] ?? 'InnoDB';
        $lines[] = ") ENGINE=$engine;";

        return implode("\n", $lines);
    }

    /**
     * 生成 ADD COLUMN 语句
     */
    protected function generateAddColumnStatement(string $tableName, string $columnName, array $columnDef): string
    {
        $sql = "ALTER TABLE `$tableName` ADD COLUMN `$columnName` {$columnDef['type']}";
        $sql .= $columnDef['nullable'] ? ' NULL' : ' NOT NULL';

        if ($columnDef['default'] !== null) {
            if (preg_match('/^current_timestamp(\(\d+\))?$/i', $columnDef['default'])) {
                $sql .= " DEFAULT {$columnDef['default']}";
            } else {
                $sql .= " DEFAULT '".$this->escapeDefaultValue($columnDef['default'])."'";
            }
        }

        if ($columnDef['extra']) {
            $sql .= " {$columnDef['extra']}";
        }

        if ($columnDef['comment']) {
            $sql .= " COMMENT '".$this->escapeDefaultValue($columnDef['comment'])."'";
        }

        return $sql.';';
    }

    /**
     * 生成 ADD INDEX 语句
     */
    protected function generateAddIndexStatement(string $tableName, string $indexName, array $indexDef): string
    {
        $indexType = '';
        if ($indexDef['type'] === 'FULLTEXT') {
            $indexType = 'FULLTEXT ';
        } elseif ($indexDef['type'] === 'SPATIAL') {
            $indexType = 'SPATIAL ';
        } elseif ($indexDef['unique']) {
            $indexType = 'UNIQUE ';
        }

        $columnParts = [];
        foreach ($indexDef['columns'] as $i => $column) {
            $subPart = $indexDef['sub_parts'][$i] ?? null;
            $columnParts[] = $subPart ? "`$column`($subPart)" : "`$column`";
        }
        $columns = implode(', ', $columnParts);

        return "ALTER TABLE `$tableName` ADD {$indexType}INDEX `$indexName` ($columns);";
    }

    /**
     * 生成 ADD FOREIGN KEY 语句
     */
    protected function generateAddForeignKeyStatement(string $tableName, string $fkName, array $fkDef): string
    {
        $columns = implode(', ', array_map(fn ($c) => "`$c`", $fkDef['columns']));
        $refTable = $fkDef['references']['table'];
        $refColumns = implode(', ', array_map(fn ($c) => "`$c`", $fkDef['references']['columns']));
        $onDelete = $fkDef['on_delete'] ?? 'NO ACTION';
        $onUpdate = $fkDef['on_update'] ?? 'NO ACTION';

        return "ALTER TABLE `$tableName` ADD CONSTRAINT `$fkName` FOREIGN KEY ($columns) REFERENCES `$refTable` ($refColumns) ON DELETE $onDelete ON UPDATE $onUpdate;";
    }
}

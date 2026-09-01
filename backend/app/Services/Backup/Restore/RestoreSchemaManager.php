<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RestoreSchemaManager
{
    private const LOCK_WAIT_SECONDS = 10;

    private const PACKET_SAFETY_MARGIN = 4096;

    public function __construct(private readonly RestoreForeignKeyPlanStore $planStore) {}

    public function prepareEmptyRuntimeShadows(RestoreContext $context): void
    {
        $connection = $this->connection();
        $statements = [];

        foreach ($context->runtimeResetTables as $table) {
            $shadow = $context->shadowTableMap[$table] ?? null;
            $definition = $context->schema['tables'][$table] ?? null;
            if (! is_string($shadow) || ! is_array($definition)) {
                throw new RuntimeException("运行时表缺少恢复 Schema 或影子映射: $table");
            }
            $this->assertIdentifier($table);
            $this->assertIdentifier($shadow);
            $statements[$shadow] = $this->createRuntimeTableSql($connection, $shadow, $definition);
        }

        if ($this->existingTables(array_keys($statements)) !== []) {
            throw new RuntimeException('运行时影子表已存在，拒绝复用');
        }

        $created = [];
        try {
            foreach ($statements as $shadow => $statement) {
                $connection->statement($statement);
                $created[] = $shadow;
            }
        } catch (Throwable $e) {
            if ($created !== []) {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                try {
                    $connection->statement('DROP TABLE '.implode(', ', array_map($this->quoteIdentifier(...), $created)));
                } finally {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                }
            }

            throw $e;
        }
    }

    public function normalizeIdentityState(RestoreContext $context, CarbonImmutable $restoredAt): void
    {
        $connection = $this->connection();
        $updates = [];
        $adminCursor = null;
        $adminShadow = null;
        foreach (['users', 'admins'] as $table) {
            if (! in_array($table, $context->swapTables(), true)) {
                continue;
            }
            $tableSchema = $context->schema['tables'][$table] ?? null;
            $shadow = $context->shadowTableMap[$table] ?? null;
            if (! is_array($tableSchema) || ! is_string($shadow)) {
                throw new RuntimeException("身份表缺少恢复 Schema 或影子映射: $table");
            }
            $columns = $tableSchema['columns'] ?? null;
            if (! is_array($columns)) {
                throw new RuntimeException("身份表缺少列定义: $table");
            }
            if (array_key_exists('token_version', $columns) && array_key_exists('logout_at', $columns)) {
                if (! array_key_exists('id', $columns) || ! is_array($columns['token_version'])) {
                    throw new RuntimeException("身份表缺少可验证的 id/token_version 定义: $table");
                }
                $this->assertIdentityCapacity(
                    $connection,
                    $table,
                    $shadow,
                    (string) ($columns['token_version']['type'] ?? ''),
                );
                $updates[] = [$table, $shadow];
            }
            if ($table === 'admins') {
                $adminCursor = $this->adminCursor($connection, $table, $shadow, $tableSchema);
                $adminShadow = $shadow;
            }
        }
        foreach ($updates as [$active, $shadow]) {
            $this->updateIdentityTable($connection, $active, $shadow, $restoredAt);
        }
        if ($adminCursor !== null) {
            $connection->statement(
                'ALTER TABLE '.$this->quoteIdentifier($adminShadow).' AUTO_INCREMENT = '.$adminCursor,
            );
        }
    }

    public function prepareCanonicalForeignKeys(RestoreContext $context): void
    {
        $expectedShadow = $this->expectedForeignKeys($context, true);
        $shadowTables = $this->mappedNames($context, $context->shadowTableMap);
        $actualShadow = $this->foreignKeysForOwners($shadowTables);
        foreach ($actualShadow as $name => $foreignKey) {
            if (! isset($expectedShadow[$name]) || $foreignKey !== $expectedShadow[$name]) {
                throw new RuntimeException('影子表包含错误或非预期外键');
            }
        }
        $activeForeignKeys = $this->foreignKeysForOwners($context->swapTables());
        if (! $this->planStore->exists($context)) {
            $this->planStore->write($context, $activeForeignKeys);
        }
        $originalForeignKeys = $this->planStore->load($context);
        $this->assertForeignKeySubset($originalForeignKeys, $activeForeignKeys, 'active');
        $missingShadow = array_diff_key($expectedShadow, $actualShadow);
        $dropped = [];
        $added = [];
        $connection = $this->connection();

        try {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($activeForeignKeys as $name => $foreignKey) {
                $this->dropForeignKey($foreignKey['table'], $name);
                $dropped[$name] = $foreignKey;
            }
            foreach ($missingShadow as $name => $foreignKey) {
                $this->addForeignKey($name, $foreignKey);
                $added[$name] = $foreignKey;
            }
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $failure) {
            $compensationFailure = null;
            try {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                foreach (array_reverse($added, true) as $name => $foreignKey) {
                    $this->dropForeignKey($foreignKey['table'], (string) $name);
                }
                foreach ($dropped as $name => $foreignKey) {
                    $this->addForeignKey((string) $name, $foreignKey);
                }
            } catch (Throwable $e) {
                $compensationFailure = $e;
            } finally {
                try {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                } catch (Throwable $e) {
                    $compensationFailure ??= $e;
                }
            }
            if ($compensationFailure !== null) {
                throw new RuntimeException('外键转换失败且无法完整补偿', 0, $failure);
            }

            throw $failure;
        }
    }

    public function cutover(RestoreContext $context): RestoreCutoverResult
    {
        $originalForeignKeys = $this->planStore->load($context);
        $swap = $context->swapTables();
        if ($swap === []) {
            throw new RuntimeException('恢复切换表集不能为空');
        }
        $this->assertTableSet($context, active: true, shadow: true, old: false);
        $this->assertExactForeignKeys([], $this->foreignKeysForOwners($swap), 'active');
        $this->assertExactForeignKeys(
            $this->expectedForeignKeys($context, true),
            $this->foreignKeysForOwners($this->mappedNames($context, $context->shadowTableMap)),
            'shadow',
        );

        $rename = $this->renameStatement($context, false);
        $this->assertPacketCapacity($rename);
        $connection = $this->connection();
        $previousLockWait = $this->sessionLockWaitTimeout();
        $renamed = false;
        $primaryFailure = null;

        try {
            $this->setSessionLockWaitTimeout(self::LOCK_WAIT_SECONDS);
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            $connection->statement($rename);
            $renamed = true;
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            $this->assertExactForeignKeys(
                $this->expectedForeignKeys($context, false),
                $this->foreignKeysForOwners($swap),
                'active',
            );

            return new RestoreCutoverResult('cutover', $swap);
        } catch (Throwable $failure) {
            $primaryFailure = $failure;
            if ($renamed) {
                try {
                    $this->performRollback($context, $originalForeignKeys);
                } catch (Throwable $compensationFailure) {
                    $primaryFailure = new RuntimeException(
                        '切换后验证失败且无法完整反向恢复: '.$compensationFailure->getMessage(),
                        0,
                        $failure,
                    );
                    throw $primaryFailure;
                }
            }

            throw $failure;
        } finally {
            $this->restoreSessionState($previousLockWait, $primaryFailure);
        }
    }

    public function rollback(RestoreContext $context): RestoreCutoverResult
    {
        $originalForeignKeys = $this->planStore->load($context);
        $swap = $context->swapTables();
        if ($swap === []) {
            throw new RuntimeException('恢复回滚表集不能为空');
        }
        $rename = $this->renameStatement($context, true);
        $this->assertPacketCapacity($rename);
        $previousLockWait = $this->sessionLockWaitTimeout();
        $primaryFailure = null;

        try {
            $this->setSessionLockWaitTimeout(self::LOCK_WAIT_SECONDS);
            $this->performRollback($context, $originalForeignKeys);

            return new RestoreCutoverResult('rollback', $swap);
        } catch (Throwable $failure) {
            $primaryFailure = $failure;
            throw $failure;
        } finally {
            $this->restoreSessionState($previousLockWait, $primaryFailure);
        }
    }

    public function restoreActiveForeignKeys(RestoreContext $context): void
    {
        $original = $this->planStore->load($context);
        $expectedShadow = $this->expectedForeignKeys($context, true);
        $active = $this->foreignKeysForOwners($context->swapTables());
        $shadow = $this->foreignKeysForOwners(
            $this->mappedNames($context, $context->shadowTableMap),
        );
        $this->assertForeignKeySubset($original, $active, 'active');
        $this->assertForeignKeySubset($expectedShadow, $shadow, 'shadow');

        $droppedShadow = [];
        $addedActive = [];
        $connection = $this->connection();
        try {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($shadow as $name => $foreignKey) {
                $this->dropForeignKey($foreignKey['table'], (string) $name);
                $droppedShadow[$name] = $foreignKey;
            }
            foreach (array_diff_key($original, $active) as $name => $foreignKey) {
                $this->addForeignKey((string) $name, $foreignKey);
                $addedActive[$name] = $foreignKey;
            }
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            $this->assertExactForeignKeys(
                $original,
                $this->foreignKeysForOwners($context->swapTables()),
                'active',
            );
            $this->assertExactForeignKeys(
                [],
                $this->foreignKeysForOwners($this->mappedNames($context, $context->shadowTableMap)),
                'shadow',
            );
        } catch (Throwable $failure) {
            $compensationFailure = null;
            try {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                foreach (array_reverse($addedActive, true) as $name => $foreignKey) {
                    $this->dropForeignKey($foreignKey['table'], (string) $name);
                }
                foreach ($droppedShadow as $name => $foreignKey) {
                    $this->addForeignKey((string) $name, $foreignKey);
                }
            } catch (Throwable $e) {
                $compensationFailure = $e;
            } finally {
                try {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                } catch (Throwable $e) {
                    $compensationFailure ??= $e;
                }
            }
            if ($compensationFailure !== null) {
                throw new RuntimeException('恢复 active 外键失败且无法完整补偿', 0, $failure);
            }

            throw $failure;
        }
    }

    public function dropStagedTables(RestoreContext $context): void
    {
        $this->dropMappedTables($context, $context->shadowTableMap);
    }

    public function dropOldTables(RestoreContext $context): void
    {
        $this->assertTableSet($context, active: true, shadow: false, old: true);
        $this->dropMappedTables($context, $context->oldTableMap);
        $this->planStore->delete($context);
    }

    /** @param array<string, mixed> $table */
    private function createRuntimeTableSql(Connection $connection, string $shadow, array $table): string
    {
        $engine = (string) ($table['engine'] ?? '');
        if (strcasecmp($engine, 'InnoDB') !== 0) {
            throw new RuntimeException('运行时恢复表使用了不支持的存储引擎');
        }
        $collation = $table['collation'] ?? null;
        if ($collation !== null && (! is_string($collation) || ! in_array($collation, [
            'utf8mb4_0900_ai_ci',
            'utf8mb4_unicode_ci',
            'utf8mb4_unicode_520_ci',
            'utf8mb4_general_ci',
            'utf8mb4_bin',
            'utf8_unicode_ci',
            'utf8_general_ci',
            'utf8_bin',
            'ascii_general_ci',
            'ascii_bin',
        ], true))) {
            throw new RuntimeException('运行时恢复表使用了不支持的排序规则');
        }
        $columns = $table['columns'] ?? null;
        if (! is_array($columns) || $columns === []) {
            throw new RuntimeException('运行时恢复表缺少列定义');
        }

        $parts = [];
        foreach ($columns as $name => $column) {
            if (! is_string($name) || ! is_array($column)) {
                throw new RuntimeException('运行时恢复表列定义无效');
            }
            $this->assertIdentifier($name);
            $parts[] = $this->runtimeColumnSql($connection, $name, $column);
        }
        $indexes = $table['indexes'] ?? [];
        if (! is_array($indexes)) {
            throw new RuntimeException('运行时恢复表索引集合无效');
        }
        foreach ($indexes as $name => $index) {
            if (! is_string($name) || ! is_array($index)) {
                throw new RuntimeException('运行时恢复表索引定义无效');
            }
            $parts[] = $this->runtimeIndexSql($name, $index, array_keys($columns));
        }

        $sql = 'CREATE TABLE '.$this->quoteIdentifier($shadow).' ('.implode(', ', $parts).') ENGINE=InnoDB';
        if (is_string($collation)) {
            $characterSet = strstr($collation, '_', true);
            $sql .= ' DEFAULT CHARACTER SET '.$characterSet.' COLLATE '.$collation;
        }
        $comment = $table['comment'] ?? '';
        if (! is_string($comment)) {
            throw new RuntimeException('运行时恢复表注释无效');
        }
        $sql .= ' COMMENT='.$this->quoteString($connection, $comment);

        $autoIncrement = $table['auto_increment'] ?? null;
        if ($autoIncrement !== null) {
            $cursor = $this->decimalString($autoIncrement, 'AUTO_INCREMENT');
            if ($cursor === '0' || $this->compareDecimal($cursor, '18446744073709551615') > 0) {
                throw new RuntimeException('运行时恢复表 AUTO_INCREMENT 无效');
            }
            $sql .= ' AUTO_INCREMENT='.$cursor;
        }

        return $sql;
    }

    /** @param array<string, mixed> $column */
    private function runtimeColumnSql(Connection $connection, string $name, array $column): string
    {
        $generation = trim((string) ($column['generation_expression'] ?? ''));
        $extra = trim((string) ($column['extra'] ?? ''));
        if ($generation !== '' || preg_match('/(?<!DEFAULT_)GENERATED/i', $extra) === 1) {
            throw new RuntimeException('运行时恢复表不支持生成列');
        }
        $type = strtolower(trim((string) ($column['type'] ?? '')));
        if (! $this->supportedRuntimeType($type)) {
            throw new RuntimeException('运行时恢复表使用了不支持的列类型');
        }
        if (! is_bool($column['nullable'] ?? null)) {
            throw new RuntimeException('运行时恢复表 nullable 定义无效');
        }

        $sql = $this->quoteIdentifier($name).' '.$type;
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
        $default = $column['default'] ?? null;
        $temporal = preg_match('/^(?:timestamp|datetime)(?:\([0-6]\))?$/D', $type) === 1;
        if ($default === null) {
            if ($column['nullable']) {
                $sql .= ' DEFAULT NULL';
            }
        } elseif ($temporal && is_string($default) && preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]\))?$/iD', $default) === 1) {
            $sql .= ' DEFAULT '.strtoupper($default);
        } elseif (preg_match('/^(?:tinyint(?:\(1\))?|smallint|mediumint|int|bigint)(?: unsigned)?$/D', $type) === 1
            && (is_int($default) || (is_string($default) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $default) === 1))) {
            $sql .= ' DEFAULT '.$default;
        } elseif (is_string($default)) {
            $sql .= ' DEFAULT '.$this->quoteString($connection, $default);
        } else {
            throw new RuntimeException('运行时恢复表 default 定义无效');
        }

        if ($extra === 'auto_increment') {
            $sql .= ' AUTO_INCREMENT';
        } elseif ($extra === '' || strcasecmp($extra, 'DEFAULT_GENERATED') === 0) {
            // DEFAULT_GENERATED 是 information_schema 标记，不是额外 DDL。
        } elseif ($temporal && preg_match('/^(?:DEFAULT_GENERATED )?on update (CURRENT_TIMESTAMP(?:\([0-6]\))?)$/iD', $extra, $matches) === 1) {
            $sql .= ' ON UPDATE '.strtoupper($matches[1]);
        } else {
            throw new RuntimeException('运行时恢复表 extra 定义无效');
        }

        $comment = $column['comment'] ?? '';
        if (! is_string($comment)) {
            throw new RuntimeException('运行时恢复表列注释无效');
        }

        return $sql.' COMMENT '.$this->quoteString($connection, $comment);
    }

    /** @param array<string, mixed> $index @param list<string> $columns */
    private function runtimeIndexSql(string $name, array $index, array $columns): string
    {
        $this->assertIdentifier($name);
        if (($index['type'] ?? null) !== 'BTREE' || ! is_bool($index['unique'] ?? null)) {
            throw new RuntimeException('运行时恢复表索引类型无效');
        }
        $indexColumns = $index['columns'] ?? null;
        $subParts = $index['sub_parts'] ?? null;
        if (! is_array($indexColumns) || ! array_is_list($indexColumns) || $indexColumns === []
            || ! is_array($subParts) || ! array_is_list($subParts) || count($subParts) !== count($indexColumns)) {
            throw new RuntimeException('运行时恢复表索引列定义无效');
        }
        $quotedColumns = [];
        foreach ($indexColumns as $offset => $column) {
            if (! is_string($column) || ! in_array($column, $columns, true)) {
                throw new RuntimeException('运行时恢复表索引引用未知列');
            }
            $quoted = $this->quoteIdentifier($column);
            $subPart = $subParts[$offset];
            if ($subPart !== null) {
                $length = $this->decimalString($subPart, '索引前缀');
                if ($length === '0' || $this->compareDecimal($length, '3072') > 0) {
                    throw new RuntimeException('运行时恢复表索引前缀无效');
                }
                $quoted .= "($length)";
            }
            $quotedColumns[] = $quoted;
        }

        $columnSql = implode(', ', $quotedColumns);
        if ($name === 'PRIMARY') {
            if ($index['unique'] !== true) {
                throw new RuntimeException('运行时恢复表主键定义无效');
            }

            return "PRIMARY KEY ($columnSql)";
        }

        return ($index['unique'] ? 'UNIQUE KEY ' : 'KEY ')
            .$this->quoteIdentifier($name)." ($columnSql)";
    }

    private function supportedRuntimeType(string $type): bool
    {
        if (preg_match('/^(?:(?:tinyint(?:\(1\))?|smallint|mediumint|int|bigint)(?: unsigned)?|(?:tinytext|text|mediumtext|longtext)|(?:timestamp|datetime)(?:\([0-6]\))?)$/D', $type) === 1) {
            return true;
        }
        if (preg_match('/^(char|varchar)\(([1-9][0-9]{0,4})\)$/D', $type, $matches) !== 1) {
            return false;
        }
        $length = (int) $matches[2];

        return $matches[1] === 'char' ? $length <= 255 : $length <= 16383;
    }

    /** @return list<string> */
    private function existingTables(array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $rows = $this->connection()->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [(string) config('database.connections.'.config('database.default').'.database')],
        );
        $existing = array_map(static fn (object $row): string => (string) $row->TABLE_NAME, $rows);

        return array_values(array_intersect($tables, $existing));
    }

    private function connection(): Connection
    {
        return DB::connection((string) config('database.default'));
    }

    private function assertIdentityCapacity(
        Connection $connection,
        string $active,
        string $shadow,
        string $tokenType,
    ): void {
        $activeSql = $this->quoteIdentifier($active);
        $shadowSql = $this->quoteIdentifier($shadow);
        $row = $connection->selectOne(
            "SELECT MAX(GREATEST(COALESCE(s.`token_version`, 0), COALESCE(a.`token_version`, 0))) AS max_token
 FROM $shadowSql s LEFT JOIN $activeSql a ON a.`id` = s.`id`",
        );
        $maximum = $this->integerTypeMaximum($tokenType);
        $current = $this->decimalString($row->max_token ?? 0, 'token_version');
        if ($this->compareDecimal($current, $maximum) >= 0) {
            throw new RuntimeException("身份表 token_version 递增将溢出: $active");
        }
    }

    private function updateIdentityTable(
        Connection $connection,
        string $active,
        string $shadow,
        CarbonImmutable $restoredAt,
    ): void {
        $activeSql = $this->quoteIdentifier($active);
        $shadowSql = $this->quoteIdentifier($shadow);
        $connection->update(
            "UPDATE $shadowSql s LEFT JOIN $activeSql a ON a.`id` = s.`id`
 SET s.`token_version` = GREATEST(COALESCE(s.`token_version`, 0), COALESCE(a.`token_version`, 0)) + 1,
 s.`logout_at` = ?",
            [$restoredAt->format('Y-m-d H:i:s')],
        );
    }

    /** @param array<string, mixed> $tableSchema */
    private function adminCursor(
        Connection $connection,
        string $active,
        string $shadow,
        array $tableSchema,
    ): string {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $activeCursor = $connection->selectOne(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $active],
        )?->AUTO_INCREMENT;
        $shadowMaximum = $connection->selectOne(
            'SELECT MAX(`id`) AS max_id FROM '.$this->quoteIdentifier($shadow),
        )?->max_id;
        $candidates = [
            $this->decimalString($tableSchema['auto_increment'] ?? 1, '备份 admins AUTO_INCREMENT'),
            $this->decimalString($activeCursor ?? 1, '当前 admins AUTO_INCREMENT'),
            $this->incrementDecimal($this->decimalString($shadowMaximum ?? 0, '恢复 admins 最大 ID')),
        ];
        $cursor = '1';
        foreach ($candidates as $candidate) {
            if ($this->compareDecimal($candidate, $cursor) > 0) {
                $cursor = $candidate;
            }
        }

        $idType = (string) ($tableSchema['columns']['id']['type'] ?? '');
        if ($this->compareDecimal($cursor, $this->integerTypeMaximum($idType)) > 0) {
            throw new RuntimeException('admins AUTO_INCREMENT 超出 ID 类型上限');
        }

        return $cursor;
    }

    private function integerTypeMaximum(string $type): string
    {
        $normalized = strtolower(trim($type));
        $unsigned = str_ends_with($normalized, ' unsigned');
        $base = preg_replace('/ unsigned$/D', '', $normalized);

        return match ($base) {
            'tinyint', 'tinyint(1)' => $unsigned ? '255' : '127',
            'smallint' => $unsigned ? '65535' : '32767',
            'mediumint' => $unsigned ? '16777215' : '8388607',
            'int' => $unsigned ? '4294967295' : '2147483647',
            'bigint' => $unsigned ? '18446744073709551615' : '9223372036854775807',
            default => throw new RuntimeException('身份版本或 ID 使用了不支持的整数类型'),
        };
    }

    private function decimalString(mixed $value, string $field): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            return $value;
        }

        throw new RuntimeException("$field 不是非负整数");
    }

    private function compareDecimal(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function incrementDecimal(string $value): string
    {
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return implode('', $digits);
            }
            $digits[$index] = '0';
        }

        return '1'.implode('', $digits);
    }

    /**
     * @param  list<string>  $owners
     * @return array<string, array<string, mixed>>
     */
    private function foreignKeysForOwners(array $owners): array
    {
        if ($owners === []) {
            return [];
        }
        foreach ($owners as $owner) {
            $this->assertIdentifier($owner);
        }
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $rows = $this->connection()->select(
            'SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME,
 kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, kcu.ORDINAL_POSITION,
 rc.UPDATE_RULE, rc.DELETE_RULE
 FROM information_schema.KEY_COLUMN_USAGE kcu
 JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
 ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
 AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
 ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [$database],
        );

        $foreignKeys = [];
        foreach ($rows as $row) {
            $table = (string) $row->TABLE_NAME;
            if (! in_array($table, $owners, true)) {
                continue;
            }
            $name = (string) $row->CONSTRAINT_NAME;
            $referencedTable = (string) $row->REFERENCED_TABLE_NAME;
            $column = (string) $row->COLUMN_NAME;
            $referencedColumn = (string) $row->REFERENCED_COLUMN_NAME;
            foreach ([$table, $name, $referencedTable, $column, $referencedColumn] as $identifier) {
                $this->assertIdentifier($identifier);
            }
            $onDelete = $this->canonicalReferentialAction((string) $row->DELETE_RULE);
            $onUpdate = $this->canonicalReferentialAction((string) $row->UPDATE_RULE);

            if (! isset($foreignKeys[$name])) {
                $foreignKeys[$name] = [
                    'table' => $table,
                    'columns' => [],
                    'references' => ['table' => $referencedTable, 'columns' => []],
                    'on_delete' => $onDelete,
                    'on_update' => $onUpdate,
                ];
            } elseif ($foreignKeys[$name]['table'] !== $table) {
                throw new RuntimeException('数据库包含重复外键名称，无法精确恢复');
            }
            $foreignKeys[$name]['columns'][] = $column;
            $foreignKeys[$name]['references']['columns'][] = $referencedColumn;
        }

        ksort($foreignKeys);

        return $foreignKeys;
    }

    /** @return array<string, array<string, mixed>> */
    private function expectedForeignKeys(RestoreContext $context, bool $shadow): array
    {
        $swap = $context->swapTables();
        $expected = [];
        foreach ($context->desiredForeignKeys as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                throw new RuntimeException('恢复上下文外键定义无效');
            }
            $this->assertIdentifier($name);
            $table = $definition['table'] ?? null;
            $columns = $definition['columns'] ?? null;
            $references = $definition['references'] ?? null;
            $referencedTable = is_array($references) ? ($references['table'] ?? null) : null;
            $referencedColumns = is_array($references) ? ($references['columns'] ?? null) : null;
            if (! is_string($table) || ! in_array($table, $swap, true)
                || ! is_string($referencedTable) || ! in_array($referencedTable, $swap, true)
                || ! is_array($columns) || ! array_is_list($columns) || $columns === []
                || ! is_array($referencedColumns) || ! array_is_list($referencedColumns)
                || count($columns) !== count($referencedColumns)) {
                throw new RuntimeException('恢复上下文外键范围或列定义无效');
            }
            foreach (array_merge($columns, $referencedColumns) as $column) {
                if (! is_string($column)) {
                    throw new RuntimeException('恢复上下文外键列定义无效');
                }
                $this->assertIdentifier($column);
            }
            $onDelete = $this->canonicalReferentialAction((string) ($definition['on_delete'] ?? ''));
            $onUpdate = $this->canonicalReferentialAction((string) ($definition['on_update'] ?? ''));

            $owner = $shadow ? ($context->shadowTableMap[$table] ?? null) : $table;
            $parent = $shadow ? ($context->shadowTableMap[$referencedTable] ?? null) : $referencedTable;
            if (! is_string($owner) || ! is_string($parent)) {
                throw new RuntimeException('恢复上下文缺少外键影子映射');
            }
            $this->assertIdentifier($owner);
            $this->assertIdentifier($parent);
            $expected[$name] = [
                'table' => $owner,
                'columns' => $columns,
                'references' => ['table' => $parent, 'columns' => $referencedColumns],
                'on_delete' => $onDelete,
                'on_update' => $onUpdate,
            ];
        }
        ksort($expected);

        return $expected;
    }

    /** @param array<string, mixed> $foreignKey */
    private function addForeignKey(string $name, array $foreignKey): void
    {
        $this->assertForeignKeyDefinition($name, $foreignKey);
        $columns = implode(', ', array_map($this->quoteIdentifier(...), $foreignKey['columns']));
        $referencedColumns = implode(
            ', ',
            array_map($this->quoteIdentifier(...), $foreignKey['references']['columns']),
        );
        $sql = 'ALTER TABLE '.$this->quoteIdentifier($foreignKey['table'])
            .' ADD CONSTRAINT '.$this->quoteIdentifier($name)
            .' FOREIGN KEY ('.$columns.') REFERENCES '
            .$this->quoteIdentifier($foreignKey['references']['table']).' ('.$referencedColumns.')'
            .' ON DELETE '.$foreignKey['on_delete'].' ON UPDATE '.$foreignKey['on_update']
            .' , ALGORITHM=INPLACE, LOCK=NONE';
        $this->connection()->statement($sql);
    }

    private function dropForeignKey(string $table, string $name): void
    {
        $this->connection()->statement(
            'ALTER TABLE '.$this->quoteIdentifier($table)
            .' DROP FOREIGN KEY '.$this->quoteIdentifier($name)
            .' , ALGORITHM=INPLACE, LOCK=NONE',
        );
    }

    private function assertReferentialAction(string $action): void
    {
        if (! in_array($action, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
            throw new RuntimeException('恢复上下文包含不支持的外键动作');
        }
    }

    private function canonicalReferentialAction(string $action): string
    {
        $action = strtoupper($action);
        $this->assertReferentialAction($action);

        return $action === 'RESTRICT' ? 'NO ACTION' : $action;
    }

    /** @param array<string, mixed> $foreignKey */
    private function assertForeignKeyDefinition(string $name, array $foreignKey): void
    {
        $table = $foreignKey['table'] ?? null;
        $columns = $foreignKey['columns'] ?? null;
        $references = $foreignKey['references'] ?? null;
        $referencedTable = is_array($references) ? ($references['table'] ?? null) : null;
        $referencedColumns = is_array($references) ? ($references['columns'] ?? null) : null;
        if (! is_string($table) || ! is_string($referencedTable)
            || ! is_array($columns) || ! array_is_list($columns) || $columns === []
            || ! is_array($referencedColumns) || ! array_is_list($referencedColumns)
            || count($columns) !== count($referencedColumns)) {
            throw new RuntimeException('恢复外键计划定义无效');
        }
        foreach ([$name, $table, $referencedTable, ...$columns, ...$referencedColumns] as $identifier) {
            if (! is_string($identifier)) {
                throw new RuntimeException('恢复外键计划定义无效');
            }
            $this->assertIdentifier($identifier);
        }
        $this->assertReferentialAction((string) ($foreignKey['on_delete'] ?? ''));
        $this->assertReferentialAction((string) ($foreignKey['on_update'] ?? ''));
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     */
    private function assertForeignKeySubset(array $expected, array $actual, string $location): void
    {
        foreach ($actual as $name => $foreignKey) {
            if (! isset($expected[$name]) || $expected[$name] !== $foreignKey) {
                throw new RuntimeException("$location 外键集合与恢复计划不一致");
            }
        }
    }

    /** @param array<string, array<string, mixed>> $originalForeignKeys */
    private function performRollback(RestoreContext $context, array $originalForeignKeys): void
    {
        $this->assertTableSet($context, active: true, shadow: false, old: true);
        $expectedActive = $this->expectedForeignKeys($context, false);
        $expectedOld = $this->foreignKeysOnOldTables($context, $originalForeignKeys);
        $activeForeignKeys = $this->foreignKeysForOwners($context->swapTables());
        $oldForeignKeys = $this->foreignKeysForOwners(
            $this->mappedNames($context, $context->oldTableMap),
        );
        $this->assertForeignKeySubset($expectedActive, $activeForeignKeys, 'active');
        $this->assertForeignKeySubset($expectedOld, $oldForeignKeys, 'old');

        $droppedActive = [];
        $addedOld = [];
        $renamed = false;
        try {
            $this->connection()->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($activeForeignKeys as $name => $foreignKey) {
                $this->dropForeignKey($foreignKey['table'], (string) $name);
                $droppedActive[$name] = $foreignKey;
            }
            foreach (array_diff_key($expectedOld, $oldForeignKeys) as $name => $foreignKey) {
                $this->addForeignKey((string) $name, $foreignKey);
                $addedOld[$name] = $foreignKey;
            }
            $this->assertExactForeignKeys([], $this->foreignKeysForOwners($context->swapTables()), 'active');
            $this->assertExactForeignKeys(
                $expectedOld,
                $this->foreignKeysForOwners($this->mappedNames($context, $context->oldTableMap)),
                'old',
            );
            $this->connection()->statement($this->renameStatement($context, true));
            $renamed = true;
            $this->connection()->statement('SET FOREIGN_KEY_CHECKS=1');
            $this->assertExactForeignKeys(
                $originalForeignKeys,
                $this->foreignKeysForOwners($context->swapTables()),
                'active',
            );
        } catch (Throwable $failure) {
            if (! $renamed) {
                try {
                    $this->connection()->statement('SET FOREIGN_KEY_CHECKS=0');
                    foreach (array_reverse($addedOld, true) as $name => $foreignKey) {
                        $this->dropForeignKey($foreignKey['table'], (string) $name);
                    }
                    foreach ($droppedActive as $name => $foreignKey) {
                        $this->addForeignKey((string) $name, $foreignKey);
                    }
                } catch (Throwable $compensationFailure) {
                    throw new RuntimeException('反向切换准备失败且无法完整补偿', 0, $failure);
                }
            }

            throw $failure;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $foreignKeys
     * @return array<string, array<string, mixed>>
     */
    private function foreignKeysOnOldTables(RestoreContext $context, array $foreignKeys): array
    {
        $old = [];
        foreach ($foreignKeys as $name => $foreignKey) {
            $this->assertForeignKeyDefinition((string) $name, $foreignKey);
            $owner = $context->oldTableMap[$foreignKey['table']] ?? null;
            $parent = $context->oldTableMap[$foreignKey['references']['table']] ?? null;
            if (! is_string($owner) || ! is_string($parent)) {
                throw new RuntimeException('原 active 外键跨越恢复表范围');
            }
            $old[$name] = $foreignKey;
            $old[$name]['table'] = $owner;
            $old[$name]['references']['table'] = $parent;
        }
        ksort($old);

        return $old;
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     */
    private function assertExactForeignKeys(array $expected, array $actual, string $location): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException("$location 外键集合与恢复计划不一致");
        }
    }

    private function assertTableSet(
        RestoreContext $context,
        bool $active,
        bool $shadow,
        bool $old,
    ): void {
        $swap = $context->swapTables();
        $sets = [
            'active' => [$active, $swap],
            'shadow' => [$shadow, $this->mappedNames($context, $context->shadowTableMap)],
            'old' => [$old, $this->mappedNames($context, $context->oldTableMap)],
        ];
        foreach ($sets as $label => [$mustExist, $tables]) {
            $existing = $this->existingTables($tables);
            if (($mustExist && count($existing) !== count($tables)) || (! $mustExist && $existing !== [])) {
                throw new RuntimeException("恢复 $label 表集合状态不完整");
            }
        }
    }

    /** @param array<string, string> $map @return list<string> */
    private function mappedNames(RestoreContext $context, array $map): array
    {
        $names = [];
        foreach ($context->swapTables() as $table) {
            $name = $map[$table] ?? null;
            if (! is_string($name)) {
                throw new RuntimeException("恢复上下文缺少表映射: $table");
            }
            $this->assertIdentifier($name);
            $names[] = $name;
        }

        return $names;
    }

    private function renameStatement(RestoreContext $context, bool $reverse): string
    {
        $pairs = [];
        foreach ($context->swapTables() as $table) {
            $shadow = $context->shadowTableMap[$table] ?? null;
            $old = $context->oldTableMap[$table] ?? null;
            if (! is_string($shadow) || ! is_string($old)) {
                throw new RuntimeException("恢复上下文缺少换表映射: $table");
            }
            if ($reverse) {
                $pairs[] = $this->quoteIdentifier($table).' TO '.$this->quoteIdentifier($shadow);
                $pairs[] = $this->quoteIdentifier($old).' TO '.$this->quoteIdentifier($table);
            } else {
                $pairs[] = $this->quoteIdentifier($table).' TO '.$this->quoteIdentifier($old);
                $pairs[] = $this->quoteIdentifier($shadow).' TO '.$this->quoteIdentifier($table);
            }
        }

        return 'RENAME TABLE '.implode(', ', $pairs);
    }

    private function assertPacketCapacity(string $sql): void
    {
        $packet = (int) ($this->connection()->selectOne(
            'SELECT @@SESSION.max_allowed_packet AS packet',
        )->packet ?? 0);
        if ($packet <= self::PACKET_SAFETY_MARGIN
            || strlen($sql) >= $packet - self::PACKET_SAFETY_MARGIN) {
            throw new RuntimeException('数据库语句超过安全 max_allowed_packet 边界');
        }
    }

    private function sessionLockWaitTimeout(): int
    {
        $value = (int) ($this->connection()->selectOne(
            'SELECT @@SESSION.lock_wait_timeout AS timeout_value',
        )->timeout_value ?? 0);
        if ($value < 1) {
            throw new RuntimeException('无法读取有效 lock_wait_timeout');
        }

        return $value;
    }

    private function setSessionLockWaitTimeout(int $seconds): void
    {
        if ($seconds < 1) {
            throw new RuntimeException('lock_wait_timeout 必须为正整数');
        }
        $this->connection()->statement("SET SESSION lock_wait_timeout = $seconds");
    }

    private function restoreSessionState(int $lockWaitTimeout, ?Throwable $primaryFailure): void
    {
        $cleanupFailure = null;
        try {
            $this->connection()->statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            $cleanupFailure = $e;
        }
        try {
            $this->setSessionLockWaitTimeout($lockWaitTimeout);
        } catch (Throwable $e) {
            $cleanupFailure ??= $e;
        }
        if ($cleanupFailure === null) {
            return;
        }
        if ($primaryFailure !== null) {
            throw new RuntimeException(
                '数据库操作失败且无法恢复会话设置: '.$cleanupFailure->getMessage(),
                0,
                $primaryFailure,
            );
        }

        throw $cleanupFailure;
    }

    /** @param array<string, string> $map */
    private function dropMappedTables(RestoreContext $context, array $map): void
    {
        $targets = $this->mappedNames($context, $map);
        if ($targets === []) {
            return;
        }
        if (array_intersect($targets, $context->retainedLogTables) !== []) {
            throw new RuntimeException('清理目标包含保留日志表');
        }
        $sql = 'DROP TABLE IF EXISTS '.implode(', ', array_map($this->quoteIdentifier(...), $targets));
        $this->assertPacketCapacity($sql);
        $connection = $this->connection();
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $connection->statement($sql);
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        $this->assertIdentifier($identifier);

        return '`'.$identifier.'`';
    }

    private function assertIdentifier(string $identifier): void
    {
        if (strlen($identifier) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException('恢复上下文包含无效标识符');
        }
    }

    private function quoteString(Connection $connection, string $value): string
    {
        $quoted = $connection->getPdo()->quote($value);
        if ($quoted === false) {
            throw new RuntimeException('无法安全引用 Schema 字符串');
        }

        return $quoted;
    }
}

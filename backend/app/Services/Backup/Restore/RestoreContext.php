<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use JsonSerializable;
use RuntimeException;

final readonly class RestoreContext implements JsonSerializable
{
    public function __construct(
        public string $restoreToken,
        public string $artifactSha256,
        public array $schema,
        public bool $schemaAuthoritative,
        public array $sourceTables,
        public array $businessTables,
        public array $runtimeResetTables,
        public array $retainedLogTables,
        public array $shadowTableMap,
        public array $oldTableMap,
        public array $columnOrder,
        public array $generatedColumns,
        public array $desiredForeignKeys,
        public array $legacyAdjunctTables,
    ) {
        if (preg_match('/^[a-f0-9]{12}$/D', $restoreToken) !== 1) {
            throw new RuntimeException('恢复 token 必须是 12 位小写十六进制字符');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactSha256) !== 1) {
            throw new RuntimeException('恢复产物 SHA-256 必须是 64 位小写十六进制字符');
        }
    }

    /** @return list<string> */
    public function swapTables(): array
    {
        return array_values(array_unique(array_merge($this->sourceTables, $this->runtimeResetTables)));
    }

    public function jsonSerialize(): array
    {
        return [
            'restore_token' => $this->restoreToken,
            'artifact_sha256' => $this->artifactSha256,
            'schema' => $this->schema,
            'schema_authoritative' => $this->schemaAuthoritative,
            'source_tables' => $this->sourceTables,
            'business_tables' => $this->businessTables,
            'runtime_reset_tables' => $this->runtimeResetTables,
            'retained_log_tables' => $this->retainedLogTables,
            'shadow_table_map' => $this->shadowTableMap,
            'old_table_map' => $this->oldTableMap,
            'column_order' => $this->columnOrder,
            'generated_columns' => $this->generatedColumns,
            'desired_foreign_keys' => $this->desiredForeignKeys,
            'legacy_adjunct_tables' => $this->legacyAdjunctTables,
        ];
    }
}

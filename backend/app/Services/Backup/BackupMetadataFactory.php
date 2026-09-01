<?php

namespace App\Services\Backup;

final class BackupMetadataFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(
        array $structure,
        array $toolchain,
        array $streamStats,
        array $includedTables,
        array $excludedTables,
    ): array {
        $capacities = [];
        foreach ($includedTables as $tableName) {
            $table = $structure['tables'][$tableName] ?? null;
            if (! is_array($table)) {
                continue;
            }
            $capacities[$tableName] = [
                'data_length' => (int) ($table['data_length'] ?? 0),
                'index_length' => (int) ($table['index_length'] ?? 0),
            ];
        }

        return [
            'backup_meta' => [
                'format_version' => 2,
                'application' => [
                    'version' => config('version.version'),
                    'channel' => config('version.channel'),
                    'build_commit' => config('version.build_commit'),
                ],
                'toolchain' => [
                    'server_version' => $toolchain['server_version'] ?? null,
                    'client_version' => $toolchain['client_version'] ?? null,
                ],
                'stream' => [
                    'compressed_bytes' => $streamStats['compressed_bytes'] ?? null,
                    'uncompressed_bytes' => $streamStats['uncompressed_bytes'] ?? null,
                    'sha256' => $streamStats['sha256'] ?? null,
                ],
                'included_tables' => array_values($includedTables),
                'excluded_tables' => array_values($excludedTables),
                'table_capacities' => $capacities,
            ],
        ];
    }
}

<?php

use App\Services\Backup\BackupArtifactInspector;
use App\Services\Backup\BackupService;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestorePreflight;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreStateInspector;
use App\Services\Binary\BinaryLocator;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->preflightDir = storage_path('framework/testing/restore-preflight');
    if (! is_dir($this->preflightDir)) {
        mkdir($this->preflightDir, 0755, true);
    }
    array_map('unlink', glob($this->preflightDir.'/*') ?: []);
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
        'database.connections.mysql.database' => 'ssl_manager_test',
        'database.connections.mysql.password' => 'top-secret-preflight-password',
        'version.version' => '3.0.0',
        'version.channel' => 'dev',
        'version.build_commit' => 'current-commit',
    ]);
});

afterEach(function () {
    array_map('unlink', glob($this->preflightDir.'/*') ?: []);
    @rmdir($this->preflightDir);
});

function taskSixColumn(string $type = 'bigint', string $extra = '', string $generation = ''): array
{
    return [
        'position' => 1,
        'type' => $type,
        'nullable' => false,
        'default' => null,
        'extra' => $extra,
        'comment' => '',
        'character_set' => null,
        'generation_expression' => $generation,
    ];
}

function taskSixTable(array $columns, array $foreignKeys = [], int $data = 0, int $indexes = 0): array
{
    return [
        'engine' => 'InnoDB',
        'collation' => 'utf8mb4_unicode_ci',
        'comment' => '',
        'auto_increment' => null,
        'data_length' => $data,
        'index_length' => $indexes,
        'columns' => $columns,
        'indexes' => [],
        'foreign_keys' => $foreignKeys,
    ];
}

function taskSixMigrationsTable(): array
{
    return taskSixTable([
        'id' => taskSixColumn('int unsigned', 'auto_increment'),
        'migration' => taskSixColumn('varchar(255)'),
        'batch' => taskSixColumn('int'),
    ]);
}

function taskSixArtifactSchema(bool $withOrders = false): array
{
    $tables = [
        'users' => taskSixTable(['id' => taskSixColumn()], data: 100, indexes: 50),
        'jobs' => taskSixTable(['id' => taskSixColumn()], data: 20, indexes: 10),
    ];
    if ($withOrders) {
        $tables['orders'] = taskSixTable([
            'id' => taskSixColumn(),
            'user_id' => taskSixColumn(),
        ], [
            'orders_user_fk' => [
                'columns' => ['user_id'],
                'references' => ['table' => 'users', 'columns' => ['id']],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
        ], data: 60, indexes: 20);
    }

    return ['tables' => $tables];
}

function taskSixCurrentSchema(bool $withOrders = false): array
{
    $schema = taskSixArtifactSchema($withOrders);
    $schema['tables']['activity_logs'] = taskSixTable(
        ['id' => taskSixColumn()],
        data: 40,
        indexes: 30,
    );

    return $schema;
}

/** @param list<string> $tables */
function taskSixDump(array $tables): string
{
    $sql = '';
    foreach ($tables as $table) {
        $columns = match ($table) {
            'orders' => "  `id` bigint NOT NULL,\n  `user_id` bigint NOT NULL",
            'migrations' => "  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  `migration` varchar(255) NOT NULL,\n  `batch` int NOT NULL",
            default => '  `id` bigint NOT NULL',
        };
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\nCREATE TABLE `{$table}` (\n{$columns}\n) ENGINE=InnoDB;\n";
    }

    return $sql;
}

function taskSixWritePreflightArtifact(
    string $directory,
    string $id,
    ?array $schema,
    string $sql,
    bool $newFormat,
    array $includedTables,
    array $metadataOverrides = [],
    bool $corruptGzip = false,
): void {
    $sqlPath = "$directory/$id.sql.gz";
    $gzip = gzopen($sqlPath, 'wb');
    gzwrite($gzip, $sql);
    gzclose($gzip);
    if ($corruptGzip) {
        file_put_contents($sqlPath, substr((string) file_get_contents($sqlPath), 0, -4));
    }

    if ($schema === null) {
        return;
    }
    if ($newFormat) {
        $capacities = [];
        foreach ($includedTables as $table) {
            $tableSchema = $schema['tables'][$table] ?? [];
            $capacities[$table] = [
                'data_length' => (int) ($tableSchema['data_length'] ?? 0),
                'index_length' => (int) ($tableSchema['index_length'] ?? 0),
            ];
        }
        $metadata = [
            'format_version' => 2,
            'application' => ['version' => '2.9.0', 'channel' => 'main', 'build_commit' => 'backup-commit'],
            'toolchain' => ['server_version' => '8.4.1', 'client_version' => '8.4.1'],
            'stream' => [
                'compressed_bytes' => filesize($sqlPath),
                'uncompressed_bytes' => strlen($sql),
                'sha256' => hash_file('sha256', $sqlPath),
            ],
            'included_tables' => $includedTables,
            'excluded_tables' => ['activity_logs', 'jobs'],
            'table_capacities' => $capacities,
        ];
        foreach ($metadataOverrides as $key => $value) {
            $metadata[$key] = $value;
        }
        $schema['backup_meta'] = $metadata;
    }
    file_put_contents("$directory/$id.schema.json", json_encode($schema, JSON_THROW_ON_ERROR));
}

function taskSixToolScript(string $directory, string $name, string $output): string
{
    $path = "$directory/$name";
    file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($output)."\n");
    chmod($path, 0700);

    return $path;
}

/** @return array{RestorePreflight, RestoreRequest} */
function taskSixPreflight(object $test, array $options = []): array
{
    $id = $options['id'] ?? 'backup_20260830_190000';
    $schema = array_key_exists('schema', $options) ? $options['schema'] : taskSixArtifactSchema();
    $current = $options['current'] ?? taskSixCurrentSchema();
    $included = $options['included'] ?? ['users'];
    $sql = $options['sql'] ?? taskSixDump($included);
    $newFormat = $options['new_format'] ?? true;
    taskSixWritePreflightArtifact(
        $test->preflightDir,
        $id,
        $schema,
        $sql,
        $newFormat,
        $included,
        $options['metadata_overrides'] ?? [],
        $options['corrupt_gzip'] ?? false,
    );

    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('mysql')->zeroOrMoreTimes()->andReturn(taskSixToolScript(
        $test->preflightDir,
        'mysql',
        $options['mysql_version'] ?? 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
    ));
    $locator->shouldReceive('gzip')->zeroOrMoreTimes()->andReturn(taskSixToolScript(
        $test->preflightDir,
        'gzip',
        'gzip 1.12',
    ));
    $checker = new MysqlToolchainChecker($locator);

    DB::shouldReceive('selectOne')->zeroOrMoreTimes()->andReturn((object) [
        'version' => $options['server_version'] ?? '8.4.3',
        'version_comment' => $options['server_comment'] ?? 'MySQL Community Server - GPL',
    ]);
    $stateTables = $options['state_tables'] ?? array_keys($current['tables'] ?? []);
    $stateForeignKeys = $options['state_foreign_keys'] ?? [];
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->zeroOrMoreTimes()->andReturnUsing(
        fn (string $query): array => str_contains($query, 'information_schema.TABLES')
            ? array_map(fn (string $table): object => (object) ['TABLE_NAME' => $table], $stateTables)
            : $stateForeignKeys,
    );
    DB::shouldReceive('connection')->zeroOrMoreTimes()->with('mysql')->andReturn($connection);

    $structures = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structures->shouldReceive('exportBackupStructure')->zeroOrMoreTimes()->with('mysql')->andReturn($current);
    $backups = Mockery::mock(BackupService::class)->makePartial();
    $backups->shouldReceive('resolveRetainedTables')->zeroOrMoreTimes()->andReturn(
        $options['retained_tables'] ?? ['activity_logs'],
    );

    return [
        new RestorePreflight(
            new BackupArtifactInspector($test->preflightDir),
            $checker,
            $structures,
            $backups,
            new RestoreStateInspector,
        ),
        new RestoreRequest($id, $options['allow_schema_difference'] ?? false, 'admin:1'),
    ];
}

function taskSixBlockerCodes(array $report): array
{
    return array_column($report['hard_blockers'] ?? [], 'code');
}

function taskSixConfirmationCodes(array $report): array
{
    return array_column($report['confirmations'] ?? [], 'code');
}

test('完整新产物返回 JSON 安全只读事实、上下文和不伪造的空间估算', function () {
    [$preflight, $request] = taskSixPreflight($this);

    $report = $preflight->inspect($request);
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['runnable'])->toBeTrue()
        ->and($report['hard_blockers'])->toBe([])
        ->and($report['confirmations'])->toBe([])
        ->and($report['artifact']['sql'])->toBe($request->backupId.'.sql.gz')
        ->and($report['artifact']['schema'])->toBe($request->backupId.'.schema.json')
        ->and($report['toolchain']['supported'])->toBeTrue()
        ->and($report['versions']['backup_application']['version'])->toBe('2.9.0')
        ->and($report['versions']['current_application']['version'])->toBe('3.0.0')
        ->and($report['context'])->toBeInstanceOf(RestoreContext::class)
        ->and($report['context']->sourceTables)->toBe(['users'])
        ->and($report['context']->runtimeResetTables)->toBe(['jobs'])
        ->and($report['context']->retainedLogTables)->toBe(['activity_logs'])
        ->and($report['space'])->toMatchArray([
            'backup_data_and_indexes_bytes' => 150,
            'current_tables_retained_bytes' => 250,
            'streaming_temp_bytes' => 0,
            'total_estimated_footprint_bytes' => 400,
            'available_bytes' => null,
            'verified' => false,
        ])
        ->and($encoded)->not->toContain($this->preflightDir)
        ->not->toContain('top-secret-preflight-password')
        ->not->toContain('recommended_application_version');

    $preflight->assertRunnable($report, $request);
});

test('产物 SHA 不匹配作为 JSON 安全硬阻断返回', function () {
    [$preflight, $request] = taskSixPreflight($this, ['metadata_overrides' => ['stream' => [
        'compressed_bytes' => 1,
        'uncompressed_bytes' => 1,
        'sha256' => str_repeat('0', 64),
    ]]]);

    $report = $preflight->inspect($request);

    expect($report['runnable'])->toBeFalse()
        ->and(taskSixBlockerCodes($report))->toContain('artifact_invalid')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain($this->preflightDir);
});

test('新版预检只校验外置 SHA 并把 gzip 解码推迟到流式导入', function () {
    [$preflight, $request] = taskSixPreflight($this, ['corrupt_gzip' => true]);

    $report = $preflight->inspect($request);

    expect($report['runnable'])->toBeTrue()
        ->and($report['hard_blockers'])->toBe([])
        ->and($report['artifact']['integrity']['verified'])->toBeTrue()
        ->and($report['artifact']['integrity'])->not->toHaveKeys(['gzip_eof', 'uncompressed_bytes']);
});

test('不受支持的当前工具链硬阻断但版本只作为事实呈现', function () {
    [$preflight, $request] = taskSixPreflight($this, [
        'server_version' => '10.11.6-MariaDB',
        'server_comment' => 'MariaDB Server',
        'mysql_version' => 'mysql  Ver 15.1 Distrib 10.11.6-MariaDB, for Linux',
    ]);

    $report = $preflight->inspect($request);

    expect(taskSixBlockerCodes($report))->toContain('toolchain_unsupported')
        ->and($report['versions']['current_server'])->toMatchArray([
            'vendor' => 'mariadb',
            'version' => '10.11.6',
        ])
        ->and($report['versions'])->not->toHaveKey('recommended_application_version');
});

test('权威 Schema 差异只是可确认项且只有 allowSchemaDifference 可绕过', function () {
    $current = taskSixCurrentSchema();
    $current['tables']['users']['columns']['id']['type'] = 'int';
    [$preflight, $request] = taskSixPreflight($this, ['current' => $current]);
    $report = $preflight->inspect($request);

    expect($report['hard_blockers'])->toBe([])
        ->and(taskSixConfirmationCodes($report))->toContain('schema_difference')
        ->and($report['schema']['authoritative'])->toBeTrue()
        ->and($report['schema']['diff']['changed_tables'])->toBe(['users'])
        ->and(fn () => $preflight->assertRunnable($report, $request))->toThrow(RuntimeException::class);

    $preflight->assertRunnable($report, new RestoreRequest($request->backupId, true, $request->actor));
});

test('无 Schema 只把 SQL 实际遇到的业务表纳入低保证恢复上下文', function () {
    $current = taskSixCurrentSchema();
    $current['tables']['extra_business'] = taskSixTable(
        ['id' => taskSixColumn(), 'parent_id' => taskSixColumn()],
        ['extra_parent_fk' => [
            'columns' => ['parent_id'],
            'references' => ['table' => 'extra_business', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ]],
        data: 70,
        indexes: 30,
    );
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => null,
        'new_format' => false,
        'current' => $current,
        'sql' => taskSixDump(['users']),
    ]);

    $report = $preflight->inspect($request);

    expect($report['schema']['authoritative'])->toBeFalse()
        ->and(taskSixConfirmationCodes($report))->toContain('schema_not_authoritative')
        ->and(array_column($report['warnings'], 'code'))->toContain('schema_low_guarantee')
        ->and($report['context']->schema['tables'])->toHaveKeys(['users', 'jobs'])
        ->not->toHaveKey('extra_business')
        ->and($report['context']->sourceTables)->toBe(['users'])
        ->and($report['context']->businessTables)->toBe(['users'])
        ->and($report['context']->runtimeResetTables)->toBe(['jobs'])
        ->and($report['context']->shadowTableMap)->toHaveKeys(['users', 'jobs'])
        ->and($report['context']->shadowTableMap)->not->toHaveKey('extra_business')
        ->and($report['context']->oldTableMap)->not->toHaveKey('extra_business')
        ->and($report['context']->desiredForeignKeys)->not->toHaveKey('extra_parent_fk');

    $preflight->assertRunnable($report, new RestoreRequest($request->backupId, true, $request->actor));
});

test('无 Schema 跨版本备份按 SQL DDL 列序校验隐式 INSERT', function () {
    $current = taskSixCurrentSchema();
    $current['tables']['users'] = taskSixTable([
        'id' => taskSixColumn(),
        'current_only' => taskSixColumn('varchar(64)'),
    ]);
    $sql = <<<'SQL'
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint NOT NULL,
  `legacy_name` varchar(64) NOT NULL
) ENGINE=InnoDB;
INSERT INTO `users` VALUES (1,'alice');
SQL;
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => null,
        'new_format' => false,
        'current' => $current,
        'sql' => $sql,
    ]);

    $report = $preflight->inspect($request);

    expect(taskSixBlockerCodes($report))->not->toContain('sql_unsafe')
        ->and($report['context']->sourceTables)->toBe(['users'])
        ->and($report['context']->columnOrder['users'])->toBe(['id', 'legacy_name'])
        ->and($report['context']->schema['tables']['users']['columns'])
        ->toHaveKeys(['id', 'legacy_name'])
        ->not->toHaveKey('current_only');
});

test('无 Schema 收敛 source 后按实际 swap 集报告跨范围 FK', function () {
    $current = taskSixCurrentSchema();
    $current['tables']['users'] = taskSixTable([
        'id' => taskSixColumn(),
        'extra_id' => taskSixColumn(),
    ], [
        'users_extra_fk' => [
            'columns' => ['extra_id'],
            'references' => ['table' => 'extra_business', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ],
    ]);
    $current['tables']['extra_business'] = taskSixTable(['id' => taskSixColumn()]);
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => null,
        'new_format' => false,
        'current' => $current,
        'sql' => taskSixDump(['users']),
    ]);

    $report = $preflight->inspect($request);

    expect($report['context']->sourceTables)->toBe(['users'])
        ->and($report['context']->shadowTableMap)->not->toHaveKey('extra_business')
        ->and(taskSixBlockerCodes($report))->toContain('cross_range_foreign_key')
        ->not->toContain('sql_unsafe');
});

test('非法 UTF-8 或路径型备份 ID 的失败报告保持 JSON 安全且不回显 ID', function (string $backupId) {
    $preflight = new RestorePreflight(
        new BackupArtifactInspector($this->preflightDir),
        new MysqlToolchainChecker(Mockery::mock(BinaryLocator::class)),
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(BackupService::class),
        new RestoreStateInspector,
    );

    $report = $preflight->inspect(new RestoreRequest($backupId, false, 'admin:1'));
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);

    expect(taskSixBlockerCodes($report))->toContain('artifact_invalid')
        ->and($report['artifact']['id'])->toBeNull()
        ->and($report['artifact']['sql'])->toBeNull()
        ->and($report['artifact']['schema'])->toBeNull()
        ->and(mb_check_encoding($encoded, 'UTF-8'))->toBeTrue()
        ->and($encoded)->not->toContain($backupId);
})->with([
    'invalid UTF-8' => "backup_\xFF_20260830_190000",
    'path-like' => '../private/backup_20260830_190000',
]);

test('遗留 Schema 只允许补入实际遇到的固定 migrations adjunct', function () {
    $current = taskSixCurrentSchema();
    $current['tables']['migrations'] = taskSixMigrationsTable();
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => taskSixArtifactSchema(),
        'new_format' => false,
        'current' => $current,
        'sql' => taskSixDump(['users', 'migrations']),
    ]);

    $report = $preflight->inspect($request);

    expect($report['hard_blockers'])->toBe([])
        ->and($report['context']->sourceTables)->toBe(['users', 'migrations'])
        ->and($report['context']->legacyAdjunctTables)->toBe(['migrations'])
        ->and(array_column($report['warnings'], 'code'))->toContain('legacy_migrations_adjunct');
});

test('遗留 Schema 缺少运行时表时沿用当前结构并作为空表参与切换', function () {
    $artifact = taskSixArtifactSchema();
    $artifact['tables']['admins'] = taskSixTable(['id' => taskSixColumn()]);
    $current = $artifact;
    $current['tables']['activity_logs'] = taskSixTable(['id' => taskSixColumn()]);
    $current['tables']['admin_refresh_tokens'] = taskSixTable([
        'id' => taskSixColumn(),
        'admin_id' => taskSixColumn(),
    ], [
        'admin_refresh_tokens_admin_id_foreign' => [
            'columns' => ['admin_id'],
            'references' => ['table' => 'admins', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ],
    ]);
    $current['tables']['user_refresh_tokens'] = taskSixTable([
        'id' => taskSixColumn(),
        'user_id' => taskSixColumn(),
    ], [
        'user_refresh_tokens_user_id_foreign' => [
            'columns' => ['user_id'],
            'references' => ['table' => 'users', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ],
    ]);
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => $artifact,
        'new_format' => false,
        'current' => $current,
        'sql' => taskSixDump(['users', 'admins']),
    ]);

    $report = $preflight->inspect($request);

    expect($report['hard_blockers'])->toBe([])
        ->and($report['context']->sourceTables)->toBe(['users', 'admins'])
        ->and($report['context']->runtimeResetTables)->toBe([
            'jobs',
            'admin_refresh_tokens',
            'user_refresh_tokens',
        ])
        ->and($report['context']->shadowTableMap)->toHaveKeys([
            'admin_refresh_tokens',
            'user_refresh_tokens',
        ])
        ->and($report['context']->desiredForeignKeys)->toHaveKeys([
            'admin_refresh_tokens_admin_id_foreign',
            'user_refresh_tokens_user_id_foreign',
        ])
        ->and($report['schema']['diff']['extra_tables'])->toBe([])
        ->and(taskSixBlockerCodes($report))->not->toContain('cross_range_foreign_key');
});

test('遗留 Schema 的 SQL 未遇到 migrations 时不把候选 adjunct 固化到上下文', function () {
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => taskSixArtifactSchema(),
        'new_format' => false,
        'sql' => taskSixDump(['users']),
    ]);

    $report = $preflight->inspect($request);

    expect($report['hard_blockers'])->toBe([])
        ->and($report['context']->sourceTables)->toBe(['users'])
        ->and($report['context']->legacyAdjunctTables)->toBe([])
        ->and(array_column($report['warnings'], 'code'))->not->toContain('legacy_migrations_adjunct');
});

test('新 metadata 幽灵表和过长影子标识符均硬阻断', function (array $options, string $code) {
    [$preflight, $request] = taskSixPreflight($this, $options);
    $report = $preflight->inspect($request);

    expect(taskSixBlockerCodes($report))->toContain($code)
        ->and(fn () => $preflight->assertRunnable(
            $report,
            new RestoreRequest($request->backupId, true, $request->actor),
        ))->toThrow(RuntimeException::class);
})->with(function (): array {
    $long = str_repeat('a', 46);

    return [
        'metadata table absent from schema' => [[
            'included' => ['ghost'],
            'sql' => taskSixDump(['ghost']),
        ], 'schema_invalid'],
        'schema contains unexplained structure-only table' => [[
            'schema' => ['tables' => [
                'users' => taskSixTable(['id' => taskSixColumn()]),
                'jobs' => taskSixTable(['id' => taskSixColumn()]),
                'orphan_structure' => taskSixTable(['id' => taskSixColumn()]),
            ]],
            'included' => ['users'],
            'sql' => taskSixDump(['users']),
        ], 'schema_invalid'],
        'malformed table structure is reported instead of thrown' => [[
            'schema' => ['tables' => [
                'users' => [
                    'columns' => ['id' => taskSixColumn()],
                    'foreign_keys' => [],
                ],
                'jobs' => taskSixTable(['id' => taskSixColumn()]),
            ]],
            'included' => ['users'],
            'sql' => taskSixDump(['users']),
        ], 'schema_invalid'],
        'prefixed identifier exceeds 64 bytes' => [[
            'schema' => ['tables' => [
                $long => taskSixTable(['id' => taskSixColumn()]),
                'jobs' => taskSixTable(['id' => taskSixColumn()]),
            ]],
            'current' => ['tables' => [
                $long => taskSixTable(['id' => taskSixColumn()]),
                'jobs' => taskSixTable(['id' => taskSixColumn()]),
            ]],
            'included' => [$long],
            'retained_tables' => [],
            'sql' => taskSixDump([$long]),
        ], 'invalid_identifier'],
    ];
});

test('当前跨 swap 范围 FK 与外部表占用目标 FK 名均不可确认绕过', function (array $currentForeignKey, string $code) {
    $artifact = taskSixArtifactSchema(withOrders: true);
    $current = taskSixCurrentSchema(withOrders: true);
    $current['tables']['external'] = taskSixTable(['id' => taskSixColumn(), 'user_id' => taskSixColumn()]);
    $current['tables']['external_parent'] = taskSixTable(['id' => taskSixColumn()]);
    $current['tables']['orders']['foreign_keys'] = [];
    $current['tables']['external']['foreign_keys'] = [$currentForeignKey['name'] => $currentForeignKey['definition']];
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => $artifact,
        'current' => $current,
        'included' => ['users', 'orders'],
        'sql' => taskSixDump(['users', 'orders']),
    ]);

    $report = $preflight->inspect($request);

    expect(taskSixBlockerCodes($report))->toContain($code)
        ->and(fn () => $preflight->assertRunnable(
            $report,
            new RestoreRequest($request->backupId, true, $request->actor),
        ))->toThrow(RuntimeException::class);
})->with([
    'cross range endpoint' => [[
        'name' => 'external_users_fk',
        'definition' => [
            'columns' => ['user_id'],
            'references' => ['table' => 'users', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ],
    ], 'cross_range_foreign_key'],
    'external owner uses desired name' => [[
        'name' => 'orders_user_fk',
        'definition' => [
            'columns' => ['user_id'],
            'references' => ['table' => 'external_parent', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'RESTRICT',
        ],
    ], 'foreign_key_name_conflict'],
]);

test('任何识别到的中断命名空间都使新恢复不可运行', function () {
    [$preflight, $request] = taskSixPreflight($this, [
        'state_tables' => [
            'users',
            'jobs',
            '__rst_abcdef123456_users',
        ],
    ]);

    $report = $preflight->inspect($request);

    expect($report['state']['state'])->toBe('broken_old_set')
        ->and(taskSixBlockerCodes($report))->toContain('restore_state_not_clean');
});

test('assertRunnable 对未知确认项即使 allowSchemaDifference 也 fail closed', function () {
    [$preflight, $request] = taskSixPreflight($this);
    $report = $preflight->inspect($request);
    $report['confirmations'][] = ['code' => 'unknown_confirmation', 'message' => 'unknown'];

    expect(fn () => $preflight->assertRunnable(
        $report,
        new RestoreRequest($request->backupId, true, $request->actor),
    ))->toThrow(RuntimeException::class, '不可绕过');
});

test('中断现场仅放行已识别的合法影子外键', function (string $scenario, bool $accepted) {
    $token = 'abcdef123456';
    $artifact = taskSixArtifactSchema(withOrders: true);
    if ($scenario === 'partial') {
        $artifact['tables']['orders']['foreign_keys']['orders_user_second_fk'] = $artifact['tables']['orders']['foreign_keys']['orders_user_fk'];
    }
    $current = taskSixCurrentSchema(withOrders: true);
    $current['tables']['orders']['foreign_keys'] = [];
    foreach (['users', 'orders', 'jobs'] as $table) {
        $current['tables']["__rst_{$token}_{$table}"] = $artifact['tables'][$table];
        $current['tables']["__rst_{$token}_{$table}"]['foreign_keys'] = [];
    }
    $foreignKey = $artifact['tables']['orders']['foreign_keys']['orders_user_fk'];
    $foreignKey['references']['table'] = "__rst_{$token}_users";
    if ($scenario === 'wrong_definition') {
        $foreignKey['on_delete'] = 'RESTRICT';
    }
    $current['tables']["__rst_{$token}_orders"]['foreign_keys']['orders_user_fk'] = $foreignKey;
    if ($scenario === 'broken_namespace') {
        $current['tables']['__rst_invalid_users'] = taskSixTable(['id' => taskSixColumn()]);
    }
    [$preflight, $request] = taskSixPreflight($this, [
        'schema' => $artifact, 'current' => $current, 'included' => ['users', 'orders'],
        'sql' => taskSixDump(['users', 'orders']),
        'allow_schema_difference' => true,
        'state_foreign_keys' => [(object) [
            'CONSTRAINT_NAME' => 'orders_user_fk', 'TABLE_NAME' => "__rst_{$token}_orders",
            'COLUMN_NAME' => 'user_id', 'REFERENCED_TABLE_NAME' => "__rst_{$token}_users",
            'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT',
            'DELETE_RULE' => $foreignKey['on_delete'],
        ]],
    ]);
    $report = $preflight->inspect($request);
    expect(in_array('foreign_key_name_conflict', taskSixBlockerCodes($report), true))->toBe(! $accepted);
    if ($accepted) {
        expect($report['state']['state'])->toBe($scenario === 'partial' ? 'active_foreign_keys_removed' : 'shadow_foreign_keys_ready');
        $method = new ReflectionMethod(AtomicRestoreService::class, 'continuationReport');
        $continued = $method->invoke(new AtomicRestoreService, $report);
        $preflight->assertRunnable($continued, $request);
        expect($continued['hard_blockers'])->toBe([]);
    }
})->with([
    'ready' => ['ready', true],
    'partial' => ['partial', true],
    'wrong definition' => ['wrong_definition', false],
    'broken namespace' => ['broken_namespace', false],
]);

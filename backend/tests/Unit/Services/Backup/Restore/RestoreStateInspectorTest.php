<?php

use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestoreState;
use App\Services\Backup\Restore\RestoreStateInspector;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

function taskSixRestoreContext(string $token = 'abcdef123456'): RestoreContext
{
    $schema = ['tables' => [
        'users' => ['columns' => ['id' => []], 'foreign_keys' => []],
        'orders' => ['columns' => ['id' => [], 'user_id' => []], 'foreign_keys' => [
            'orders_user_fk' => [
                'columns' => ['user_id'],
                'references' => ['table' => 'users', 'columns' => ['id']],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
        ]],
        'jobs' => ['columns' => ['id' => []], 'foreign_keys' => []],
    ]];

    return new RestoreContext(
        restoreToken: $token,
        artifactSha256: str_repeat('c', 64),
        schema: $schema,
        schemaAuthoritative: true,
        sourceTables: ['users', 'orders'],
        businessTables: ['users', 'orders'],
        runtimeResetTables: ['jobs'],
        retainedLogTables: ['activity_logs'],
        shadowTableMap: [
            'users' => "__rst_{$token}_users",
            'orders' => "__rst_{$token}_orders",
            'jobs' => "__rst_{$token}_jobs",
        ],
        oldTableMap: [
            'users' => "__old_{$token}_users",
            'orders' => "__old_{$token}_orders",
            'jobs' => "__old_{$token}_jobs",
        ],
        columnOrder: ['users' => ['id'], 'orders' => ['id', 'user_id'], 'jobs' => ['id']],
        generatedColumns: ['users' => [], 'orders' => [], 'jobs' => []],
        desiredForeignKeys: [
            'orders_user_fk' => [
                'table' => 'orders',
                'columns' => ['user_id'],
                'references' => ['table' => 'users', 'columns' => ['id']],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
        ],
        legacyAdjunctTables: [],
    );
}

function taskSixStateTables(string $token, string $prefix): array
{
    return array_map(
        fn (string $table): string => "__{$prefix}_{$token}_{$table}",
        ['users', 'orders', 'jobs'],
    );
}

function taskSixFkRow(string $name, string $table, string $referencedTable): object
{
    return (object) [
        'CONSTRAINT_NAME' => $name,
        'TABLE_NAME' => $table,
        'COLUMN_NAME' => 'user_id',
        'REFERENCED_TABLE_NAME' => $referencedTable,
        'REFERENCED_COLUMN_NAME' => 'id',
        'UPDATE_RULE' => 'RESTRICT',
        'DELETE_RULE' => 'CASCADE',
        'ORDINAL_POSITION' => 1,
    ];
}

test('将 MySQL 5.7 的 NO ACTION 视为与备份 RESTRICT 语义一致', function (): void {
    $token = 'abcdef123456';
    $foreignKey = taskSixFkRow('orders_user_fk', "__rst_{$token}_orders", "__rst_{$token}_users");
    $foreignKey->UPDATE_RULE = 'NO ACTION';
    taskSixBindStateFacts(
        array_merge(['users', 'orders', 'jobs', 'activity_logs'], taskSixStateTables($token, 'rst')),
        [$foreignKey],
    );

    $report = (new RestoreStateInspector)->inspect(taskSixRestoreContext($token));

    expect($report['state'])->toBe(RestoreState::ShadowForeignKeysReady->value)
        ->and($report['missing_shadow_foreign_keys'])->toBe([])
        ->and($report['unexpected_shadow_foreign_keys'])->toBe([]);
});

function taskSixBindStateFacts(array $tables, array $foreignKeys): void
{
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'ssl_manager_test',
    ]);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->twice()->andReturn(
        array_map(fn (string $table): object => (object) ['TABLE_NAME' => $table], $tables),
        $foreignKeys,
    );
    DB::shouldReceive('connection')->once()->with('mysql')->andReturn($connection);
}

test('严格识别六种可判定恢复状态', function (string $expected, array $namespaceTables, array $foreignKeys) {
    $token = 'abcdef123456';
    $active = ['users', 'orders', 'jobs', 'activity_logs'];
    taskSixBindStateFacts(array_merge($active, $namespaceTables), $foreignKeys);

    $report = (new RestoreStateInspector)->inspect(taskSixRestoreContext($token));

    expect($report['state'])->toBe($expected)
        ->and($report['missing_active_suffixes'])->toBe([])
        ->and($report['malformed_namespace_tables'])->toBe([]);
})->with(function (): array {
    $token = 'abcdef123456';
    $shadow = taskSixStateTables($token, 'rst');
    $old = taskSixStateTables($token, 'old');

    return [
        'clean' => [RestoreState::Clean->value, [], []],
        'staged' => [RestoreState::Staged->value, $shadow, [taskSixFkRow('orders_user_fk', 'orders', 'users')]],
        'active foreign keys removed' => [RestoreState::ActiveForeignKeysRemoved->value, $shadow, []],
        'shadow foreign keys ready' => [RestoreState::ShadowForeignKeysReady->value, $shadow, [
            taskSixFkRow('orders_user_fk', "__rst_{$token}_orders", "__rst_{$token}_users"),
        ]],
        'active with old' => [RestoreState::ActiveWithOld->value, $old, []],
        'broken old set' => [RestoreState::BrokenOldSet->value, array_slice($old, 0, 2), []],
    ];
});

test('不完整 shadow、多 token、未知后缀和畸形命名空间都确定性阻断', function (array $namespaceTables, string $factKey) {
    $active = ['users', 'orders', 'jobs'];
    taskSixBindStateFacts(array_merge($active, $namespaceTables), []);

    $report = (new RestoreStateInspector)->inspect(taskSixRestoreContext());

    expect($report['state'])->toBe(RestoreState::BrokenOldSet->value)
        ->and($report[$factKey])->not->toBeEmpty();
})->with([
    'incomplete shadow' => [[
        '__rst_abcdef123456_users',
        '__rst_abcdef123456_orders',
    ], 'missing_shadow_suffixes'],
    'multiple tokens' => [[
        '__rst_abcdef123456_users',
        '__rst_123456abcdef_orders',
    ], 'tokens'],
    'unexpected suffix' => [[
        '__rst_abcdef123456_users',
        '__rst_abcdef123456_orders',
        '__rst_abcdef123456_jobs',
        '__rst_abcdef123456_intruder',
    ], 'unexpected_shadow_suffixes'],
    'malformed namespace' => [[
        '__rst_ABCDEF123456_users',
    ], 'malformed_namespace_tables'],
]);

test('FK readiness 使用库中实际唯一 token 而非本次请求 token 并保留 FK 事实', function () {
    $actualToken = '123456abcdef';
    taskSixBindStateFacts(
        array_merge(['users', 'orders', 'jobs'], taskSixStateTables($actualToken, 'rst')),
        [taskSixFkRow(
            'orders_user_fk',
            "__rst_{$actualToken}_orders",
            "__rst_{$actualToken}_users",
        )],
    );

    $report = (new RestoreStateInspector)->inspect(taskSixRestoreContext('abcdef123456'));

    expect($report['state'])->toBe(RestoreState::ShadowForeignKeysReady->value)
        ->and($report['tokens'])->toBe([$actualToken])
        ->and($report['shadow_foreign_keys']['orders_user_fk']['table'])
        ->toBe("__rst_{$actualToken}_orders");
});

test('shadow 外键集合必须与 desired canonical 集合精确匹配', function (array $foreignKeys, array $unexpected) {
    $token = 'abcdef123456';
    taskSixBindStateFacts(
        array_merge(['users', 'orders', 'jobs'], taskSixStateTables($token, 'rst')),
        $foreignKeys,
    );

    $report = (new RestoreStateInspector)->inspect(taskSixRestoreContext($token));

    expect($report['state'])->toBe(RestoreState::ActiveForeignKeysRemoved->value)
        ->and($report['desired_shadow_foreign_keys_ready'])->toBeFalse()
        ->and($report['unexpected_shadow_foreign_keys'])->toBe($unexpected);
})->with(function (): array {
    $token = 'abcdef123456';
    $correct = taskSixFkRow(
        'orders_user_fk',
        "__rst_{$token}_orders",
        "__rst_{$token}_users",
    );

    return [
        'unknown extra name' => [[
            $correct,
            taskSixFkRow('unexpected_fk', "__rst_{$token}_orders", 'users'),
        ], ['unexpected_fk']],
        'desired name on wrong shadow endpoints' => [[
            taskSixFkRow('orders_user_fk', "__rst_{$token}_users", "__rst_{$token}_orders"),
        ], ['orders_user_fk']],
    ];
});

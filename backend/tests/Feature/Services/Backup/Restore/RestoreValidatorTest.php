<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Services\Backup\BackupService;
use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestoreValidator;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Support\Facades\DB;

const TASK8_TOKEN = 'c0ffee123456';

afterEach(function (): void {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::statement('DROP TABLE IF EXISTS '.implode(', ', array_map(
        fn (string $table): string => '`'.$table.'`',
        [
            '__rst_c0ffee123456_admins',
            '__rst_c0ffee123456_users',
            '__rst_c0ffee123456_orders',
            '__rst_c0ffee123456_funds',
            '__rst_c0ffee123456_transactions',
            '__rst_c0ffee123456_task8_parents',
            '__rst_c0ffee123456_task8_children',
            'task8_legacy_records',
        ],
    )));
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

it('校验全部影子表结构 CHECK TABLE 外键和业务不变量', function (): void {
    $context = task8CreateValidShadowContext();
    $checks = [];
    DB::listen(function ($query) use (&$checks): void {
        if (str_starts_with(strtoupper(trim($query->sql)), 'CHECK TABLE')) {
            $checks[] = $query->sql;
        }
    });

    $report = app(RestoreValidator::class)->validateShadow($context);
    expect($report->passed)->toBeTrue()
        ->and($report->errors)->toBe([])
        ->and($report->metrics['checked_tables'])->toBe(7)
        ->and($report->metrics['foreign_keys_checked'])->toBe(1)
        ->and($checks)->toHaveCount(7);
});

it('订单过户后保留原用户交易仍可通过恢复校验', function (): void {
    $context = task8CreateValidShadowContext();
    DB::table('__rst_c0ffee123456_users')->where('id', 1)->update(['balance' => 40]);
    DB::table('__rst_c0ffee123456_users')->insert([
        'id' => 2,
        'username' => 'transferee',
        'email' => 'transferee@example.test',
        'mobile' => '13900000000',
        'balance' => 0,
    ]);
    DB::table('__rst_c0ffee123456_orders')->where('id', 30)->update(['user_id' => 2]);
    DB::table('__rst_c0ffee123456_transactions')->insert([
        'id' => 21,
        'user_id' => 1,
        'type' => 'order',
        'transaction_id' => 30,
        'amount' => -10,
        'balance_before' => 50,
        'balance_after' => 40,
    ]);

    $report = app(RestoreValidator::class)->validateShadow($context);

    expect(array_column($report->errors, 'code'))->not->toContain('order_transaction_orphan')
        ->and($report->passed)->toBeTrue()
        ->and($report->errors)->toBe([]);
});

it('把影子结构语义差异作为硬错误且报告不包含行数据或 SQL', function (): void {
    $context = task8CreateValidShadowContext();
    DB::statement('ALTER TABLE `__rst_c0ffee123456_users` ADD COLUMN `secret_token` varchar(255) NULL');

    $report = app(RestoreValidator::class)->validateShadow($context);
    $encoded = json_encode($report->errors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($report->passed)->toBeFalse()
        ->and(array_column($report->errors, 'code'))->toContain('schema_mismatch')
        ->and($encoded)->not->toContain('SELECT ')
        ->and($encoded)->not->toContain('secret-value');
});

it('兼容未记录字符集和生成表达式字段的旧版备份 Schema', function (): void {
    $context = task8CreateValidShadowContext();
    $schema = $context->schema;
    foreach ($schema['tables'] as &$table) {
        foreach ($table['columns'] as &$column) {
            unset($column['character_set'], $column['generation_expression']);
        }
        unset($column);
    }
    unset($table);
    $legacyContext = task8Context($schema, $context->shadowTableMap, $context->desiredForeignKeys);

    $report = app(RestoreValidator::class)->validateShadow($legacyContext);

    expect($report->passed)->toBeTrue()
        ->and($report->errors)->toBe([]);
});

it('逐条反关联检测备份外键中的孤儿数据', function (): void {
    $context = task8CreateValidShadowContext();
    DB::table('__rst_c0ffee123456_task8_children')->where('id', 1)->update(['parent_id' => 999]);

    $report = app(RestoreValidator::class)->validateShadow($context);
    $error = collect($report->errors)->firstWhere('code', 'foreign_key_orphan');

    expect($report->passed)->toBeFalse()
        ->and($error)->not->toBeNull()
        ->and($error['count'])->toBe(1)
        ->and($error['constraint'])->toBe('task8_children_parent_fk');
});

it('续跑时只接受与备份定义完全一致的影子物理外键', function (): void {
    $context = task8CreateValidShadowContext();
    DB::statement('ALTER TABLE `__rst_c0ffee123456_task8_children` ADD CONSTRAINT `task8_children_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `__rst_c0ffee123456_task8_parents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');

    $report = app(RestoreValidator::class)->validateShadow($context);
    expect($report->errors)->toBe([])
        ->and($report->passed)->toBeTrue();
});

it('检测管理员可用性 用户唯一性和交易生成键唯一性', function (): void {
    $context = task8CreateValidShadowContext();
    DB::table('__rst_c0ffee123456_admins')->update(['status' => 0]);
    DB::table('__rst_c0ffee123456_users')->insert([
        'id' => 2,
        'username' => 'member',
        'email' => 'member@example.test',
        'mobile' => '13800000000',
        'balance' => 0,
    ]);
    DB::table('__rst_c0ffee123456_transactions')->insert([
        [
            'id' => 21,
            'user_id' => 1,
            'type' => 'cancel',
            'transaction_id' => 30,
            'amount' => 1,
            'balance_before' => 50,
            'balance_after' => 51,
        ],
        [
            'id' => 22,
            'user_id' => 1,
            'type' => 'cancel',
            'transaction_id' => 30,
            'amount' => -1,
            'balance_before' => 51,
            'balance_after' => 50,
        ],
    ]);

    $report = app(RestoreValidator::class)->validateShadow($context);
    $codes = array_column($report->errors, 'code');

    expect($report->passed)->toBeFalse()
        ->and($codes)->toContain('admin_unusable')
        ->and($codes)->toContain('user_username_duplicate')
        ->and($codes)->toContain('user_email_duplicate')
        ->and($codes)->toContain('user_mobile_duplicate')
        ->and($codes)->toContain('transaction_dedup_duplicate')
        ->and($codes)->toContain('fund_event_duplicate');
});

it('沿用资金四道网并补充订单流水存在性与交易余额算术', function (): void {
    $context = task8CreateValidShadowContext();
    DB::table('__rst_c0ffee123456_users')->where('id', 1)->update(['balance' => 99]);
    DB::table('__rst_c0ffee123456_transactions')->where('id', 20)->update([
        'amount' => 40,
        'balance_after' => 39,
    ]);
    DB::table('__rst_c0ffee123456_transactions')->insert([
        'id' => 23,
        'user_id' => 1,
        'type' => 'order',
        'transaction_id' => 999,
        'amount' => -10,
        'balance_before' => 40,
        'balance_after' => 30,
    ]);

    $report = app(RestoreValidator::class)->validateShadow($context);
    $codes = array_column($report->errors, 'code');

    expect($report->passed)->toBeFalse()
        ->and($codes)->toContain('fund_accounting_identity')
        ->and($codes)->toContain('fund_amount_pairing')
        ->and($codes)->toContain('order_transaction_orphan')
        ->and($codes)->toContain('transaction_balance_arithmetic');
});

it('跨程序 Schema 的 active 校验只执行数据库通用检查', function (): void {
    DB::statement('CREATE TABLE `task8_legacy_records` (`id` bigint unsigned NOT NULL, `payload` varchar(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::table('task8_legacy_records')->insert(['id' => 1, 'payload' => 'legacy']);
    $connection = (string) config('database.default');
    $export = app(DatabaseStructureService::class)->exportBackupStructure($connection);
    $schema = ['tables' => ['task8_legacy_records' => $export['tables']['task8_legacy_records']]];
    $context = task8Context(
        $schema,
        ['task8_legacy_records' => 'task8_legacy_records'],
        [],
    );

    $report = app(RestoreValidator::class)->validateActive($context);
    expect($report->passed)->toBeTrue()
        ->and($report->metrics['model_smoke'])->toBe('skipped_schema_difference');
});

it('SQL DDL 推导的低保证结构仍校验索引事实', function (): void {
    DB::statement('CREATE TABLE `task8_legacy_records` (`id` bigint unsigned NOT NULL, `payload` varchar(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    $connection = (string) config('database.default');
    $export = app(DatabaseStructureService::class)->exportBackupStructure($connection);
    $schema = ['tables' => ['task8_legacy_records' => $export['tables']['task8_legacy_records']]];
    $schema['tables']['task8_legacy_records']['indexes'] = [];
    $context = task8Context(
        $schema,
        ['task8_legacy_records' => 'task8_legacy_records'],
        [],
        authoritative: false,
    );

    $report = app(RestoreValidator::class)->validateActive($context);

    expect($report->passed)->toBeFalse()
        ->and(array_column($report->errors, 'code'))->toContain('schema_mismatch');
});

it('当前程序 Schema 一致时追加 Laravel 模型只读冒烟', function (): void {
    Admin::factory()->create(['status' => 1]);
    $connection = (string) config('database.default');
    $database = (string) config("database.connections.{$connection}.database");
    $backupService = app(BackupService::class);
    $retainedLogs = $backupService->resolveRetainedTables($database);
    $schema = $backupService->filterStructureTables(
        app(DatabaseStructureService::class)->exportBackupStructure($connection),
        $retainedLogs,
    );
    $tables = array_keys($schema['tables']);
    $foreignKeys = [];
    foreach ($schema['tables'] as $table => $definition) {
        foreach ($definition['foreign_keys'] ?? [] as $name => $foreignKey) {
            $foreignKeys[$name] = ['table' => $table] + $foreignKey;
        }
    }
    $context = task8Context($schema, array_combine($tables, $tables), $foreignKeys, $retainedLogs);

    $report = app(RestoreValidator::class)->validateActive($context);
    $explicitTimestampDefaults = (bool) DB::selectOne(
        'SELECT @@SESSION.explicit_defaults_for_timestamp AS enabled',
    )->enabled;
    expect($report->passed)->toBeTrue()
        ->and($report->metrics['model_smoke'])->toBe(
            $explicitTimestampDefaults ? 'passed' : 'skipped_schema_difference',
        );
});

function task8CreateValidShadowContext(): RestoreContext
{
    DB::statement('CREATE TABLE `__rst_c0ffee123456_admins` (`id` int unsigned NOT NULL, `username` varchar(20) NOT NULL, `password` varchar(128) NOT NULL, `status` tinyint unsigned NOT NULL DEFAULT 1, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_c0ffee123456_users` (`id` bigint unsigned NOT NULL, `username` varchar(20) NOT NULL, `email` varchar(50) NULL, `mobile` varchar(20) NULL, `balance` decimal(10,2) NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_c0ffee123456_orders` (`id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL, `amount` decimal(10,2) NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `orders_user_idx` (`user_id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_c0ffee123456_funds` (`id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL, `amount` decimal(10,2) NOT NULL, `type` varchar(20) NOT NULL, `status` tinyint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `funds_user_idx` (`user_id`)) ENGINE=InnoDB');
    DB::statement("CREATE TABLE `__rst_c0ffee123456_transactions` (`id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL, `type` varchar(20) NOT NULL, `transaction_id` bigint unsigned NOT NULL, `amount` decimal(10,2) NOT NULL, `balance_before` decimal(10,2) NOT NULL, `balance_after` decimal(10,2) NOT NULL, `dedup_key` varchar(64) GENERATED ALWAYS AS (CASE WHEN (`type` = 'order') THEN NULL ELSE concat(`type`,':',`transaction_id`) END) VIRTUAL, PRIMARY KEY (`id`), KEY `transactions_user_idx` (`user_id`)) ENGINE=InnoDB");
    DB::statement('CREATE TABLE `__rst_c0ffee123456_task8_parents` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_c0ffee123456_task8_children` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NULL, PRIMARY KEY (`id`), KEY `children_parent_idx` (`parent_id`)) ENGINE=InnoDB');

    DB::table('__rst_c0ffee123456_admins')->insert(['id' => 1, 'username' => 'root', 'password' => 'secret-value', 'status' => 1]);
    DB::table('__rst_c0ffee123456_users')->insert(['id' => 1, 'username' => 'member', 'email' => 'member@example.test', 'mobile' => '13800000000', 'balance' => 50]);
    DB::table('__rst_c0ffee123456_orders')->insert(['id' => 30, 'user_id' => 1, 'amount' => 10]);
    DB::table('__rst_c0ffee123456_funds')->insert(['id' => 10, 'user_id' => 1, 'amount' => 50, 'type' => 'addfunds', 'status' => 1]);
    DB::table('__rst_c0ffee123456_transactions')->insert(['id' => 20, 'user_id' => 1, 'type' => 'addfunds', 'transaction_id' => 10, 'amount' => 50, 'balance_before' => 0, 'balance_after' => 50]);
    DB::table('__rst_c0ffee123456_task8_parents')->insert(['id' => 1]);
    DB::table('__rst_c0ffee123456_task8_children')->insert(['id' => 1, 'parent_id' => 1]);

    $map = [
        'admins' => '__rst_c0ffee123456_admins',
        'users' => '__rst_c0ffee123456_users',
        'orders' => '__rst_c0ffee123456_orders',
        'funds' => '__rst_c0ffee123456_funds',
        'transactions' => '__rst_c0ffee123456_transactions',
        'task8_parents' => '__rst_c0ffee123456_task8_parents',
        'task8_children' => '__rst_c0ffee123456_task8_children',
    ];
    $connection = (string) config('database.default');
    $export = app(DatabaseStructureService::class)->exportBackupStructure($connection);
    $schema = ['tables' => []];
    foreach ($map as $source => $physical) {
        $schema['tables'][$source] = $export['tables'][$physical];
    }
    $foreignKey = [
        'columns' => ['parent_id'],
        'references' => ['table' => 'task8_parents', 'columns' => ['id']],
        'on_delete' => 'RESTRICT',
        'on_update' => 'RESTRICT',
    ];
    $schema['tables']['task8_children']['foreign_keys']['task8_children_parent_fk'] = $foreignKey;

    return task8Context(
        $schema,
        $map,
        ['task8_children_parent_fk' => ['table' => 'task8_children'] + $foreignKey],
    );
}

/**
 * @param  array<string, mixed>  $schema
 * @param  array<string, string>  $map
 * @param  array<string, array<string, mixed>>  $foreignKeys
 */
function task8Context(
    array $schema,
    array $map,
    array $foreignKeys,
    array $retainedLogs = [],
    bool $authoritative = true,
): RestoreContext {
    $tables = array_keys($map);

    return new RestoreContext(
        restoreToken: TASK8_TOKEN,
        artifactSha256: str_repeat('8', 64),
        schema: $schema,
        schemaAuthoritative: $authoritative,
        sourceTables: $tables,
        businessTables: $tables,
        runtimeResetTables: [],
        retainedLogTables: $retainedLogs,
        shadowTableMap: $map,
        oldTableMap: array_map(fn (string $table): string => '__old_'.TASK8_TOKEN.'_'.$table, array_combine($tables, $tables)),
        columnOrder: array_map(fn (array $table): array => array_keys($table['columns']), $schema['tables']),
        generatedColumns: array_map(
            fn (array $table): array => array_keys(array_filter(
                $table['columns'],
                fn (array $column): bool => trim((string) ($column['generation_expression'] ?? '')) !== '',
            )),
            $schema['tables'],
        ),
        desiredForeignKeys: $foreignKeys,
        legacyAdjunctTables: [],
    );
}

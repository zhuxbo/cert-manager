<?php

use Illuminate\Support\Facades\Schema;

/**
 * 回归测试：补两批缺失索引（acmes status/created_at/product_id + 四张日志表 created_at）。
 *
 * - RefreshDatabase 已跑过全部迁移，断言目标索引存在。
 * - 迁移 up() 必须幂等：索引已存在的环境重跑不应报错（Duplicate key name）。
 */
function indexExistsOnColumn(string $table, string $column): bool
{
    return collect(Schema::getIndexes($table))
        ->contains(fn ($idx) => in_array($column, $idx['columns'] ?? [], true));
}

test('acmes gains status/created_at/product_id indexes', function () {
    expect(indexExistsOnColumn('acmes', 'status'))->toBeTrue('acmes.status 应有索引');
    expect(indexExistsOnColumn('acmes', 'created_at'))->toBeTrue('acmes.created_at 应有索引');
    expect(indexExistsOnColumn('acmes', 'product_id'))->toBeTrue('acmes.product_id 应有索引');
});

test('log tables gain created_at index', function () {
    foreach (['ca_logs', 'callback_logs', 'error_logs', 'user_logs'] as $table) {
        expect(indexExistsOnColumn($table, 'created_at'))
            ->toBeTrue("{$table}.created_at 应有索引");
    }
});

test('migration up is idempotent on already-indexed schema', function () {
    $migration = require database_path('migrations/2026_06_01_000001_add_missing_indexes_to_acmes_and_logs.php');

    // 索引已存在，第二次跑 up() 应被 getIndexes 判存短路，不抛 Duplicate key name
    $migration->up();

    expect(indexExistsOnColumn('acmes', 'status'))->toBeTrue();
    expect(indexExistsOnColumn('ca_logs', 'created_at'))->toBeTrue();
});

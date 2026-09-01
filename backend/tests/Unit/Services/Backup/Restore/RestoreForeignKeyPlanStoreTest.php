<?php

declare(strict_types=1);

use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestoreForeignKeyPlanStore;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    restorePlanCleanup();
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'ssl_manager_test',
    ]);
});

afterEach(function (): void {
    restorePlanCleanup();
});

it('原子发布并读取与恢复上下文绑定的不可变外键计划', function (): void {
    $context = restorePlanContext();
    $foreignKeys = restorePlanForeignKeys();
    $store = new RestoreForeignKeyPlanStore;

    $store->write($context, $foreignKeys);
    $store->write($context, $foreignKeys);

    $path = restorePlanPath();
    $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($store->load($context))->toBe($foreignKeys)
        ->and($payload['restore_token'])->toBe($context->restoreToken)
        ->and($payload['database'])->toBe('ssl_manager_test')
        ->and($payload['artifact_sha256'])->toBe($context->artifactSha256)
        ->and($payload)->toHaveKeys(['context_fingerprint', 'swap_tables', 'active_foreign_keys', 'checksum'])
        ->and($payload)->not->toHaveKeys(['phase', 'progress', 'status'])
        ->and(glob(restorePlanPath().'.*.part'))->toBe([]);
});

it('拒绝损坏、跨数据库或与既有内容冲突的恢复计划', function (): void {
    $context = restorePlanContext();
    $store = new RestoreForeignKeyPlanStore;
    $store->write($context, restorePlanForeignKeys());
    $path = restorePlanPath();

    $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $payload['database'] = 'another_database';
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

    expect(fn () => $store->load($context))
        ->toThrow(RuntimeException::class, '校验失败');

    restorePlanCleanup();
    $store->write($context, restorePlanForeignKeys());
    $different = restorePlanForeignKeys();
    $different['task7_child_parent_current']['on_delete'] = 'CASCADE';

    expect(fn () => $store->write($context, $different))
        ->toThrow(RuntimeException::class, '内容不一致');
});

it('删除最后一个恢复计划后清理空 restores 目录', function (): void {
    $context = restorePlanContext();
    $store = new RestoreForeignKeyPlanStore;
    $store->write($context, restorePlanForeignKeys());
    $directory = dirname(restorePlanPath());

    expect(is_dir($directory))->toBeTrue();

    $store->delete($context);

    expect(is_file(restorePlanPath()))->toBeFalse()
        ->and(is_dir($directory))->toBeFalse();
});

function restorePlanPath(): string
{
    return storage_path('restores/d1e2f3a4b5c6.json');
}

function restorePlanCleanup(): void
{
    foreach (array_merge([restorePlanPath()], glob(restorePlanPath().'.*.part') ?: []) as $path) {
        @unlink($path);
    }
    @rmdir(dirname(restorePlanPath()));
}

function restorePlanContext(): RestoreContext
{
    return new RestoreContext(
        restoreToken: 'd1e2f3a4b5c6',
        artifactSha256: str_repeat('a', 64),
        schema: ['tables' => []],
        schemaAuthoritative: true,
        sourceTables: ['task7_parent', 'task7_child'],
        businessTables: ['task7_parent', 'task7_child'],
        runtimeResetTables: [],
        retainedLogTables: [],
        shadowTableMap: [
            'task7_parent' => '__rst_d1e2f3a4b5c6_task7_parent',
            'task7_child' => '__rst_d1e2f3a4b5c6_task7_child',
        ],
        oldTableMap: [
            'task7_parent' => '__old_d1e2f3a4b5c6_task7_parent',
            'task7_child' => '__old_d1e2f3a4b5c6_task7_child',
        ],
        columnOrder: [],
        generatedColumns: [],
        desiredForeignKeys: [],
        legacyAdjunctTables: [],
    );
}

/** @return array<string, array<string, mixed>> */
function restorePlanForeignKeys(): array
{
    return [
        'task7_child_parent_current' => [
            'table' => 'task7_child',
            'columns' => ['parent_id'],
            'references' => ['table' => 'task7_parent', 'columns' => ['id']],
            'on_delete' => 'NO ACTION',
            'on_update' => 'CASCADE',
        ],
    ];
}

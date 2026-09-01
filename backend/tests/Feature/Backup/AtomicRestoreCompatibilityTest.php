<?php

declare(strict_types=1);

use App\Services\Backup\SqlDumpRewritePlan;
use App\Services\Backup\SqlDumpRewriter;
use Tests\Support\Backup\SqlDumpFixtureBuilder;

function task12RewriteFixture(SqlDumpFixtureBuilder $fixture, int $chunkBytes = 7): string
{
    $rewriter = new SqlDumpRewriter($fixture->rewritePlan());
    $sql = $fixture->dump();
    $output = '';
    for ($offset = 0; $offset < strlen($sql); $offset += $chunkBytes) {
        $output .= $rewriter->push(substr($sql, $offset, $chunkBytes));
    }

    return $output.$rewriter->finish();
}

test('固定种子备份夹具可重复并覆盖恢复改写边界', function (): void {
    $fixture = new SqlDumpFixtureBuilder;
    $sql = $fixture->dump(16);

    expect($sql)->toBe($fixture->dump(16))
        ->toContain("成员\\'0😀")
        ->toContain('0x00ffCAFE')
        ->toContain("X'1020'")
        ->toContain("b'10101010'")
        ->toContain('GENERATED ALWAYS AS')
        ->toContain('CONSTRAINT `task12_children_parent_fk` FOREIGN KEY')
        ->not->toContain('activity_logs')
        ->not->toContain('easy_logs')
        ->not->toContain('cloud_deploy_logs');
});

test('逐块改写同时处理显式隐式生成列、多值 INSERT、二进制和外键', function (): void {
    $fixture = new SqlDumpFixtureBuilder;
    $rewritten = task12RewriteFixture($fixture, 1);

    expect($rewritten)
        ->toContain('CREATE TABLE `__rst_abcdef123456_transactions`')
        ->toContain('INSERT INTO `__rst_abcdef123456_transactions` (`id`,`user_id`,`type`,`transaction_id`,`payload`) VALUES')
        ->toContain("(1,718793000000000000,'order',9001,0x00ffCAFE)")
        ->toContain("(3,718793000000000000,'addfunds',9003,b'10101010')")
        ->toContain('CREATE TABLE `__rst_abcdef123456_agisos`')
        ->not->toContain("'order:9001'")
        ->not->toContain("'addfunds:9003'")
        ->not->toContain('FOREIGN KEY')
        ->not->toContain('task12_children_parent_fk');
});

test('5.7、8.0、8.4 与历史 MariaDB 客户端产物共享同一文件输入契约', function (
    string $series,
    string $clientVendor,
): void {
    $fixture = new SqlDumpFixtureBuilder;
    $schema = $fixture->schema($series, clientVendor: $clientVendor);
    $rewritten = task12RewriteFixture($fixture);

    expect($schema['backup_meta']['format_version'])->toBe(2)
        ->and($schema['backup_meta']['application']['version'])->toBe('2.9.0-fixture')
        ->and($schema['backup_meta']['included_tables'])->toBe(SqlDumpFixtureBuilder::SOURCE_TABLES)
        ->and($schema['backup_meta']['excluded_tables'])->toBe(array_merge(
            SqlDumpFixtureBuilder::RUNTIME_TABLES,
            SqlDumpFixtureBuilder::RETAINED_LOG_TABLES,
        ))
        ->and($rewritten)->toContain('INSERT INTO `__rst_abcdef123456_users`');

    if ($clientVendor === 'mariadb') {
        expect($schema['backup_meta']['toolchain']['client_version'])->toContain('MariaDB');
    }
})->with([
    'MySQL 5.7' => ['5.7', 'mysql'],
    'MySQL 8.0' => ['8.0', 'mysql'],
    'MySQL 8.4' => ['8.4', 'mysql'],
    '历史 MariaDB-client 文件' => ['8.0', 'mariadb'],
]);

test('旧 Schema 元数据仍可作为权威表结构且日志保留与运行时清空分类不漂移', function (): void {
    $fixture = new SqlDumpFixtureBuilder;
    $legacy = $fixture->schema(legacyMetadata: true);
    $active = $fixture->activeOnlyRows();

    expect($legacy)->not->toHaveKey('backup_meta')
        ->and(array_keys($legacy['tables']))->toBe(array_merge(
            SqlDumpFixtureBuilder::SOURCE_TABLES,
            SqlDumpFixtureBuilder::RUNTIME_TABLES,
        ))
        ->and(array_intersect(array_keys($active), SqlDumpFixtureBuilder::RETAINED_LOG_TABLES))
        ->toBe(SqlDumpFixtureBuilder::RETAINED_LOG_TABLES)
        ->and(array_values(array_intersect(array_keys($active), SqlDumpFixtureBuilder::RUNTIME_TABLES)))
        ->toBe(SqlDumpFixtureBuilder::RUNTIME_TABLES)
        ->and($active['activity_logs'][0]['user_id'])->toBe(999)
        ->and($legacy['tables']['task12_children']['foreign_keys'])
        ->toHaveKey('task12_children_parent_fk');
});

test('无 Schema 的真实 mysqldump 夹具可从同一流推导恢复结构', function (): void {
    $fixture = new SqlDumpFixtureBuilder;
    $rewriter = new SqlDumpRewriter(new SqlDumpRewritePlan(
        [],
        [],
        [],
        inferSchema: true,
    ));
    $sql = $fixture->dump(16);
    for ($offset = 0; $offset < strlen($sql); $offset += 13) {
        $rewriter->push(substr($sql, $offset, 13));
    }
    $rewriter->finish();
    $schema = $rewriter->inferredSchema();

    expect(array_keys($schema['tables']))->toBe(SqlDumpFixtureBuilder::SOURCE_TABLES)
        ->and(array_keys($schema['tables']['transactions']['columns']))
        ->toBe(['id', 'user_id', 'type', 'transaction_id', 'dedup_key', 'payload'])
        ->and($schema['tables']['transactions']['columns']['dedup_key']['generation_expression'])
        ->not->toBe('')
        ->and($schema['tables']['task12_children']['foreign_keys'])
        ->toHaveKey('task12_children_parent_fk');
});

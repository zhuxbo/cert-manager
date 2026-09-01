<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestoreForeignKeyPlanStore;
use App\Services\Backup\Restore\RestoreSchemaManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const TASK7_TOKEN = 'a1b2c3d4e5f6';

afterEach(function (): void {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::statement('DROP TABLE IF EXISTS `__old_a1b2c3d4e5f6_task7_runtime`, `__rst_a1b2c3d4e5f6_task7_runtime`, `task7_runtime`, `__rst_a1b2c3d4e5f6_users`, `__rst_a1b2c3d4e5f6_admins`, `__old_a1b2c3d4e5f6_task7_child`, `__old_a1b2c3d4e5f6_task7_parent`, `__rst_a1b2c3d4e5f6_task7_child`, `__rst_a1b2c3d4e5f6_task7_parent`, `__rst_a1b2c3d4e5f6_task7_unrelated`, `task7_child`, `task7_parent`, `task7_activity_logs`, `task7_conflict_owner`, `task7_conflict_parent`');
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    @unlink(storage_path('restores/'.TASK7_TOKEN.'.json'));
});

it('跨进程续接外键转换并以一条 RENAME 完成切换和精确回滚', function (): void {
    DB::statement('CREATE TABLE `task7_parent` (`id` bigint unsigned NOT NULL, `label` varchar(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`), CONSTRAINT `task7_child_parent_current` FOREIGN KEY (`parent_id`) REFERENCES `task7_parent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_activity_logs` (`id` bigint unsigned NOT NULL, `message` varchar(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_runtime` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `payload` varchar(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::table('task7_parent')->insert(['id' => 1, 'label' => 'active']);
    DB::table('task7_child')->insert(['id' => 1, 'parent_id' => 1]);
    DB::table('__rst_a1b2c3d4e5f6_task7_parent')->insert(['id' => 2, 'label' => 'restored']);
    DB::table('__rst_a1b2c3d4e5f6_task7_child')->insert(['id' => 2, 'parent_id' => 2]);
    DB::table('task7_activity_logs')->insert(['id' => 1, 'message' => 'keep']);
    DB::table('task7_runtime')->insert(['payload' => 'discard']);

    $foreignKey = [
        'table' => 'task7_child',
        'columns' => ['parent_id'],
        'references' => ['table' => 'task7_parent', 'columns' => ['id']],
        'on_delete' => 'RESTRICT',
        'on_update' => 'CASCADE',
    ];
    $schema = ['tables' => [
        'task7_parent' => task7BusinessSchema(false),
        'task7_child' => task7BusinessSchema(true),
        'task7_runtime' => task7TableSchema(),
    ]];
    $context = task7Context(
        $schema,
        ['task7_parent', 'task7_child'],
        ['task7_runtime'],
        ['task7_child_parent_canonical' => $foreignKey],
        ['task7_activity_logs'],
    );
    $manager = app(RestoreSchemaManager::class);

    $manager->prepareEmptyRuntimeShadows($context);
    $manager->prepareCanonicalForeignKeys($context);

    $shadowFk = task7ForeignKey('__rst_a1b2c3d4e5f6_task7_child');
    expect($shadowFk)->not->toBeNull()
        ->and($shadowFk->CONSTRAINT_NAME)->toBe('task7_child_parent_canonical')
        ->and($shadowFk->REFERENCED_TABLE_NAME)->toBe('__rst_a1b2c3d4e5f6_task7_parent')
        ->and(task7ForeignKey('task7_child'))->toBeNull();
    app(RestoreSchemaManager::class)->prepareCanonicalForeignKeys($context);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_starts_with(strtoupper(trim($query->sql)), 'RENAME TABLE')) {
            $queries[] = $query->sql;
        }
    });
    $cutover = app(RestoreSchemaManager::class)->cutover($context);

    expect($cutover->operation)->toBe('cutover')
        ->and($cutover->swappedTables)->toBe(['task7_parent', 'task7_child', 'task7_runtime'])
        ->and($queries)->toHaveCount(1)
        ->and(DB::table('task7_parent')->value('label'))->toBe('restored')
        ->and(DB::table('task7_runtime')->count())->toBe(0)
        ->and(task7ForeignKey('task7_child')->REFERENCED_TABLE_NAME)->toBe('task7_parent')
        ->and(DB::table('task7_activity_logs')->value('message'))->toBe('keep');

    $queries = [];
    $rollback = app(RestoreSchemaManager::class)->rollback($context);

    expect($rollback->operation)->toBe('rollback')
        ->and($rollback->swappedTables)->toBe(['task7_parent', 'task7_child', 'task7_runtime'])
        ->and($queries)->toHaveCount(1)
        ->and(DB::table('task7_parent')->value('label'))->toBe('active')
        ->and(DB::table('task7_runtime')->value('payload'))->toBe('discard')
        ->and(task7ForeignKey('task7_child')->CONSTRAINT_NAME)->toBe('task7_child_parent_current')
        ->and(task7ForeignKey('task7_child')->REFERENCED_TABLE_NAME)->toBe('task7_parent')
        ->and(DB::table('task7_activity_logs')->value('message'))->toBe('keep');
});

it('rename 前失败时从持久计划恢复 active 外键并允许丢弃 staged 表', function (): void {
    DB::statement('CREATE TABLE `task7_parent` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`), CONSTRAINT `task7_child_parent_current` FOREIGN KEY (`parent_id`) REFERENCES `task7_parent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`)) ENGINE=InnoDB');
    $context = task7Context(
        ['tables' => [
            'task7_parent' => task7BusinessSchema(false),
            'task7_child' => task7BusinessSchema(true),
        ]],
        ['task7_parent', 'task7_child'],
        [],
        ['task7_child_parent_canonical' => [
            'table' => 'task7_child',
            'columns' => ['parent_id'],
            'references' => ['table' => 'task7_parent', 'columns' => ['id']],
            'on_delete' => 'RESTRICT',
            'on_update' => 'CASCADE',
        ]],
    );
    $manager = app(RestoreSchemaManager::class);

    $manager->prepareCanonicalForeignKeys($context);
    expect(task7ForeignKey('task7_child'))->toBeNull()
        ->and(task7ForeignKey('__rst_a1b2c3d4e5f6_task7_child'))->not->toBeNull();

    $manager->restoreActiveForeignKeys($context);
    $manager->dropStagedTables($context);

    expect(task7ForeignKey('task7_child')->CONSTRAINT_NAME)->toBe('task7_child_parent_current')
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_parent'))->toBeFalse()
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_child'))->toBeFalse();
});

it('使恢复身份版本严格递增并只推进 admins 自增游标', function (): void {
    $user = User::factory()->create(['token_version' => 8]);
    $admin = Admin::factory()->create(['token_version' => 3]);
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_users` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `token_version` int unsigned NOT NULL, `logout_at` timestamp NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=41');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_admins` (`id` int unsigned NOT NULL AUTO_INCREMENT, `token_version` int unsigned NOT NULL, `logout_at` timestamp NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=9');
    DB::table('__rst_a1b2c3d4e5f6_users')->insert([
        ['id' => $user->id, 'token_version' => 2, 'logout_at' => null],
        ['id' => $user->id + 1, 'token_version' => 11, 'logout_at' => null],
    ]);
    DB::table('__rst_a1b2c3d4e5f6_admins')->insert([
        ['id' => $admin->id, 'token_version' => 6, 'logout_at' => null],
        ['id' => 7000, 'token_version' => 2, 'logout_at' => null],
    ]);
    $userCursorBefore = (int) DB::selectOne(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['__rst_a1b2c3d4e5f6_users'],
    )->AUTO_INCREMENT;
    $schema = ['tables' => [
        'users' => task7IdentitySchema('bigint unsigned', 999999),
        'admins' => task7IdentitySchema('int unsigned', 5000),
    ]];
    $context = task7Context($schema, ['users', 'admins'], []);
    $restoredAt = CarbonImmutable::parse('2026-08-30 12:34:56', 'UTC');

    app(RestoreSchemaManager::class)->normalizeIdentityState($context, $restoredAt);

    $restoredUser = DB::table('__rst_a1b2c3d4e5f6_users')->where('id', $user->id)->first();
    $orphanUser = DB::table('__rst_a1b2c3d4e5f6_users')->where('id', $user->id + 1)->first();
    $restoredAdmin = DB::table('__rst_a1b2c3d4e5f6_admins')->where('id', $admin->id)->first();
    $adminCursor = DB::selectOne(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['__rst_a1b2c3d4e5f6_admins'],
    );
    $userCursor = DB::selectOne(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['__rst_a1b2c3d4e5f6_users'],
    );

    expect((int) $restoredUser->token_version)->toBe(9)
        ->and((int) $orphanUser->token_version)->toBe(12)
        ->and((int) $restoredAdmin->token_version)->toBe(7)
        ->and((string) $restoredUser->logout_at)->toBe('2026-08-30 12:34:56')
        ->and((int) $adminCursor->AUTO_INCREMENT)->toBe(7001)
        ->and((int) $userCursor->AUTO_INCREMENT)->toBe($userCursorBefore);
});

it('从备份 Schema 创建空运行时影子且不改变 active 表', function (): void {
    DB::statement('CREATE TABLE `task7_runtime` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `payload` varchar(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::table('task7_runtime')->insert(['payload' => 'active']);

    $context = task7Context(
        schema: ['tables' => ['task7_runtime' => task7TableSchema()]],
        sourceTables: [],
        runtimeTables: ['task7_runtime'],
    );

    app(RestoreSchemaManager::class)->prepareEmptyRuntimeShadows($context);

    expect(DB::table('task7_runtime')->pluck('payload')->all())->toBe(['active'])
        ->and(DB::table($context->shadowTableMap['task7_runtime'])->count())->toBe(0);

    $indexes = DB::select(
        'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$context->shadowTableMap['task7_runtime']],
    );
    expect(array_column($indexes, 'INDEX_NAME'))->toContain('payload_idx')
        ->and(fn () => app(RestoreSchemaManager::class)->prepareEmptyRuntimeShadows($context))
        ->toThrow(RuntimeException::class, '已存在');
});

it('在执行任何 DDL 前拒绝生成列和不安全 Schema 片段', function (): void {
    $generated = task7TableSchema();
    $generated['columns']['payload']['generation_expression'] = 'concat(`payload`, \'x\')';
    $generatedContext = task7Context(
        ['tables' => ['task7_runtime' => $generated]],
        [],
        ['task7_runtime'],
    );

    expect(fn () => app(RestoreSchemaManager::class)->prepareEmptyRuntimeShadows($generatedContext))
        ->toThrow(RuntimeException::class, '不支持生成列')
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_runtime'))->toBeFalse();

    $unsafe = task7TableSchema();
    $unsafe['columns']['payload']['extra'] = 'auto_increment; DROP TABLE task7_runtime';
    $unsafeContext = task7Context(
        ['tables' => ['task7_runtime' => $unsafe]],
        [],
        ['task7_runtime'],
    );

    expect(fn () => app(RestoreSchemaManager::class)->prepareEmptyRuntimeShadows($unsafeContext))
        ->toThrow(RuntimeException::class, 'extra 定义无效')
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_runtime'))->toBeFalse();
});

it('身份版本溢出时不更新且旧 Schema 缺字段时保持原值', function (): void {
    $user = User::factory()->create(['token_version' => 1]);
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_users` (`id` bigint unsigned NOT NULL, `token_version` tinyint unsigned NOT NULL, `logout_at` timestamp NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::table('__rst_a1b2c3d4e5f6_users')->insert([
        'id' => $user->id,
        'token_version' => 255,
        'logout_at' => null,
    ]);
    $schema = task7IdentitySchema('bigint unsigned', 1);
    $schema['columns']['token_version']['type'] = 'tinyint unsigned';
    $context = task7Context(['tables' => ['users' => $schema]], ['users'], []);

    expect(fn () => app(RestoreSchemaManager::class)->normalizeIdentityState(
        $context,
        CarbonImmutable::parse('2026-08-30 12:34:56', 'UTC'),
    ))->toThrow(RuntimeException::class, '溢出')
        ->and((int) DB::table('__rst_a1b2c3d4e5f6_users')->value('token_version'))->toBe(255)
        ->and(DB::table('__rst_a1b2c3d4e5f6_users')->value('logout_at'))->toBeNull();

    DB::statement('DROP TABLE `__rst_a1b2c3d4e5f6_users`');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_users` (`id` bigint unsigned NOT NULL, `marker` varchar(16) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::table('__rst_a1b2c3d4e5f6_users')->insert(['id' => $user->id, 'marker' => 'legacy']);
    $legacySchema = task7IdentitySchema('bigint unsigned', 1);
    unset($legacySchema['columns']['token_version'], $legacySchema['columns']['logout_at']);
    $legacySchema['columns']['marker'] = task7Column('varchar(16)', false, '');
    $legacyContext = task7Context(['tables' => ['users' => $legacySchema]], ['users'], []);

    app(RestoreSchemaManager::class)->normalizeIdentityState(
        $legacyContext,
        CarbonImmutable::parse('2026-08-30 12:34:56', 'UTC'),
    );

    expect(DB::table('__rst_a1b2c3d4e5f6_users')->value('marker'))->toBe('legacy');
});

it('外键转换中途失败时只补偿本轮动作并重新启用检查', function (): void {
    DB::statement('CREATE TABLE `task7_parent` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`), CONSTRAINT `task7_child_parent_current` FOREIGN KEY (`parent_id`) REFERENCES `task7_parent` (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_conflict_parent` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_conflict_owner` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`), CONSTRAINT `task7_child_parent_canonical` FOREIGN KEY (`parent_id`) REFERENCES `task7_conflict_parent` (`id`)) ENGINE=InnoDB');

    $context = task7Context(
        ['tables' => [
            'task7_parent' => task7BusinessSchema(false),
            'task7_child' => task7BusinessSchema(true),
        ]],
        ['task7_parent', 'task7_child'],
        [],
        ['task7_child_parent_canonical' => [
            'table' => 'task7_child',
            'columns' => ['parent_id'],
            'references' => ['table' => 'task7_parent', 'columns' => ['id']],
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ]],
    );

    expect(fn () => app(RestoreSchemaManager::class)->prepareCanonicalForeignKeys($context))
        ->toThrow(QueryException::class)
        ->and(task7ForeignKey('task7_child')->CONSTRAINT_NAME)->toBe('task7_child_parent_current')
        ->and(task7ForeignKey('__rst_a1b2c3d4e5f6_task7_child'))->toBeNull()
        ->and((int) DB::selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS checks_enabled')->checks_enabled)->toBe(1);
});

it('切换后规范外键验证失败时用一条反向 RENAME 恢复原表和原外键', function (): void {
    DB::statement('CREATE TABLE `task7_parent` (`id` bigint unsigned NOT NULL, `label` varchar(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`), CONSTRAINT `task7_child_parent_current` FOREIGN KEY (`parent_id`) REFERENCES `task7_parent` (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_child` (`id` bigint unsigned NOT NULL, `parent_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `parent_idx` (`parent_id`)) ENGINE=InnoDB');
    DB::table('task7_parent')->insert(['id' => 1, 'label' => 'active']);
    DB::table('task7_child')->insert(['id' => 1, 'parent_id' => 1]);
    DB::table('__rst_a1b2c3d4e5f6_task7_parent')->insert(['id' => 2, 'label' => 'restored']);
    DB::table('__rst_a1b2c3d4e5f6_task7_child')->insert(['id' => 2, 'parent_id' => 2]);
    $context = task7Context(
        ['tables' => [
            'task7_parent' => task7BusinessSchema(false),
            'task7_child' => task7BusinessSchema(true),
        ]],
        ['task7_parent', 'task7_child'],
        [],
        ['task7_child_parent_canonical' => [
            'table' => 'task7_child',
            'columns' => ['parent_id'],
            'references' => ['table' => 'task7_parent', 'columns' => ['id']],
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ]],
    );
    $manager = app(RestoreSchemaManager::class);
    $manager->prepareCanonicalForeignKeys($context);

    $renameCount = 0;
    $sabotaged = false;
    DB::listen(function ($query) use (&$renameCount, &$sabotaged): void {
        if (! str_starts_with(strtoupper(trim($query->sql)), 'RENAME TABLE')) {
            return;
        }
        $renameCount++;
        if (! $sabotaged) {
            $sabotaged = true;
            DB::statement('ALTER TABLE `task7_child` DROP FOREIGN KEY `task7_child_parent_canonical`, ALGORITHM=INPLACE, LOCK=NONE');
        }
    });

    expect(fn () => $manager->cutover($context))
        ->toThrow(RuntimeException::class, '外键集合与恢复计划不一致')
        ->and($renameCount)->toBe(2)
        ->and(DB::table('task7_parent')->value('label'))->toBe('active')
        ->and(DB::table('__rst_a1b2c3d4e5f6_task7_parent')->value('label'))->toBe('restored')
        ->and(task7ForeignKey('task7_child')->CONSTRAINT_NAME)->toBe('task7_child_parent_current')
        ->and(task7TableExists('__old_a1b2c3d4e5f6_task7_parent'))->toBeFalse()
        ->and((int) DB::selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS checks_enabled')->checks_enabled)->toBe(1);
});

it('在换表集合不完整时于 RENAME 前失败并执行 max packet 硬边界', function (): void {
    DB::statement('CREATE TABLE `task7_parent` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__rst_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    DB::statement('CREATE TABLE `__old_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    $context = task7Context(
        ['tables' => ['task7_parent' => task7BusinessSchema(false)]],
        ['task7_parent'],
        [],
    );
    $manager = app(RestoreSchemaManager::class);
    $manager->prepareCanonicalForeignKeys($context);
    $renameCount = 0;
    DB::listen(function ($query) use (&$renameCount): void {
        if (str_starts_with(strtoupper(trim($query->sql)), 'RENAME TABLE')) {
            $renameCount++;
        }
    });

    expect(fn () => $manager->cutover($context))
        ->toThrow(RuntimeException::class, '表集合状态不完整')
        ->and($renameCount)->toBe(0);

    $packet = (int) DB::selectOne('SELECT @@SESSION.max_allowed_packet AS packet')->packet;
    $method = new ReflectionMethod(RestoreSchemaManager::class, 'assertPacketCapacity');
    expect(fn () => $method->invoke($manager, str_repeat('R', $packet)))
        ->toThrow(RuntimeException::class, 'max_allowed_packet');
});

it('分阶段和 old 清理各只删除上下文精确映射且不触碰日志或相似前缀', function (): void {
    foreach ([
        'task7_parent',
        'task7_child',
        '__rst_a1b2c3d4e5f6_task7_parent',
        '__rst_a1b2c3d4e5f6_task7_child',
        '__old_a1b2c3d4e5f6_task7_parent',
        '__old_a1b2c3d4e5f6_task7_child',
        '__rst_a1b2c3d4e5f6_task7_unrelated',
        'task7_activity_logs',
    ] as $table) {
        DB::statement('CREATE TABLE `'.$table.'` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    }
    DB::table('task7_activity_logs')->insert(['id' => 9]);
    $context = task7Context(
        ['tables' => [
            'task7_parent' => task7BusinessSchema(false),
            'task7_child' => task7BusinessSchema(true),
        ]],
        ['task7_parent', 'task7_child'],
        [],
        [],
        ['task7_activity_logs'],
    );
    $drops = [];
    DB::listen(function ($query) use (&$drops): void {
        if (str_starts_with(strtoupper(trim($query->sql)), 'DROP TABLE')) {
            $drops[] = $query->sql;
        }
    });
    $manager = app(RestoreSchemaManager::class);
    $planStore = new RestoreForeignKeyPlanStore;
    $planStore->write($context, []);

    $manager->dropStagedTables($context);
    expect($planStore->load($context))->toBe([]);
    $manager->dropOldTables($context);

    expect($drops)->toHaveCount(2)
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_parent'))->toBeFalse()
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_child'))->toBeFalse()
        ->and(task7TableExists('__old_a1b2c3d4e5f6_task7_parent'))->toBeFalse()
        ->and(task7TableExists('__old_a1b2c3d4e5f6_task7_child'))->toBeFalse()
        ->and(task7TableExists('__rst_a1b2c3d4e5f6_task7_unrelated'))->toBeTrue()
        ->and(DB::table('task7_activity_logs')->value('id'))->toBe(9)
        ->and(task7TableExists('task7_parent'))->toBeTrue()
        ->and(fn () => $planStore->load($context))->toThrow(RuntimeException::class, '不存在')
        ->and((int) DB::selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS checks_enabled')->checks_enabled)->toBe(1);
});

it('old 表集合不完整时保留现有表和恢复计划并拒绝清理', function (): void {
    DB::statement('CREATE TABLE `task7_parent` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `task7_child` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    DB::statement('CREATE TABLE `__old_a1b2c3d4e5f6_task7_parent` LIKE `task7_parent`');
    $context = task7Context(
        ['tables' => [
            'task7_parent' => task7BusinessSchema(false),
            'task7_child' => task7BusinessSchema(false),
        ]],
        ['task7_parent', 'task7_child'],
        [],
    );
    $planStore = new RestoreForeignKeyPlanStore;
    $planStore->write($context, []);

    expect(fn () => app(RestoreSchemaManager::class)->dropOldTables($context))
        ->toThrow(RuntimeException::class, '表集合状态不完整')
        ->and(task7TableExists('__old_a1b2c3d4e5f6_task7_parent'))->toBeTrue()
        ->and($planStore->load($context))->toBe([]);
});

/** @param array<string, mixed> $schema */
function task7Context(
    array $schema,
    array $sourceTables,
    array $runtimeTables,
    array $desiredForeignKeys = [],
    array $retainedLogs = [],
): RestoreContext {
    $swap = array_values(array_unique(array_merge($sourceTables, $runtimeTables)));
    $shadow = [];
    $old = [];
    $columns = [];
    foreach ($swap as $table) {
        $shadow[$table] = '__rst_'.TASK7_TOKEN.'_'.$table;
        $old[$table] = '__old_'.TASK7_TOKEN.'_'.$table;
        $columns[$table] = array_keys($schema['tables'][$table]['columns']);
    }

    return new RestoreContext(
        restoreToken: TASK7_TOKEN,
        artifactSha256: str_repeat('b', 64),
        schema: $schema,
        schemaAuthoritative: true,
        sourceTables: $sourceTables,
        businessTables: $sourceTables,
        runtimeResetTables: $runtimeTables,
        retainedLogTables: $retainedLogs,
        shadowTableMap: $shadow,
        oldTableMap: $old,
        columnOrder: $columns,
        generatedColumns: array_fill_keys($swap, []),
        desiredForeignKeys: $desiredForeignKeys,
        legacyAdjunctTables: [],
    );
}

/** @return array<string, mixed> */
function task7BusinessSchema(bool $child): array
{
    $columns = ['id' => task7Column('bigint unsigned', false, null)];
    $indexes = ['PRIMARY' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['id'], 'sub_parts' => [null]]];
    if ($child) {
        $columns['parent_id'] = task7Column('bigint unsigned', false, null);
        $indexes['parent_idx'] = ['unique' => false, 'type' => 'BTREE', 'columns' => ['parent_id'], 'sub_parts' => [null]];
    } else {
        $columns['label'] = task7Column('varchar(32)', false, '');
    }

    return [
        'engine' => 'InnoDB',
        'collation' => 'utf8mb4_unicode_ci',
        'comment' => '',
        'auto_increment' => null,
        'columns' => $columns,
        'indexes' => $indexes,
        'foreign_keys' => [],
    ];
}

function task7ForeignKey(string $table): ?object
{
    return DB::selectOne(
        'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
        [$table],
    );
}

function task7TableExists(string $table): bool
{
    return DB::selectOne(
        'SELECT COUNT(*) AS aggregate FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table],
    )->aggregate > 0;
}

/** @return array<string, mixed> */
function task7TableSchema(): array
{
    return [
        'engine' => 'InnoDB',
        'collation' => 'utf8mb4_unicode_ci',
        'comment' => 'runtime table',
        'auto_increment' => 17,
        'columns' => [
            'id' => task7Column('bigint unsigned', false, null, 'auto_increment'),
            'payload' => task7Column('varchar(64)', false, ''),
        ],
        'indexes' => [
            'PRIMARY' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['id'], 'sub_parts' => [null]],
            'payload_idx' => ['unique' => false, 'type' => 'BTREE', 'columns' => ['payload'], 'sub_parts' => [16]],
        ],
        'foreign_keys' => [],
    ];
}

/** @return array<string, mixed> */
function task7IdentitySchema(string $idType, int $autoIncrement): array
{
    return [
        'engine' => 'InnoDB',
        'collation' => 'utf8mb4_unicode_ci',
        'comment' => '',
        'auto_increment' => $autoIncrement,
        'columns' => [
            'id' => task7Column($idType, false, null, 'auto_increment'),
            'token_version' => task7Column('int unsigned', false, 0),
            'logout_at' => task7Column('timestamp', true, null),
        ],
        'indexes' => [
            'PRIMARY' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['id'], 'sub_parts' => [null]],
        ],
        'foreign_keys' => [],
    ];
}

/** @return array<string, mixed> */
function task7Column(string $type, bool $nullable, mixed $default, string $extra = ''): array
{
    return [
        'type' => $type,
        'nullable' => $nullable,
        'default' => $default,
        'extra' => $extra,
        'comment' => '',
        'generation_expression' => '',
    ];
}

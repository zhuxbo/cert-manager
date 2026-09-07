<?php

declare(strict_types=1);

use App\Services\Backup\BackupArtifactInspector;
use App\Services\Backup\BackupMetadataFactory;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestoreForeignKeyPlanStore;
use App\Services\Backup\Restore\RestorePreflight;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreRuntimeManager;
use App\Services\Binary\BinaryLocator;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

test('ActiveWithOld 真实续接校验备份身份，拒绝不匹配时保留旧表', function (string $planState) {
    $token = 'deadcafe0927';
    $table = 'review_restore_artifact';
    $old = "__old_{$token}_{$table}";
    $root = sys_get_temp_dir().'/restore-artifact-review-'.bin2hex(random_bytes(5));
    mkdir($root.'/databak', 0700, true);
    $originalStorage = storage_path();
    app()->useStoragePath($root);
    $created = [];
    try {
        foreach ([$table, $old] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException('Review fixture already exists; refusing to overwrite');
            }
        }
        DB::statement("CREATE TABLE `$table` (`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(20) NOT NULL) ENGINE=InnoDB");
        $created[] = $table;
        DB::table($table)->insert(['id' => 1, 'value' => $planState === 'same' ? 'artifact-B' : 'artifact-A']);
        DB::statement("CREATE TABLE `$old` LIKE `$table`");
        $created[] = $old;
        DB::table($old)->insert(['id' => 1, 'value' => 'original']);
        $export = app(DatabaseStructureService::class)->exportBackupStructure((string) config('database.default'));
        $structure = ['tables' => [$table => $export['tables'][$table]]];
        $hashes = [];
        foreach (['A' => 'backup_20260907_120001', 'B' => 'backup_20260907_120002'] as $letter => $id) {
            $sql = "CREATE TABLE `$table` (`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(20) NOT NULL) ENGINE=InnoDB;\nINSERT INTO `$table` VALUES (1,'artifact-$letter');\n";
            $gzip = gzencode($sql);
            file_put_contents($root.'/databak/'.$id.'.sql.gz', $gzip);
            $hashes[$letter] = hash('sha256', $gzip);
            $metadata = app(BackupMetadataFactory::class)->make($structure, [], ['compressed_bytes' => strlen($gzip), 'uncompressed_bytes' => strlen($sql), 'sha256' => $hashes[$letter]], [$table], []);
            file_put_contents($root.'/databak/'.$id.'.schema.json', json_encode($structure + $metadata));
        }
        app()->instance(BackupArtifactInspector::class, new BackupArtifactInspector($root.'/databak'));
        // 续接不执行 mysql 导入；隔离宿主客户端版本，数据库和身份校验仍使用真实实现。
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $client = $root.'/mysql-version';
        file_put_contents($client, "#!/bin/sh\n[ \"\$1\" = --version ] || exit 91\nprintf '%s\\n' ".escapeshellarg("mysql Ver $version (MySQL Community Server - GPL)")."\n");
        chmod($client, 0700);
        $locator = Mockery::mock(BinaryLocator::class);
        $locator->shouldReceive('mysql')->andReturn($client);
        $locator->shouldReceive('gzip')->andReturn(app(BinaryLocator::class)->gzip());
        app()->instance(MysqlToolchainChecker::class, new MysqlToolchainChecker($locator));
        // 仅替换运行时冻结和缓存清理，其余续接校验与表清理在真实测试库执行。
        app()->instance(RestoreRuntimeManager::class, new class
        {
            public function freeze(string $reason): void {}

            public function clearRuntimeState(callable $republish): void
            {
                $republish();
            }

            public function resume(): void {}
        });
        $request = new RestoreRequest('backup_20260907_120002', true, 'cli');
        $report = app(RestorePreflight::class)->inspect($request);
        $candidate = $report['context'] ?? null;
        if (! $candidate instanceof RestoreContext) {
            throw new RuntimeException(json_encode($report['hard_blockers']));
        }
        $contextA = new RestoreContext($token, $hashes['A'], $candidate->schema, true, [$table], [$table], [], $candidate->retainedLogTables,
            [$table => "__rst_{$token}_{$table}"], [$table => $old], $candidate->columnOrder, $candidate->generatedColumns, [], []);
        if ($planState !== 'missing') {
            $planContext = $planState === 'same'
                ? new RestoreContext($token, $hashes['B'], $candidate->schema, true, [$table], [$table], [], $candidate->retainedLogTables,
                    [$table => "__rst_{$token}_{$table}"], [$table => $old], $candidate->columnOrder, $candidate->generatedColumns, [], [])
                : $contextA;
            app(RestoreForeignKeyPlanStore::class)->write($planContext, []);
        }
        $restore = fn () => app(AtomicRestoreService::class)->restore($request, static fn (array $payload) => null);
        if ($planState === 'same') {
            expect($restore()->operation)->toBe('resumed')
                ->and(Schema::hasTable($old))->toBeFalse();
        } else {
            expect($restore)->toThrow(RuntimeException::class, $planState === 'missing' ? '计划不存在' : '上下文不匹配')
                ->and(Schema::hasTable($old))->toBeTrue()
                ->and(DB::table($old)->value('value'))->toBe('original');
        }
        expect(DB::table($table)->value('value'))->toBe($planState === 'same' ? 'artifact-B' : 'artifact-A');
    } finally {
        foreach (array_reverse($created) as $name) {
            DB::statement("DROP TABLE IF EXISTS `$name`");
        }
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($root);
    }
})->with(['same', 'different', 'missing']);

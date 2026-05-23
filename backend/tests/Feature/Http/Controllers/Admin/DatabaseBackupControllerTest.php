<?php

use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Admin;
use App\Services\Backup\BackupService;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();

    // 独立目录避免测试互相污染
    $this->testDir = storage_path('databak_test_'.uniqid());
    mkdir($this->testDir, 0755, true);

    // 劫持 BackupService basePath
    $this->service = new class($this->testDir) extends BackupService
    {
        public function __construct(private string $dir) {}

        public function basePath(): string
        {
            return $this->dir;
        }
    };
    $this->app->instance(BackupService::class, $this->service);
});

afterEach(function () {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        array_map('unlink', glob($this->testDir.'/*') ?: []);
        rmdir($this->testDir);
    }
});

function createFakeBackup(string $dir, string $id, bool $withSchema = true): void
{
    $sql = $dir.'/'.$id.'.sql.gz';
    $gz = gzopen($sql, 'wb');
    gzwrite($gz, "-- fake\n");
    gzclose($gz);

    if ($withSchema) {
        file_put_contents($dir.'/'.$id.'.schema.json', json_encode(['tables' => []]));
    }
}

test('列表返回所有备份及配套 schema 标记', function () {
    createFakeBackup($this->testDir, 'backup_20260424_120000', true);
    createFakeBackup($this->testDir, 'pre_restore_20260424_130000', false);

    $resp = $this->actingAsAdmin($this->admin)->getJson('/api/admin/database/backups');

    $resp->assertOk();
    $data = $resp->json('data');
    expect($data['total'])->toBe(2);

    $byId = collect($data['items'])->keyBy('id');
    expect($byId['backup_20260424_120000']['has_schema'])->toBeTrue()
        ->and($byId['backup_20260424_120000']['prefix'])->toBe('backup')
        ->and($byId['pre_restore_20260424_130000']['has_schema'])->toBeFalse()
        ->and($byId['pre_restore_20260424_130000']['prefix'])->toBe('pre_restore');
});

test('store 入队 CreateBackupJob 并返回 token', function () {
    Queue::fake();

    $resp = $this->actingAsAdmin($this->admin)->postJson('/api/admin/database/backups');

    $resp->assertOk();
    expect($resp->json('data.token'))->toBeString()
        ->and(strlen($resp->json('data.token')))->toBeGreaterThanOrEqual(32);

    Queue::assertPushed(CreateBackupJob::class);
});

test('未认证访问管理 API 返回 401', function () {
    $this->getJson('/api/admin/database/backups')->assertStatus(401);
});

test('恢复要求 mode 参数合法', function () {
    createFakeBackup($this->testDir, 'backup_20260424_120000');

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', ['mode' => 'nonsense'])
        ->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonStructure(['errors' => ['mode']]);
});

test('恢复入队 RestoreBackupJob 并返回 token', function () {
    Queue::fake();
    createFakeBackup($this->testDir, 'backup_20260424_120000');

    $resp = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', ['mode' => 'incremental']);

    $resp->assertOk();
    expect($resp->json('data.token'))->toBeString();
    Queue::assertPushed(RestoreBackupJob::class);
});

test('恢复不存在的备份返回错误', function () {
    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_19990101_000000/restore', ['mode' => 'full'])
        ->assertOk()
        ->assertJson(['code' => 0]); // error() 在 ApiResponse 里返回 code=0
});

test('删除移除 sql.gz 与 schema.json', function () {
    createFakeBackup($this->testDir, 'backup_20260424_120000');

    expect(is_file($this->testDir.'/backup_20260424_120000.sql.gz'))->toBeTrue()
        ->and(is_file($this->testDir.'/backup_20260424_120000.schema.json'))->toBeTrue();

    $resp = $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/database/backups/backup_20260424_120000');

    $resp->assertOk();
    expect($resp->json('data.deleted'))->toBe(2);
    expect(is_file($this->testDir.'/backup_20260424_120000.sql.gz'))->toBeFalse()
        ->and(is_file($this->testDir.'/backup_20260424_120000.schema.json'))->toBeFalse();
});

test('下载 token 签发后可一次性消费', function () {
    Cache::flush();
    createFakeBackup($this->testDir, 'backup_20260424_120000');

    $resp = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/download-token');

    $resp->assertOk();
    $token = $resp->json('data.token');
    expect($token)->toBeString()->and(strlen($token))->toBe(40);

    // 第一次下载成功
    $dl = $this->get('/api/admin/database/backups/download?token='.$token);
    $dl->assertOk();
    expect($dl->headers->get('Content-Disposition'))->toContain('backup_20260424_120000.sql.gz');

    // 第二次下载同 token 应 404（已核销）
    $this->get('/api/admin/database/backups/download?token='.$token)->assertNotFound();
});

test('非法/过期下载 token 返回 404', function () {
    Cache::flush();
    $this->get('/api/admin/database/backups/download?token=invalid_token')->assertNotFound();
    $this->get('/api/admin/database/backups/download?token='.str_repeat('a', 40))->assertNotFound();
});

test('jobStatus 返回进度信息', function () {
    Cache::flush();
    /** @var BackupService $svc */
    $svc = app(BackupService::class);
    $token = $svc->newJobToken();
    $svc->setJobProgress($token, ['status' => 'running', 'message' => 'dumping']);

    $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/database/jobs/'.$token)
        ->assertOk()
        ->assertJsonPath('data.progress.status', 'running')
        ->assertJsonPath('data.progress.message', 'dumping');
});

test('schemaDiff 无 schema 时返回 has_schema=false', function () {
    createFakeBackup($this->testDir, 'backup_20260424_120000', withSchema: false);

    $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/database/backups/backup_20260424_120000/schema-diff')
        ->assertOk()
        ->assertJsonPath('data.has_schema', false);
});

test('schemaDiff: schema 与当前一致时 has_diff=false 且返回表概览', function () {
    // 写一个与当前库结构 mock 完全一致的 schema.json
    $schema = [
        'tables' => [
            'users' => [
                'comment' => '用户表',
                'columns' => ['id' => [], 'name' => []],
                'indexes' => [],
            ],
        ],
        'generated_at' => '2026-04-24 12:00:00',
    ];
    $sql = $this->testDir.'/backup_20260424_120000.sql.gz';
    $gz = gzopen($sql, 'wb');
    gzwrite($gz, "-- fake\n");
    gzclose($gz);
    file_put_contents(
        $this->testDir.'/backup_20260424_120000.schema.json',
        json_encode($schema)
    );

    // mock DatabaseStructureService 返回相同结构 + 空差异
    $this->mock(DatabaseStructureService::class, function ($m) use ($schema) {
        $m->shouldReceive('exportCurrentStructure')->andReturn($schema);
        $m->shouldReceive('compareStructures')->andReturn([
            'missing_tables' => [],
            'extra_tables' => [],
            'table_differences' => [],
        ]);
    });

    // resolveIgnoreTables 不应触发真实 information_schema 查询，用 partial mock 屏蔽
    // 实际上这里用真实库也行，但我们替 service 的实例
    $svc = new class($this->testDir) extends BackupService
    {
        public function __construct(private string $dir) {}

        public function basePath(): string
        {
            return $this->dir;
        }

        public function resolveIgnoreTables(string $database): array
        {
            return [];
        }
    };
    $this->app->instance(BackupService::class, $svc);

    $resp = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/database/backups/backup_20260424_120000/schema-diff');

    $resp->assertOk()
        ->assertJsonPath('data.has_schema', true)
        ->assertJsonPath('data.has_diff', false);

    $overview = $resp->json('data.tables_overview');
    expect($overview)->toBeArray()
        ->and($overview[0]['name'])->toBe('users')
        ->and($overview[0]['comment'])->toBe('用户表')
        ->and($overview[0]['columns'])->toBe(2);
});

test('schemaDiff: schema 与当前不一致时 has_diff=true 含 missing/extra/modified', function () {
    $schema = ['tables' => ['old_table' => ['columns' => ['a' => []]]]];
    $sql = $this->testDir.'/backup_20260424_120000.sql.gz';
    $gz = gzopen($sql, 'wb');
    gzwrite($gz, "-- fake\n");
    gzclose($gz);
    file_put_contents(
        $this->testDir.'/backup_20260424_120000.schema.json',
        json_encode($schema)
    );

    $this->mock(DatabaseStructureService::class, function ($m) {
        $m->shouldReceive('exportCurrentStructure')->andReturn(['tables' => ['new_table' => []]]);
        $m->shouldReceive('compareStructures')->andReturn([
            'missing_tables' => ['old_table' => []],
            'extra_tables' => ['new_table' => []],
            'table_differences' => [
                'users' => [
                    'missing_columns' => ['email' => []],
                    'extra_columns' => [],
                    'modified_columns' => ['name' => []],
                    'missing_indexes' => [],
                    'extra_indexes' => [],
                ],
            ],
        ]);
    });

    $svc = new class($this->testDir) extends BackupService
    {
        public function __construct(private string $dir) {}

        public function basePath(): string
        {
            return $this->dir;
        }

        public function resolveIgnoreTables(string $database): array
        {
            return [];
        }
    };
    $this->app->instance(BackupService::class, $svc);

    $resp = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/database/backups/backup_20260424_120000/schema-diff');

    $resp->assertOk()
        ->assertJsonPath('data.has_schema', true)
        ->assertJsonPath('data.has_diff', true);

    $summary = $resp->json('data.summary');
    expect($summary['missing_tables'])->toEqual(['old_table'])
        ->and($summary['extra_tables'])->toEqual(['new_table'])
        ->and($summary['modified_tables']['users']['missing_columns'])->toEqual(['email'])
        ->and($summary['modified_tables']['users']['modified_columns'])->toEqual(['name']);
});

test('store 在 mysqldump 不可用时立即返回错误，不入队 Job', function () {
    Queue::fake();
    // delegate 后通过 mock BinaryLocator 模拟"找不到 mysqldump"（不再依赖 config 路径）
    // diagnose 参数模拟 BinaryLocator::diagnose() 含 "mysql-client" 的安装提示
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andThrow(new BinaryNotFoundException(
        tool: 'mysqldump',
        triedPaths: ['/nonexistent'],
        diagnose: ['推荐安装命令:', '  macOS: brew install mysql-client'],
    ));
    $this->app->instance(BinaryLocator::class, $mock);

    $resp = $this->actingAsAdmin($this->admin)->postJson('/api/admin/database/backups');

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('未找到 mysqldump 命令')
        ->and($resp->json('msg'))->not->toContain('mysql-client');
    expect($resp->json('errors'))->toBeArray()
        ->and(implode("\n", $resp->json('errors')))->toContain('mysql-client');

    Queue::assertNotPushed(CreateBackupJob::class);
});

test('restore 在 mysql 不可用时立即返回错误，不入队 Job', function () {
    Queue::fake();
    createFakeBackup($this->testDir, 'backup_20260424_120000');
    // Controller 顺序探测 mysqldump → mysql，mysqldump 在真实环境可能找到（mock 必须显式返回）
    // 让 mysqldump 返回任意路径以通过第一道探测，mysql 抛 BinaryNotFoundException 触发分支
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andReturn('/fake/mysqldump');
    $mock->shouldReceive('mysql')->andThrow(new BinaryNotFoundException(
        tool: 'mysql',
        triedPaths: ['/nonexistent'],
        diagnose: ['推荐安装命令:', '  macOS: brew install mysql-client'],
    ));
    $this->app->instance(BinaryLocator::class, $mock);

    $resp = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', ['mode' => 'full']);

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('未找到 mysql 命令')
        ->and($resp->json('msg'))->not->toContain('mysql-client');
    expect($resp->json('errors'))->toBeArray()
        ->and(implode("\n", $resp->json('errors')))->toContain('mysql-client');

    Queue::assertNotPushed(RestoreBackupJob::class);
});

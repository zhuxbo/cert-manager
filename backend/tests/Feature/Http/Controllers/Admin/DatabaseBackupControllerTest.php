<?php

use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Admin;
use App\Models\AdminLog;
use App\Services\Backup\BackupService;
use App\Services\Backup\Restore\RestorePreflight;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

function fakeMysqlToolchainBinary(string $tool): string
{
    $path = sys_get_temp_dir().'/fake_'.$tool.'_'.uniqid().'.sh';
    if ($tool === 'gzip') {
        $output = 'gzip 1.12';
    } else {
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        preg_match('/(\d+\.\d+)/', $version, $matches);
        $output = "$tool  Ver {$matches[1]}.99 for Linux on x86_64 (MySQL Community Server - GPL)";
    }
    file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($output)."\n");
    chmod($path, 0700);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
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

    // 检查器从 BinaryLocator 获取绝对路径，并在测试脚本上执行 --version。
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andReturn(fakeMysqlToolchainBinary('mysqldump'));
    $mock->shouldReceive('gzip')->andReturn(fakeMysqlToolchainBinary('gzip'));
    $this->app->instance(BinaryLocator::class, $mock);

    $resp = $this->actingAsAdmin($this->admin)->postJson('/api/admin/database/backups');

    $resp->assertOk();
    expect($resp->json('data.token'))->toBeString()
        ->and(strlen($resp->json('data.token')))->toBeGreaterThanOrEqual(32);

    Queue::assertPushed(CreateBackupJob::class);
});

test('未认证访问管理 API 返回 401', function () {
    $this->getJson('/api/admin/database/backups')->assertStatus(401);
});

test('恢复请求禁止旧 mode 且只接受布尔 Schema 确认', function () {
    createFakeBackup($this->testDir, 'backup_20260424_120000');

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', ['mode' => 'full'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mode', 'allow_schema_difference']);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', [
            'allow_schema_difference' => 'yes',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['allow_schema_difference']);
});

test('恢复入队 RestoreBackupJob 并返回 token', function () {
    expectsBreakingChange('atomic-restore-2026-08: restore body removes mode and adds allow_schema_difference');
    Queue::fake();
    createFakeBackup($this->testDir, 'backup_20260424_120000');
    $preflight = new Task11RestorePreflightFake;
    $this->app->instance(RestorePreflight::class, $preflight);

    $resp = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', [
            'allow_schema_difference' => false,
        ]);

    $resp->assertOk();
    expect($resp->json('data.token'))->toBeString();
    Queue::assertPushed(RestoreBackupJob::class, function (RestoreBackupJob $job): bool {
        return $job->backupId === 'backup_20260424_120000'
            && $job->allowSchemaDifference === false
            && $job->actor === 'admin:'.$this->admin->id;
    });
    expect($preflight->requests)->toHaveCount(1)
        ->and($preflight->requests[0]->allowSchemaDifference)->toBeFalse();
});

test('恢复不存在的备份返回错误', function () {
    expectsBreakingChange('atomic-restore-2026-08: restore body removes mode and adds allow_schema_difference');
    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_19990101_000000/restore', [
            'allow_schema_difference' => false,
        ])
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

test('jobStatus 无需管理员认证即可凭 token 返回脱敏进度', function () {
    Cache::flush();
    /** @var BackupService $svc */
    $svc = app(BackupService::class);
    $token = $svc->newJobToken();
    $svc->setJobProgress($token, [
        'status' => 'running',
        'stage' => 'import',
        'message' => 'password is database-secret',
        'error' => 'SQLSTATE[42000]: GRANT ALL ON *.* TO attacker',
        'admin_id' => $this->admin->id,
        'sql' => 'GRANT ALL ON *.* TO attacker',
        'password' => 'database-secret',
        'dump_path' => '/var/lib/mysql/backup.sql.gz',
        'output' => 'mysql --password=database-secret < /tmp/backup.sql',
        'mode' => 'full',
        'progress' => 45,
    ]);

    expect(AdminLog::count())->toBe(0);
    $response = $this->getJson('/api/admin/database/jobs/'.$token)
        ->assertOk()
        ->assertJsonPath('data.progress.status', 'running')
        ->assertJsonPath('data.progress.stage', 'import')
        ->assertJsonPath('data.progress.message', '正在导入备份')
        ->assertJsonPath('data.progress.progress', 45);

    expect($response->getContent())
        ->not->toContain('GRANT ALL')
        ->not->toContain('SQLSTATE')
        ->not->toContain('password is')
        ->not->toContain('database-secret')
        ->not->toContain('/var/lib/mysql')
        ->not->toContain('/tmp/backup.sql');
    expect($response->json('data.progress'))->not->toHaveKeys([
        'admin_id',
        'error',
        'sql',
        'password',
        'dump_path',
        'output',
        'mode',
    ]);
    expect(AdminLog::count())->toBe(0);
});

test('jobStatus 只保留原子恢复的固定阶段', function () {
    /** @var BackupService $svc */
    $svc = app(BackupService::class);
    $stages = [
        'preflight',
        'freeze',
        'create_shadow',
        'import',
        'prepare_structure',
        'validate',
        'wait_metadata_lock',
        'cutover',
        'runtime_cleanup',
        'complete',
    ];

    foreach ($stages as $stage) {
        $token = $svc->newJobToken();
        $svc->setJobProgress($token, ['status' => 'running', 'stage' => $stage]);
        $this->getJson('/api/admin/database/jobs/'.$token)
            ->assertOk()
            ->assertJsonPath('data.progress.stage', $stage);
    }
});

test('jobStatus 对未知状态码和越界数值 fail closed', function () {
    /** @var BackupService $svc */
    $svc = app(BackupService::class);
    $token = $svc->newJobToken();
    $svc->setJobProgress($token, [
        'status' => '/var/lib/mysql',
        'stage' => 'GRANT ALL',
        'message' => 'password is leaked',
        'progress' => 101,
    ]);

    $response = $this->getJson('/api/admin/database/jobs/'.$token)
        ->assertOk()
        ->assertJsonPath('data.progress.status', 'unknown')
        ->assertJsonPath('data.progress.message', '任务状态暂不可用');

    expect($response->json('data.progress'))->not->toHaveKeys([
        'stage',
        'progress',
    ]);
    expect($response->getContent())
        ->not->toContain('/var/lib/mysql')
        ->not->toContain('GRANT ALL')
        ->not->toContain('password is leaked');
});

test('jobStatus 在非冻结时显式拒绝 HEAD', function () {
    /** @var BackupService $svc */
    $svc = app(BackupService::class);
    $token = $svc->newJobToken();
    $svc->setJobProgress($token, ['status' => 'running', 'stage' => 'import']);

    $this->call('HEAD', '/api/admin/database/jobs/'.$token)->assertStatus(405);
});

test('jobStatus 任意 method 都不会把 bearer token 写入 AdminLog', function () {
    /** @var BackupService $svc */
    $svc = app(BackupService::class);
    $token = $svc->newJobToken();
    $svc->setJobProgress($token, ['status' => 'running', 'stage' => 'import']);
    $path = '/api/admin/database/jobs/'.$token;

    expect(AdminLog::count())->toBe(0);
    foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        $response = $this->call($method, $path);
        if ($method === 'GET') {
            $response->assertOk();
        } elseif ($method !== 'OPTIONS') {
            $response->assertStatus(405);
        }
    }

    expect(AdminLog::count())->toBe(0);
});

test('jobStatus 未知 token 返回 404', function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::createFromTimestamp(1800000020));

    try {
        $this->getJson('/api/admin/database/jobs/'.str_repeat('c', 32))->assertNotFound();

        for ($i = 0; $i < 119; $i++) {
            $token = str_pad(base_convert((string) $i, 10, 36), 32, 'a');
            $this->getJson('/api/admin/database/jobs/'.$token)->assertNotFound();
        }

        $this->getJson('/api/admin/database/jobs/'.str_repeat('z', 32))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('errors.error_code', 'rate_limited')
            ->assertJsonPath('errors.retry_after', 100);
    } finally {
        Carbon::setTestNow();
        Cache::flush();
    }
});

test('恢复预检只返回公开事实且不泄露内部上下文路径密码或 SQL', function () {
    $preflight = new Task11RestorePreflightFake;
    $this->app->instance(RestorePreflight::class, $preflight);

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/database/backups/backup_20260424_120000/restore-preflight');

    $response->assertOk()
        ->assertJsonPath('data.runnable', true)
        ->assertJsonPath('data.artifact.integrity.verified', true)
        ->assertJsonPath('data.versions.backup_application.version', '1.2.3')
        ->assertJsonPath('data.versions.current_application.version', '2.0.0')
        ->assertJsonPath('data.schema.diff.has_difference', false)
        ->assertJsonMissingPath('data.context');
    expect($response->getContent())
        ->not->toContain('/var/lib/mysql/private.sql.gz')
        ->not->toContain('database-secret')
        ->not->toContain('INSERT INTO');
});

test('Schema 差异未确认时恢复返回 422 且不入队', function () {
    Queue::fake();
    createFakeBackup($this->testDir, 'backup_20260424_120000');
    $preflight = new Task11RestorePreflightFake;
    $preflight->confirmations = [[
        'code' => 'schema_difference',
        'message' => '备份 Schema 与当前数据库结构存在语义差异。',
    ]];
    $preflight->schemaDifference = true;
    $this->app->instance(RestorePreflight::class, $preflight);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', [
            'allow_schema_difference' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('data.schema.diff.has_difference', true)
        ->assertJsonPath('data.confirmations.0.code', 'schema_difference');

    Queue::assertNotPushed(RestoreBackupJob::class);
});

test('Schema 差异明确确认后允许入队', function () {
    Queue::fake();
    createFakeBackup($this->testDir, 'backup_20260424_120000');
    $preflight = new Task11RestorePreflightFake;
    $preflight->confirmations = [[
        'code' => 'schema_difference',
        'message' => '备份 Schema 与当前数据库结构存在语义差异。',
    ]];
    $preflight->schemaDifference = true;
    $this->app->instance(RestorePreflight::class, $preflight);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', [
            'allow_schema_difference' => true,
        ])
        ->assertOk();

    Queue::assertPushed(RestoreBackupJob::class, fn (RestoreBackupJob $job): bool => $job->allowSchemaDifference);
});

test('硬阻断即使确认 Schema 差异也返回 422 且不入队', function () {
    Queue::fake();
    createFakeBackup($this->testDir, 'backup_20260424_120000');
    $preflight = new Task11RestorePreflightFake;
    $preflight->hardBlockers = [[
        'code' => 'toolchain_unsupported',
        'message' => 'MySQL 客户端与服务端系列不一致。',
        'facts' => [],
    ]];
    $this->app->instance(RestorePreflight::class, $preflight);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/database/backups/backup_20260424_120000/restore', [
            'allow_schema_difference' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('data.hard_blockers.0.code', 'toolchain_unsupported');

    Queue::assertNotPushed(RestoreBackupJob::class);
});

test('store 在工具链不受支持时立即返回错误，不入队 Job', function () {
    Queue::fake();
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andThrow(new BinaryNotFoundException(
        tool: 'mysqldump',
        triedPaths: ['/nonexistent'],
    ));
    $mock->shouldReceive('gzip')->andReturn(fakeMysqlToolchainBinary('gzip'));
    $this->app->instance(BinaryLocator::class, $mock);

    $resp = $this->actingAsAdmin($this->admin)->postJson('/api/admin/database/backups');

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('MySQL 工具链不受支持')
        ->and($resp->json('msg'))->not->toContain('mysql-client');
    expect($resp->json('errors'))->toBeArray()
        ->and(implode("\n", $resp->json('errors')))->toContain('/www/server/mysql/bin/mysqldump')
        ->toContain('apt-get install -y mysql-client')
        ->toContain('dnf install -y mysql-community-client')
        ->not->toContain('/nonexistent');

    Queue::assertNotPushed(CreateBackupJob::class);
});

final class Task11RestorePreflightFake
{
    /** @var list<RestoreRequest> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $hardBlockers = [];

    /** @var list<array<string, mixed>> */
    public array $confirmations = [];

    public bool $schemaDifference = false;

    /** @return array<string, mixed> */
    public function inspect(RestoreRequest $request): array
    {
        $this->requests[] = $request;
        $runnable = $this->hardBlockers === []
            && ($this->confirmations === [] || $request->allowSchemaDifference);

        return [
            'runnable' => $runnable,
            'hard_blockers' => $this->hardBlockers,
            'confirmations' => $this->confirmations,
            'warnings' => [['code' => 'fixture_warning', 'message' => '测试告警']],
            'artifact' => [
                'id' => $request->backupId,
                'legacy' => false,
                'sql' => 'backup_20260424_120000.sql.gz',
                'schema' => 'backup_20260424_120000.schema.json',
                'integrity' => [
                    'verified' => true,
                    'compressed_bytes' => 1024,
                    'sha256' => str_repeat('a', 64),
                    'gzip_eof' => true,
                ],
            ],
            'toolchain' => [
                'supported' => $this->hardBlockers === [],
                'errors' => [],
                'warnings' => [],
                'server' => ['vendor' => 'mysql', 'version' => '8.4.6', 'series' => '8.4'],
                'mysql' => ['vendor' => 'mysql', 'version' => '8.4.6', 'series' => '8.4'],
                'gzip' => ['version' => '1.13'],
            ],
            'versions' => [
                'backup_application' => ['version' => '1.2.3', 'channel' => 'main'],
                'current_application' => ['version' => '2.0.0', 'channel' => 'main'],
                'backup_toolchain' => ['server_version' => '8.0.40', 'client_version' => '8.0.40'],
                'current_server' => ['vendor' => 'mysql', 'version' => '8.4.6', 'series' => '8.4'],
                'current_mysql_client' => ['vendor' => 'mysql', 'version' => '8.4.6', 'series' => '8.4'],
            ],
            'schema' => [
                'authoritative' => true,
                'diff' => [
                    'has_difference' => $this->schemaDifference,
                    'missing_tables' => $this->schemaDifference ? ['orders'] : [],
                    'extra_tables' => [],
                    'changed_tables' => [],
                ],
            ],
            'space' => [
                'backup_data_and_indexes_bytes' => 2048,
                'current_tables_retained_bytes' => 4096,
                'streaming_temp_bytes' => 0,
                'total_estimated_footprint_bytes' => 6144,
                'available_bytes' => null,
                'verified' => false,
                'note' => '未测量远程 MySQL 主机的可用磁盘空间。',
            ],
            'state' => ['state' => 'clean'],
            'context' => [
                'dump_path' => '/var/lib/mysql/private.sql.gz',
                'password' => 'database-secret',
                'sql' => 'INSERT INTO admins VALUES (1)',
            ],
        ];
    }
}

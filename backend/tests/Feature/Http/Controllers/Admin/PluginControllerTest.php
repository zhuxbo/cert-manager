<?php

use App\Jobs\PluginOperationJob;
use App\Models\Admin;
use App\Models\PluginOperation;
use App\Services\Plugin\PluginManager;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    Queue::fake();
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/plugin-controller-*.zip') ?: [] as $file) {
        @unlink($file);
    }

    File::deleteDirectory(storage_path('app/plugin-operations'));
});

function pluginControllerZipUpload(string $name = 'zip-plugin', string $version = '1.0.0'): UploadedFile
{
    $zipPath = sys_get_temp_dir().'/plugin-controller-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString("$name/plugin.json", json_encode([
        'name' => $name,
        'version' => $version,
    ]));
    $zip->close();

    return new UploadedFile($zipPath, "$name.zip", 'application/zip', null, true);
}

test('管理员可以获取已安装插件列表', function () {
    $plugins = [
        ['name' => 'test-plugin', 'version' => '1.0.0'],
    ];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('getInstalledPlugins')->once()->andReturn($plugins);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/plugin/installed');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['plugins']]);
    expect($response->json('data.plugins'))->toBe($plugins);
});

test('管理员可以检查插件更新', function () {
    $updates = [
        ['name' => 'test-plugin', 'latest' => '1.1.0'],
    ];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('checkUpdates')->once()->andReturn($updates);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/plugin/check-updates');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['updates']]);
    expect($response->json('data.updates'))->toBe($updates);
});

test('管理员可以远程安装插件', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.operation.plugin_name'))->toBe('test-plugin')
        ->and($response->json('data.operation.type'))->toBe(PluginOperation::TYPE_INSTALL_REMOTE)
        ->and($response->json('data.operation.status'))->toBe(PluginOperation::STATUS_QUEUED)
        ->and($response->json('data.operation.upload_path'))->toBeNull();

    $uuid = $response->json('data.operation.uuid');
    expect(PluginOperation::where('uuid', $uuid)->exists())->toBeTrue();
    Queue::assertPushed(PluginOperationJob::class, fn (PluginOperationJob $job) => $job->operationUuid === $uuid
        && $job->queue === config('queue.names.tasks'));
});

test('安装插件未指定名称和文件返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以更新插件', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/update', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.operation.plugin_name'))->toBe('test-plugin')
        ->and($response->json('data.operation.type'))->toBe(PluginOperation::TYPE_UPDATE)
        ->and($response->json('data.operation.status'))->toBe(PluginOperation::STATUS_QUEUED);

    $uuid = $response->json('data.operation.uuid');
    Queue::assertPushed(PluginOperationJob::class, fn (PluginOperationJob $job) => $job->operationUuid === $uuid
        && $job->queue === config('queue.names.tasks'));
});

test('更新插件未指定名称返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/update', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以卸载插件', function () {
    $result = ['name' => 'test-plugin'];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('uninstall')
        ->with('test-plugin', false)
        ->once()
        ->andReturn($result);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/uninstall', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toBe($result);
});

test('卸载插件未指定名称返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/uninstall', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以卸载插件并删除数据', function () {
    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('uninstall')->with('test-plugin', true)->once()->andReturn(['name' => 'test-plugin']);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/uninstall', [
        'name' => 'test-plugin',
        'remove_data' => true,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以按版本安装插件', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', [
        'name' => 'test-plugin',
        'release_url' => 'https://example.com/release',
        'version' => '2.0.0',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.operation.plugin_name'))->toBe('test-plugin')
        ->and($response->json('data.operation.version'))->toBe('2.0.0')
        ->and($response->json('data.operation.status'))->toBe(PluginOperation::STATUS_QUEUED);

    $operation = PluginOperation::where('uuid', $response->json('data.operation.uuid'))->first();
    expect($operation)->not->toBeNull()
        ->and($operation->release_url)->toBe('https://example.com/release');
});

test('管理员可以上传 ZIP 安装插件', function () {
    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/plugin/install', [
        'file' => pluginControllerZipUpload(),
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.operation.plugin_name'))->toBe('zip-plugin')
        ->and($response->json('data.operation.version'))->toBe('1.0.0')
        ->and($response->json('data.operation.type'))->toBe(PluginOperation::TYPE_INSTALL_UPLOAD)
        ->and($response->json('data.operation.upload_path'))->toBeNull();

    $operation = PluginOperation::where('uuid', $response->json('data.operation.uuid'))->first();
    expect($operation)->not->toBeNull()
        ->and($operation->upload_path)->not->toBeNull()
        ->and(is_file(storage_path("app/$operation->upload_path")))->toBeTrue();

    Queue::assertPushed(PluginOperationJob::class, fn (PluginOperationJob $job) => $job->operationUuid === $operation->uuid
        && $job->queue === config('queue.names.tasks'));
});

test('上传非 ZIP 文件安装插件返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/plugin/install', [
        'file' => UploadedFile::fake()->create('zip-plugin.txt', 10, 'text/plain'),
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('存在活动插件任务时拒绝重复安装更新和卸载', function () {
    PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_UPDATE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_QUEUED,
        'stage' => PluginOperation::STAGE_QUEUED,
        'message' => 'queued',
        'admin_id' => $this->admin->id,
    ]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/plugin/install', ['name' => 'test-plugin'])
        ->assertOk()
        ->assertJson(['code' => 0]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/plugin/update', ['name' => 'test-plugin'])
        ->assertOk()
        ->assertJson(['code' => 0]);

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldNotReceive('uninstall');
    $this->app->instance(PluginManager::class, $mock);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/plugin/uninstall', ['name' => 'test-plugin'])
        ->assertOk()
        ->assertJson(['code' => 0]);

    Queue::assertNothingPushed();
});

test('存在失败插件任务时拒绝普通安装并提示重试或卸载', function () {
    PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 依赖安装失败',
        'error' => '插件 cloud-deploy 依赖安装失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', [
        'name' => 'cloud-deploy',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('msg'))->toContain('重试或卸载');

    expect(PluginOperation::where('plugin_name', 'cloud-deploy')->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('管理员可以重试失败的远程插件安装任务', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'cloud-deploy',
        'version' => '0.1.0',
        'release_url' => 'https://example.com/plugins/cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 依赖安装失败',
        'error' => '插件 cloud-deploy 依赖安装失败',
        'admin_id' => $this->admin->id,
        'attempts' => 1,
        'run_token' => 'old-token',
        'last_heartbeat_at' => now()->subMinute(),
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$operation->uuid/retry");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.operation.uuid'))->toBe($operation->uuid)
        ->and($response->json('data.operation.status'))->toBe(PluginOperation::STATUS_QUEUED);

    $operation->refresh();
    expect($operation->status)->toBe(PluginOperation::STATUS_QUEUED)
        ->and($operation->stage)->toBe(PluginOperation::STAGE_QUEUED)
        ->and($operation->error)->toBeNull()
        ->and($operation->run_token)->toBeNull()
        ->and($operation->finished_at)->toBeNull()
        ->and(PluginOperation::where('plugin_name', 'cloud-deploy')->count())->toBe(1);

    Queue::assertPushed(PluginOperationJob::class, fn (PluginOperationJob $job) => $job->operationUuid === $operation->uuid
        && $job->queue === config('queue.names.tasks'));
});

test('管理员可以卸载失败的安装任务并清理同插件失败记录', function () {
    $first = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 依赖安装失败',
        'error' => '插件 cloud-deploy 依赖安装失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '该插件要求系统版本 >=0.6.3',
        'error' => '该插件要求系统版本 >=0.6.3',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$first->uuid/uninstall");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.name'))->toBe('cloud-deploy')
        ->and($response->json('data.message'))->toContain('已清理');

    expect(PluginOperation::where('plugin_name', 'cloud-deploy')->exists())->toBeFalse();
});

test('清理失败安装记录时拒绝更新任务并保留更新失败记录', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_UPDATE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 更新失败',
        'error' => '插件 cloud-deploy 更新失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$operation->uuid/uninstall");

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('msg'))->toContain('更新失败任务只能重试')
        ->and(PluginOperation::whereKey($operation->id)->exists())->toBeTrue();
});

test('管理员可以取消失败的更新任务并保留当前插件版本', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_UPDATE,
        'plugin_name' => 'cloud-deploy',
        'version' => '0.0.6',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 更新失败',
        'error' => '插件 cloud-deploy 更新失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldNotReceive('uninstall');
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$operation->uuid/cancel");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.name'))->toBe('cloud-deploy')
        ->and($response->json('data.message'))->toContain('保留当前版本')
        ->and(PluginOperation::whereKey($operation->id)->exists())->toBeFalse();
});

test('取消失败更新任务时拒绝安装任务并保留记录', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 安装失败',
        'error' => '插件 cloud-deploy 安装失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$operation->uuid/cancel");

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('msg'))->toContain('只能取消失败的更新任务')
        ->and(PluginOperation::whereKey($operation->id)->exists())->toBeTrue();
});

test('清理失败安装记录不会删除同插件更新失败记录', function () {
    $install = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 依赖安装失败',
        'error' => '插件 cloud-deploy 依赖安装失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $update = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_UPDATE,
        'plugin_name' => 'cloud-deploy',
        'status' => PluginOperation::STATUS_FAILED,
        'stage' => PluginOperation::STAGE_ERROR,
        'message' => '插件 cloud-deploy 更新失败',
        'error' => '插件 cloud-deploy 更新失败',
        'admin_id' => $this->admin->id,
        'finished_at' => now(),
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$install->uuid/uninstall");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(PluginOperation::whereKey($install->id)->exists())->toBeFalse()
        ->and(PluginOperation::whereKey($update->id)->exists())->toBeTrue();
});

test('管理员可以查看插件任务并标记 stale running 为失败', function () {
    config(['plugin.operations.stale_after' => 60]);

    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_RUNNING,
        'stage' => 'composer_install',
        'message' => 'composer install',
        'admin_id' => $this->admin->id,
        'run_token' => 'token-a',
        'last_heartbeat_at' => now()->subSeconds(61),
        'started_at' => now()->subMinutes(2),
    ]);

    $list = $this->actingAsAdmin($this->admin)->getJson('/api/admin/plugin/operations');
    $list->assertOk()->assertJson(['code' => 1]);
    expect($list->json('data.operations.0.uuid'))->toBe($operation->uuid)
        ->and($list->json('data.operations.0.is_stale'))->toBeTrue();

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/plugin/operations/$operation->uuid/fail-stale");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.operation.status'))->toBe(PluginOperation::STATUS_FAILED)
        ->and($operation->fresh()->status)->toBe(PluginOperation::STATUS_FAILED);
});

test('插件任务入队失败时 operation 立即标记失败', function () {
    $this->app->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function dispatch($command)
        {
            throw new RuntimeException('queue backend down');
        }

        public function dispatchSync($command, $handler = null)
        {
            throw new RuntimeException('not used');
        }

        public function dispatchNow($command, $handler = null)
        {
            throw new RuntimeException('not used');
        }

        public function dispatchAfterResponse($command, $handler = null): void
        {
            throw new RuntimeException('not used');
        }

        public function chain($jobs = null)
        {
            return $this;
        }

        public function hasCommandHandler($command): bool
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return null;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    });

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    $operation = PluginOperation::where('plugin_name', 'test-plugin')->first();
    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(PluginOperation::STATUS_FAILED)
        ->and($operation->error)->toContain('queue backend down');
});

test('未认证用户无法访问插件管理', function () {
    $response = $this->getJson('/api/admin/plugin/installed');

    $response->assertUnauthorized();
});

<?php

use App\Jobs\PluginOperationJob;
use App\Models\PluginOperation;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('PluginOperationJob 执行远程安装并写入成功终态', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_QUEUED,
        'stage' => PluginOperation::STAGE_QUEUED,
        'message' => 'queued',
    ]);

    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('withProgressReporter')
        ->once()
        ->with(Mockery::on(fn ($reporter) => is_callable($reporter)))
        ->andReturnUsing(function (callable $reporter) use ($manager) {
            $reporter('download', '下载插件包');

            return $manager;
        });
    $manager->shouldReceive('install')
        ->once()
        ->with('test-plugin', null, null)
        ->andReturn(['name' => 'test-plugin', 'version' => '1.0.0', 'secret' => 'hidden']);

    (new PluginOperationJob($operation->uuid))->handle(app(PluginOperationService::class), $manager);

    $operation->refresh();
    expect($operation->status)->toBe(PluginOperation::STATUS_SUCCEEDED)
        ->and($operation->stage)->toBe('done')
        ->and($operation->attempts)->toBe(1)
        ->and($operation->result)->toBe(['name' => 'test-plugin', 'version' => '1.0.0'])
        ->and($operation->run_token)->not->toBeNull()
        ->and($operation->last_heartbeat_at)->not->toBeNull();
});

test('PluginOperationJob 安装异常时写入失败终态并脱敏错误', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_QUEUED,
        'stage' => PluginOperation::STAGE_QUEUED,
        'message' => 'queued',
    ]);

    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('withProgressReporter')->once()->andReturnSelf();
    $manager->shouldReceive('install')
        ->once()
        ->andThrow(new RuntimeException('/tmp/plugin/source.zip?token=secret-token 安装失败'));

    (new PluginOperationJob($operation->uuid))->handle(app(PluginOperationService::class), $manager);

    $operation->refresh();
    expect($operation->status)->toBe(PluginOperation::STATUS_FAILED)
        ->and($operation->stage)->toBe(PluginOperation::STAGE_ERROR)
        ->and($operation->error)->toContain('[path]')
        ->and($operation->error)->not->toContain('secret-token');
});

test('PluginOperationJob 未进入 running 前失败时写入失败终态', function () {
    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_UPDATE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_QUEUED,
        'stage' => PluginOperation::STAGE_QUEUED,
        'message' => 'queued',
    ]);

    (new PluginOperationJob($operation->uuid))->failed(new RuntimeException('queue failed'));

    $operation->refresh();
    expect($operation->status)->toBe(PluginOperation::STATUS_FAILED)
        ->and($operation->run_token)->toBeNull()
        ->and($operation->error)->toContain('queue failed');
});

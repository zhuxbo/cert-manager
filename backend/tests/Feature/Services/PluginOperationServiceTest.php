<?php

use App\Models\PluginOperation;
use App\Services\Plugin\PluginOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    File::deleteDirectory(storage_path('app/plugin-operations'));
});

test('failStale 只允许超时 running 任务并清理上传包', function () {
    config(['plugin.operations.stale_after' => 60]);

    $uuid = (string) Str::uuid();
    $uploadPath = "plugin-operations/$uuid/source.zip";
    File::makeDirectory(dirname(storage_path("app/$uploadPath")), 0755, true);
    file_put_contents(storage_path("app/$uploadPath"), 'zip-bytes');

    $operation = PluginOperation::create([
        'uuid' => $uuid,
        'type' => PluginOperation::TYPE_INSTALL_UPLOAD,
        'plugin_name' => 'zip-plugin',
        'status' => PluginOperation::STATUS_RUNNING,
        'stage' => 'composer_install',
        'message' => 'composer install',
        'upload_path' => $uploadPath,
        'run_token' => 'run-a',
        'last_heartbeat_at' => now()->subSeconds(61),
    ]);

    $failed = app(PluginOperationService::class)->failStale($operation->uuid);

    expect($failed->status)->toBe(PluginOperation::STATUS_FAILED)
        ->and(is_file(storage_path("app/$uploadPath")))->toBeFalse();
});

test('failStale 拒绝未超时 running 任务', function () {
    config(['plugin.operations.stale_after' => 60]);

    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_INSTALL_REMOTE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_RUNNING,
        'stage' => 'download',
        'message' => 'download',
        'run_token' => 'run-a',
        'last_heartbeat_at' => now(),
    ]);

    expect(fn () => app(PluginOperationService::class)->failStale($operation->uuid))
        ->toThrow(RuntimeException::class, '尚未超时');

    expect($operation->fresh()->status)->toBe(PluginOperation::STATUS_RUNNING);
});

test('failStale 允许恢复超时 queued 任务', function () {
    config(['plugin.operations.stale_after' => 60]);

    $operation = PluginOperation::create([
        'uuid' => (string) Str::uuid(),
        'type' => PluginOperation::TYPE_UPDATE,
        'plugin_name' => 'test-plugin',
        'status' => PluginOperation::STATUS_QUEUED,
        'stage' => PluginOperation::STAGE_QUEUED,
        'message' => 'queued',
    ]);
    $operation->forceFill(['updated_at' => now()->subSeconds(61)])->save();

    $failed = app(PluginOperationService::class)->failStale($operation->uuid);

    expect($failed->status)->toBe(PluginOperation::STATUS_FAILED)
        ->and($failed->run_token)->toBeNull();
});

test('assertConfigSafe 将 migrate seed rollback 三段 artisan 预算计入 operation timeout', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 600);
    config()->set('plugin.download.timeout', 30);
    config()->set('plugin.composer.timeout', 210);
    config()->set('plugin.operations.overhead_margin', 30);
    config()->set('plugin.operations.artisan_timeout', 15);
    config()->set('plugin.operations.timeout', 270);

    expect(fn () => app(PluginOperationService::class)->assertConfigSafe())
        ->toThrow(RuntimeException::class, 'timeout 预算不足');

    config()->set('plugin.operations.timeout', 315);

    app(PluginOperationService::class)->assertConfigSafe();
    expect(true)->toBeTrue();
});

test('sanitizeError 遮蔽常见 composer 凭据形态', function () {
    $message = 'Authorization: Bearer bearer-secret COMPOSER_AUTH={"github-oauth":{"github.com":"ghp-secret"},"http-basic":{"repo.example":{"username":"deploy","password":"basic-secret"}}} https://user:pass-secret@example.com/pkg.zip?token=query-secret {"password":"json-secret","secret":"json-hidden"} GITHUB_TOKEN=env-secret /tmp/source.zip';

    $sanitized = app(PluginOperationService::class)->sanitizeError($message);

    expect($sanitized)->not->toContain('bearer-secret')
        ->and($sanitized)->not->toContain('ghp-secret')
        ->and($sanitized)->not->toContain('basic-secret')
        ->and($sanitized)->not->toContain('pass-secret')
        ->and($sanitized)->not->toContain('query-secret')
        ->and($sanitized)->not->toContain('json-secret')
        ->and($sanitized)->not->toContain('json-hidden')
        ->and($sanitized)->not->toContain('env-secret')
        ->and($sanitized)->toContain('******');
});

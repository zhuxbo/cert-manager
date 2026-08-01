<?php

use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Seeders\PluginSeeder;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('Cloud Deploy 插件 Seeder 幂等补齐通知模板、保留定制并支持清理', function () {
    $seeder = app(PluginSeeder::class);

    $seeder->run();
    $seeder->run();

    $query = NotificationTemplate::where('code', 'cloud_deploy_failed');
    expect($query->count())->toBe(1);

    $template = $query->firstOrFail();
    expect($template->name)->toBe('云部署失败')
        ->and($template->variables)->toBe([
            'product',
            'domain',
            'access_name',
            'error_code',
        ])
        ->and($template->content)->toContain('{{ $product }}');

    $template->update(['content' => '管理员自定义内容', 'status' => 0]);
    $seeder->run();

    expect($template->fresh()->content)->toBe('管理员自定义内容')
        ->and($template->fresh()->status)->toBe(0);

    $seeder->clear();
    expect($query->count())->toBe(0);
});

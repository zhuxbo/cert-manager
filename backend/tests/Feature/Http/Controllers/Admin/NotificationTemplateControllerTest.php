<?php

use App\Models\Notification;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Seeders\NotificationTemplateSeeder as CloudDeployNotificationTemplateSeeder;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

// index() 用 (int) $request->input('pageSize', 10) 兜底默认值：客户端显式传 null 时
// input() 返回 null（key 存在），(int) null = 0 → limit(0) 静默返回空列表。
// 正确写法应为 (int) ($request->input('pageSize') ?? 10)，显式 null 回落默认值。
test('管理员通知模板列表-pageSize 显式 null 回落默认值，不静默返回空列表', function () {
    foreach (range(1, 3) as $index) {
        NotificationTemplate::factory()->create([
            'code' => "page-size-null-$index",
        ]);
    }

    $response = $this->actingAsAdmin()
        ->json('GET', '/api/admin/notification-template', [
            'pageSize' => null,
            'code' => 'page-size-null-',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.pageSize'))->toBe(10);
    expect($response->json('data.total'))->toBe(3);
    expect($response->json('data.items'))->toHaveCount(3);
});

test('管理员通知模板列表-currentPage 显式 null 回落默认值', function () {
    foreach (range(1, 3) as $index) {
        NotificationTemplate::factory()->create([
            'code' => "current-page-null-$index",
        ]);
    }

    $response = $this->actingAsAdmin()
        ->json('GET', '/api/admin/notification-template', [
            'currentPage' => null,
            'code' => 'current-page-null-',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.currentPage'))->toBe(1);
    expect($response->json('data.total'))->toBe(3);
    expect($response->json('data.items'))->toHaveCount(3);
});

test('管理员可以同时重置主系统与插件 Seeder 管理的选中模板', function () {
    app(NotificationTemplateSeeder::class)->run();
    app(CloudDeployNotificationTemplateSeeder::class)->run();

    $systemTemplate = NotificationTemplate::where('code', 'security')->firstOrFail();
    $pluginTemplate = NotificationTemplate::where('code', 'cloud_deploy_failed')->firstOrFail();
    $systemTemplate->update([
        'name' => '自定义安全通知',
        'content' => '自定义系统模板',
        'status' => 0,
    ]);
    $pluginTemplate->update([
        'name' => '自定义云部署通知',
        'content' => '自定义插件模板',
        'status' => 0,
    ]);
    $notification = Notification::factory()->create(['template_id' => $systemTemplate->id]);
    $oldIds = [$systemTemplate->id, $pluginTemplate->id];
    $autoIncrementBefore = (int) DB::selectOne(
        "SHOW TABLE STATUS LIKE 'notification_templates'"
    )->Auto_increment;

    $this->actingAsAdmin()
        ->json('POST', '/api/admin/notification-template/reset', ['ids' => $oldIds])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $restoredSystem = NotificationTemplate::where('code', 'security')->firstOrFail();
    $restoredPlugin = NotificationTemplate::where('code', 'cloud_deploy_failed')->firstOrFail();
    $autoIncrementAfter = (int) DB::selectOne(
        "SHOW TABLE STATUS LIKE 'notification_templates'"
    )->Auto_increment;

    expect($restoredSystem->id)->toBe($systemTemplate->id)
        ->and($restoredSystem->name)->toBe('安全通知')
        ->and($restoredSystem->content)->toContain('您的账号发生安全变更')
        ->and($restoredSystem->status)->toBe(1)
        ->and($restoredPlugin->id)->toBe($pluginTemplate->id)
        ->and($restoredPlugin->name)->toBe('云部署失败')
        ->and($restoredPlugin->content)->toContain('证书已签发但推送到云平台失败')
        ->and($restoredPlugin->status)->toBe(1)
        ->and($notification->fresh()->template_id)->toBe($systemTemplate->id)
        ->and(NotificationTemplate::whereIn('id', $oldIds)->count())->toBe(2)
        ->and($autoIncrementAfter)->toBe($autoIncrementBefore);
});

test('选中非 Seeder 模板时整批重置回滚且保留原模板', function () {
    app(NotificationTemplateSeeder::class)->run();

    $systemTemplate = NotificationTemplate::where('code', 'security')->firstOrFail();
    $systemTemplate->update(['content' => '保留的系统自定义内容']);
    $customTemplate = NotificationTemplate::factory()->create([
        'code' => 'custom_reset_rejected',
        'content' => '保留的自定义模板内容',
    ]);
    $otherCustomTemplate = NotificationTemplate::factory()->create([
        'code' => 'other_custom_reset_rejected',
        'content' => '保留的另一个自定义模板内容',
    ]);
    $countBefore = NotificationTemplate::count();
    $unmanagedIds = [$customTemplate->id, $otherCustomTemplate->id];

    $this->actingAsAdmin()
        ->json('POST', '/api/admin/notification-template/reset', [
            'ids' => [$otherCustomTemplate->id, $systemTemplate->id, $customTemplate->id],
        ])
        ->assertOk()
        ->assertJson([
            'code' => 0,
            'errors' => [
                'ids' => [
                    '无法重置的模板 ID：'.implode('、', $unmanagedIds)
                    .'。这些模板不是系统或已安装插件管理的模板',
                ],
            ],
        ]);

    expect(NotificationTemplate::count())->toBe($countBefore)
        ->and($systemTemplate->fresh()->code)->toBe('security')
        ->and($systemTemplate->fresh()->content)->toBe('保留的系统自定义内容')
        ->and($customTemplate->fresh()->code)->toBe('custom_reset_rejected')
        ->and($customTemplate->fresh()->content)->toBe('保留的自定义模板内容')
        ->and($otherCustomTemplate->fresh()->code)->toBe('other_custom_reset_rejected')
        ->and($otherCustomTemplate->fresh()->content)->toBe('保留的另一个自定义模板内容');
});

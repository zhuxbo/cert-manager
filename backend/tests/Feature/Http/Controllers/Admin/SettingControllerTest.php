<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取所有设置', function () {
    $group = SettingGroup::factory()->create();
    Setting::factory()->count(3)->create(['group_id' => $group->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/setting');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['groups']]);
});

test('管理员可以获取指定组的设置', function () {
    $group = SettingGroup::factory()->create();
    Setting::factory()->count(3)->create(['group_id' => $group->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/setting/group/$group->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['group']]);
});

test('获取不存在的设置组返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/setting/group/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以查看设置详情', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/setting/$setting->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $setting->id);
});

test('管理员可以添加设置项', function () {
    $group = SettingGroup::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/setting', [
        'group_id' => $group->id,
        'key' => 'test_key',
        'type' => 'string',
        'value' => 'test_value',
        'description' => '测试设置',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Setting::where('key', 'test_key')->exists())->toBeTrue();
});

test('管理员可以更新设置', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/setting/$setting->id", [
        'group_id' => $setting->group_id,
        'key' => $setting->key,
        'type' => $setting->type,
        'value' => 'updated_value',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $setting->refresh();
    expect($setting->value)->toBe('updated_value');
});

test('管理员可以批量更新设置', function () {
    $settings = Setting::factory()->count(3)->create();

    $updateData = $settings->map(function ($setting) {
        return ['id' => $setting->id, 'value' => 'batch_updated'];
    })->toArray();

    $response = $this->actingAsAdmin($this->admin)->putJson('/api/admin/setting/batch-update', [
        'settings' => $updateData,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    foreach ($settings as $setting) {
        $setting->refresh();
        expect($setting->value)->toBe('batch_updated');
    }
});

test('管理员可以删除设置', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/setting/$setting->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Setting::find($setting->id))->toBeNull();
});

test('管理员可以清除设置缓存', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/setting/clear-cache');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('未认证用户无法访问设置管理', function () {
    $response = $this->getJson('/api/admin/setting');

    $response->assertUnauthorized();
});

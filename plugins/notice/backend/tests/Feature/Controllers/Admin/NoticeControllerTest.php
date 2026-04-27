<?php

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Notice\Models\Notice;
use Tests\Traits\ActsAsAdmin;

uses(Tests\TestCase::class, ActsAsAdmin::class, RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('index 返回分页列表', function () {
    Notice::factory()->count(3)->create();

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/notice');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(3);
});

test('index 支持 is_active 筛选', function () {
    Notice::factory()->count(2)->create(['is_active' => true]);
    Notice::factory()->create(['is_active' => false]);

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/notice?is_active=1');

    $response->assertOk();
    expect($response->json('data.total'))->toBe(2);
});

test('store 创建公告', function () {
    $response = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/notice', [
            'title' => '测试公告',
            'content' => '公告内容',
            'type' => 'warning',
            'position' => 'order',
            'sort' => 10,
        ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.title'))->toBe('测试公告');
    expect($response->json('data.position'))->toBe('order');
    $this->assertDatabaseHas('notices', ['title' => '测试公告', 'type' => 'warning', 'position' => 'order']);
});

test('store 验证必填字段', function () {
    $response = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/notice', []);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('errors'))->toHaveKeys(['title', 'content']);
});

test('store 验证 type 枚举', function () {
    $response = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/notice', [
            'title' => '测试',
            'content' => '内容',
            'type' => 'danger',
        ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('errors'))->toHaveKey('type');
});

test('store 验证 position 枚举', function () {
    $response = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/notice', [
            'title' => '测试',
            'content' => '内容',
            'position' => 'invalid',
        ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('errors'))->toHaveKey('position');
});

test('store 默认 position 为 dashboard', function () {
    $response = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/notice', [
            'title' => '测试公告',
            'content' => '公告内容',
        ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $this->assertDatabaseHas('notices', ['title' => '测试公告', 'position' => 'dashboard']);
});

test('update 更新公告', function () {
    $notice = Notice::factory()->create(['title' => '旧标题', 'position' => 'dashboard']);

    $response = $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/notice/$notice->id", [
            'title' => '新标题',
            'content' => '新内容',
            'position' => 'popup',
        ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($notice->fresh()->title)->toBe('新标题');
    expect($notice->fresh()->position)->toBe('popup');
});

test('destroy 删除公告', function () {
    $notice = Notice::factory()->create();

    $response = $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/notice/$notice->id");

    $response->assertOk();
    $this->assertDatabaseMissing('notices', ['id' => $notice->id]);
});

test('toggle 切换激活状态', function () {
    $notice = Notice::factory()->create(['is_active' => true]);

    $response = $this->actingAsAdmin($this->admin)
        ->patchJson("/api/admin/notice/$notice->id/toggle");

    $response->assertOk();
    expect($notice->fresh()->is_active)->toBeFalse();
});

test('toggle 再次切换恢复激活', function () {
    $notice = Notice::factory()->create(['is_active' => false]);

    $this->actingAsAdmin($this->admin)
        ->patchJson("/api/admin/notice/$notice->id/toggle");

    expect($notice->fresh()->is_active)->toBeTrue();
});

test('未认证访问返回 401', function () {
    $this->getJson('/api/admin/notice')->assertUnauthorized();
});

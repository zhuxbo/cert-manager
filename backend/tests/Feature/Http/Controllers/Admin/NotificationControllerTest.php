<?php

use App\Models\Admin;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\MocksExternalApis;

uses(ActsAsAdmin::class);
uses(MocksExternalApis::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取通知列表', function () {
    Notification::factory()->count(3)->create(['notifiable_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/notification');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按状态筛选通知', function () {
    Notification::factory()->sent()->create(['notifiable_id' => $this->user->id]);
    Notification::factory()->failed()->create(['notifiable_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/notification?status=sent');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按用户筛选通知', function () {
    Notification::factory()->create(['notifiable_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    Notification::factory()->create(['notifiable_id' => $otherUser->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/notification?user_id={$this->user->id}");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看通知详情', function () {
    $notification = Notification::factory()->create(['notifiable_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/notification/$notification->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $notification->id);
});

test('查看不存在的通知返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/notification/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以发送测试通知', function () {
    $this->mockSmtp();

    $template = NotificationTemplate::factory()->create([
        'code' => 'test_template',
        'status' => 1,
    ]);

    $mockCenter = Mockery::mock(NotificationCenter::class);
    $mockCenter->shouldReceive('dispatch')->once();
    $this->app->instance(NotificationCenter::class, $mockCenter);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/notification/test-send', [
        'template_type' => 'test_template',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->user->id,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
});

test('发送测试通知时模板不存在返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/notification/test-send', [
        'template_type' => 'nonexistent_template',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->user->id,
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以重发通知', function () {
    $template = NotificationTemplate::factory()->create(['status' => 1]);
    $notification = Notification::factory()->sent()->create([
        'notifiable_id' => $this->user->id,
        'template_id' => $template->id,
        'data' => ['title' => '测试', 'content' => '内容'],
    ]);

    $mockCenter = Mockery::mock(NotificationCenter::class);
    $mockCenter->shouldReceive('dispatch')->once();
    $this->app->instance(NotificationCenter::class, $mockCenter);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/notification/resend/$notification->id");

    $response->assertOk()->assertJson(['code' => 1]);
});

test('重发不存在的通知返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/notification/resend/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以分页获取通知列表', function () {
    Notification::factory()->count(15)->create(['notifiable_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/notification?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问通知管理', function () {
    $response = $this->getJson('/api/admin/notification');

    $response->assertUnauthorized();
});

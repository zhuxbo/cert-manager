<?php

use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

// index() 用 (int) $request->input('pageSize', 10) 兜底默认值：客户端显式传 null 时
// input() 返回 null（key 存在），(int) null = 0 → limit(0) 静默返回空列表。
// 正确写法应为 (int) ($request->input('pageSize') ?? 10)，显式 null 回落默认值。
test('管理员通知模板列表-pageSize 显式 null 回落默认值，不静默返回空列表', function () {
    NotificationTemplate::factory()->count(3)->create();

    $response = $this->actingAsAdmin()
        ->json('GET', '/api/admin/notification-template', ['pageSize' => null])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.pageSize'))->toBe(10);
    expect($response->json('data.items'))->toHaveCount(3);
});

test('管理员通知模板列表-currentPage 显式 null 回落默认值', function () {
    NotificationTemplate::factory()->count(3)->create();

    $response = $this->actingAsAdmin()
        ->json('GET', '/api/admin/notification-template', ['currentPage' => null])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.currentPage'))->toBe(1);
    expect($response->json('data.items'))->toHaveCount(3);
});

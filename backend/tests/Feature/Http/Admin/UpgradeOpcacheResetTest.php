<?php

use App\Models\Admin;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class)->group('database');

// ==========================================
// 1. opcache-reset 接口契约（按扩展加载状态分支返回）
// ==========================================

test('opcache-reset 按扩展加载状态返回对应结构', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/opcache-reset');

    $response->assertOk()->assertJson(['code' => 1]);

    if (function_exists('opcache_reset')) {
        // 扩展已加载 → status=ok 或 failed（视 opcache_reset() 返回值）
        $status = $response->json('data.status');
        expect(in_array($status, ['ok', 'failed'], true))->toBeTrue();
        // opcache_get_status 返回 false 时 opcache_status 字段可能为 null
        expect($response->json('data'))->toHaveKey('opcache_status');
    } else {
        // 扩展未加载 → status=skipped + reason，调用方据此走兜底（如宝塔 php_reload）
        $response->assertJson([
            'data' => [
                'status' => 'skipped',
                'reason' => 'opcache_extension_not_loaded',
            ],
        ]);
    }
});

// ==========================================
// 2. 仅 admin 可调
// ==========================================

test('未登录时 POST /api/admin/upgrade/opcache-reset 返回 401', function () {
    $this->postJson('/api/admin/upgrade/opcache-reset')->assertUnauthorized();
});

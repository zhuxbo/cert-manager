<?php

use App\Models\Admin;
use Tests\Traits\ActsAsAdmin;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(ActsAsAdmin::class);

// ==========================================
// JWT 认证
// ==========================================

test('AdminAuthenticate 有效 JWT 通过', function () {
    $admin = Admin::factory()->create();

    $this->actingAsAdmin($admin)
        ->getJson('/api/admin/me')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('AdminAuthenticate 无 token 返回 401', function () {
    $this->getJson('/api/admin/me')
        ->assertUnauthorized();
});

test('AdminAuthenticate 无效 token 返回 401', function () {
    $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/admin/me')
        ->assertUnauthorized();
});

test('AdminAuthenticate 禁用账号返回 401', function () {
    $admin = Admin::factory()->create(['status' => 0]);

    $this->actingAsAdmin($admin)
        ->getJson('/api/admin/me')
        ->assertUnauthorized();
});

test('AdminAuthenticate token_version 过期且超过宽限期返回 401', function () {
    $admin = Admin::factory()->create([
        'token_version' => 0,
        'logout_at' => now()->subMinutes(5),
    ]);

    // 用旧的 token_version 生成令牌
    $token = JWTAuth::fromUser($admin);

    // 增加 token_version（模拟密码修改或登出）
    Admin::where('id', $admin->id)->update([
        'token_version' => 1,
        'logout_at' => now()->subMinutes(5),
    ]);

    $this->withHeaders(['Authorization' => "Bearer $token"])
        ->getJson('/api/admin/me')
        ->assertUnauthorized();
});

test('AdminAuthenticate 已认证管理员可以获取信息', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->getJson('/api/admin/me');
    $response->assertOk();
    $response->assertJsonPath('data.id', $admin->id);
});

<?php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Str;

// ==========================================
// API Token 认证
// ==========================================

test('ApiAuthenticate 有效 api_token 通过', function () {
    $user = User::factory()->create();
    $rawToken = Str::random(64);
    $apiToken = ApiToken::factory()->create([
        'user_id' => $user->id,
        'token' => $rawToken,
        'status' => 1,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->getJson('/api/V1/health')
        ->assertOk();
});

test('ApiAuthenticate 无 token 返回错误', function () {
    $this->getJson('/api/V1/health')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('ApiAuthenticate 无效 token 返回错误', function () {
    $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
        ->getJson('/api/V1/health')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('ApiAuthenticate 禁用 token 返回错误', function () {
    $user = User::factory()->create();
    $rawToken = Str::random(64);
    $apiToken = ApiToken::factory()->create([
        'user_id' => $user->id,
        'token' => $rawToken,
        'status' => 0,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->getJson('/api/V1/health')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('ApiAuthenticate IP 受限 token 在不允许的 IP 返回错误', function () {
    $user = User::factory()->create();
    $rawToken = Str::random(64);
    $apiToken = ApiToken::factory()->withAllowedIps(['10.0.0.1', '10.0.0.2'])->create([
        'user_id' => $user->id,
        'token' => $rawToken,
        'status' => 1,
    ]);

    // 测试请求的 IP 默认是 127.0.0.1，不在允许列表中
    $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->getJson('/api/V1/health')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('ApiAuthenticate 所属用户禁用返回错误', function () {
    // token 自身启用，但所属用户被禁用 → 拒绝（与 JWT 侧 Account is disabled 对齐）
    $user = User::factory()->disabled()->create();
    $rawToken = Str::random(64);
    ApiToken::factory()->create([
        'user_id' => $user->id,
        'token' => $rawToken,
        'status' => 1,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->getJson('/api/V1/health')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

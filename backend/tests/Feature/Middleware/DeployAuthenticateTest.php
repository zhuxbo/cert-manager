<?php

use App\Models\DeployToken;
use App\Models\User;

// ==========================================
// Deploy Token 认证
// ==========================================

test('DeployAuthenticate 有效 token 通过', function () {
    $user = User::factory()->create();
    $deployToken = DeployToken::factory()->create([
        'user_id' => $user->id,
        'status' => 1,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->getJson('/api/deploy')
        ->assertOk();
});

test('DeployAuthenticate 无 token 返回错误', function () {
    $this->getJson('/api/deploy')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('DeployAuthenticate 无效 token 返回错误', function () {
    $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/deploy')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('DeployAuthenticate 禁用 token 返回错误', function () {
    $user = User::factory()->create();
    $deployToken = DeployToken::factory()->create([
        'user_id' => $user->id,
        'status' => 0,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->getJson('/api/deploy')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('DeployAuthenticate 所属用户禁用返回错误', function () {
    // token 自身启用，但所属用户被禁用 → 拒绝（与 JWT 侧 Account is disabled 对齐）
    $user = User::factory()->disabled()->create();
    $deployToken = DeployToken::factory()->create([
        'user_id' => $user->id,
        'status' => 1,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->getJson('/api/deploy')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('DeployAuthenticate GET query token 通过', function () {
    $user = User::factory()->create();
    $deployToken = DeployToken::factory()->create([
        'user_id' => $user->id,
        'status' => 1,
    ]);

    $this->getJson("/api/deploy?token=$deployToken->token")
        ->assertOk();
});

test('DeployAuthenticate GET query token 无效返回错误', function () {
    $this->getJson('/api/deploy?token=invalid-token')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('DeployAuthenticate Bearer 优先于 GET query token', function () {
    $user = User::factory()->create();
    $validToken = DeployToken::factory()->create([
        'user_id' => $user->id,
        'status' => 1,
    ]);

    // Bearer 有效 + query 无效 → 应通过（Bearer 优先）
    $this->withHeaders(['Authorization' => "Bearer $validToken->token"])
        ->getJson('/api/deploy?token=invalid-token')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('DeployAuthenticate IP 受限 token 在不允许的 IP 返回错误', function () {
    $user = User::factory()->create();
    $deployToken = DeployToken::factory()->withAllowedIps(['10.0.0.1'])->create([
        'user_id' => $user->id,
        'status' => 1,
    ]);

    $this->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->getJson('/api/deploy')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

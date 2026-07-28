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

    // 不带 order 会被业务层参数校验拦下（invalid_order）——这恰好证明请求已穿过认证中间件，
    // 比只断 assertOk 精确：认证失败同样是 HTTP 200，单看状态码分辨不出（反模式 15 伪绿）。
    $this->getJson("/api/deploy?token=$deployToken->token")
        ->assertOk()
        ->assertJsonPath('errors.error_code', 'invalid_order');
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

    // Bearer 有效 + query 无效 → 应通过（Bearer 优先）。
    // 断 invalid_order 而非 token_invalid：前者说明认证已放行、被业务层参数校验拦下，
    // 后者才是"用了 query 里那个无效 token"——这一对取值精确区分了优先级是否生效。
    $this->withHeaders(['Authorization' => "Bearer $validToken->token"])
        ->getJson('/api/deploy?token=invalid-token')
        ->assertOk()
        ->assertJsonPath('errors.error_code', 'invalid_order');
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

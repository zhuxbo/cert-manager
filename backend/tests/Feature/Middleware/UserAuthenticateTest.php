<?php

use App\Models\User;
use Tests\Traits\ActsAsUser;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(ActsAsUser::class);

// ==========================================
// 用户 JWT 认证
// ==========================================

test('UserAuthenticate 有效 JWT 通过', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('UserAuthenticate 无 token 返回 401', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized();
});

test('UserAuthenticate 无效 token 返回 401', function () {
    $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/me')
        ->assertUnauthorized();
});

test('UserAuthenticate 禁用账号返回 401', function () {
    $user = User::factory()->create(['status' => 0]);

    $this->actingAsUser($user)
        ->getJson('/api/me')
        ->assertUnauthorized();
});

test('UserAuthenticate token_version 过期且超过宽限期返回 401', function () {
    $user = User::factory()->create([
        'token_version' => 0,
        'logout_at' => now()->subMinutes(5),
    ]);

    $token = JWTAuth::claims(['guard' => 'user'])->fromUser($user);

    User::where('id', $user->id)->update([
        'token_version' => 1,
        'logout_at' => now()->subMinutes(5),
    ]);

    $this->withHeaders(['Authorization' => "Bearer $token"])
        ->getJson('/api/me')
        ->assertUnauthorized();
});

test('UserAuthenticate 已认证用户可以获取信息', function () {
    $user = User::factory()->create();

    $response = $this->actingAsUser($user)->getJson('/api/me');
    $response->assertOk();
    $response->assertJsonPath('data.username', $user->username);
});

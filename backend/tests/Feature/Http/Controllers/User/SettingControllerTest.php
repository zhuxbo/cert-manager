<?php

use App\Models\ApiToken;
use App\Models\DeployToken;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('获取 API Token-无记录返回空', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/setting/api-token')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取 API Token-已有记录', function () {
    $user = User::factory()->create();
    ApiToken::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson('/api/setting/api-token')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('更新 API Token-创建新记录', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->putJson('/api/setting/api-token', [
            'token' => 'new_token_string_12345',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(ApiToken::where('user_id', $user->id)->exists())->toBeTrue();
});

test('更新 API Token-更新已有记录', function () {
    $user = User::factory()->create();
    ApiToken::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->putJson('/api/setting/api-token', [
            'token' => 'updated_token_string',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取回调设置-无记录', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/setting/callback')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('更新回调设置', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->putJson('/api/setting/callback', [
            'url' => 'https://example.com/callback',
            'token' => 'callback_token',
            'status' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取通知设置', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/setting/notification-preferences')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('更新通知设置', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->putJson('/api/setting/notification-preferences', [
            'email_enabled' => true,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取 Deploy Token-无记录', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/setting/deploy-token')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取 Deploy Token-已有记录', function () {
    $user = User::factory()->create();
    DeployToken::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson('/api/setting/deploy-token')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('更新 Deploy Token-创建新记录', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->putJson('/api/setting/deploy-token', [
            'token' => 'abcdefghijklmnopqrstuvwxyz012345',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(DeployToken::where('user_id', $user->id)->exists())->toBeTrue();
});

test('删除 Deploy Token', function () {
    $user = User::factory()->create();
    DeployToken::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->deleteJson('/api/setting/deploy-token')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(DeployToken::where('user_id', $user->id)->exists())->toBeFalse();
});

test('获取自动续签设置', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/setting/auto-preferences')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('更新自动续签设置', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->putJson('/api/setting/auto-preferences', [
            'auto_renew' => true,
            'auto_reissue' => false,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $autoSettings = $user->fresh()->auto_settings;
    expect($autoSettings['auto_renew'])->toBeTrue();
    expect($autoSettings['auto_reissue'])->toBeFalse();
});

test('设置-未认证', function () {
    $this->getJson('/api/setting/api-token')
        ->assertUnauthorized();
});

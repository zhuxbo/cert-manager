<?php

use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('用户登录成功', function () {
    $user = User::factory()->create([
        'password' => 'password123',
        'status' => 1,
    ]);

    $response = $this->postJson('/api/login', [
        'account' => $user->email,
        'password' => 'password123',
    ]);

    $response
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'username', 'balance']]);

    $plainRefreshToken = $response->json('data.refresh_token');
    $storedRefreshToken = UserRefreshToken::where('user_id', $user->id)->first();

    expect($storedRefreshToken)->not->toBeNull();
    expect($storedRefreshToken?->refresh_token)->toBe(hash('sha256', $plainRefreshToken));
    expect($user->fresh()->last_login_at)->not->toBeNull();
    expect($user->fresh()->last_login_ip)->not->toBeNull();
});

test('用户登录失败-密码错误', function () {
    $user = User::factory()->create([
        'password' => 'password123',
    ]);

    $this->postJson('/api/login', [
        'account' => $user->email,
        'password' => 'wrong_password',
    ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(UserRefreshToken::count())->toBe(0);
});

test('用户注册成功', function () {
    Cache::put('verify_code_register_newuser@example.com', '123456', 600);

    $response = $this->postJson('/api/register', [
        'username' => 'newuser123',
        'email' => 'newuser@example.com',
        'password' => 'password123',
        'code' => '123456',
    ]);

    $response
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'username']]);

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user?->email_verified_at)->not->toBeNull();
    expect(UserRefreshToken::where('user_id', $user?->id)->count())->toBe(1);
});

test('用户注册失败-用户名已存在', function () {
    $user = User::factory()->create();

    $this->postJson('/api/register', [
        'username' => $user->username,
        'email' => 'another@example.com',
        'password' => 'password123',
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('用户注册失败-验证码为空', function () {
    $this->postJson('/api/register', [
        'username' => 'newuser456',
        'email' => 'newuser456@example.com',
        'password' => 'password123',
        'code' => '',
    ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('重置密码成功', function () {
    $user = User::factory()->create([
        'email' => 'reset@example.com',
    ]);

    Cache::put('verify_code_reset_reset@example.com', '123456', 600);

    $this->postJson('/api/reset-password', [
        'email' => 'reset@example.com',
        'password' => 'newpassword123',
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
});

test('重置密码-验证码无效返回错误', function () {
    // 安全修复：去掉 exists:users,email 校验（不再通过 errors.email 暴露邮箱是否注册）
    expectsBreakingChange('audit-2026-06: reset-password 移除 exists:users,email 枚举校验，未注册邮箱不再返回 errors.email');

    $this->postJson('/api/reset-password', [
        'email' => 'nonexistent@example.com',
        'password' => 'newpassword123',
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('重置密码-不暴露邮箱是否注册（账号枚举消歧）', function () {
    expectsBreakingChange('audit-2026-06: reset-password 对存在/不存在邮箱统一返回成功式响应');
    // 已注册邮箱：缓存有效验证码 → 成功
    $user = User::factory()->create(['email' => 'exists@example.com']);
    Cache::put('verify_code_reset_exists@example.com', '111111', 600);

    $existsResponse = $this->postJson('/api/reset-password', [
        'email' => 'exists@example.com',
        'password' => 'newpassword123',
        'code' => '111111',
    ])->assertOk();

    // 未注册邮箱：同样存在一个有效验证码（攻击者对任意邮箱触发过 send-code）
    Cache::put('verify_code_reset_ghost@example.com', '222222', 600);

    $ghostResponse = $this->postJson('/api/reset-password', [
        'email' => 'ghost@example.com',
        'password' => 'newpassword123',
        'code' => '222222',
    ])->assertOk();

    // 两者对外响应不可区分（均 code=1），不泄露邮箱注册状态
    expect($existsResponse->json('code'))->toBe(1);
    expect($ghostResponse->json('code'))->toBe(1);

    // 内部仅对已注册邮箱真正改密；幽灵邮箱不会创建用户
    expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
    expect(User::where('email', 'ghost@example.com')->exists())->toBeFalse();
});

test('获取当前用户信息', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['username', 'email', 'balance']]);
});

test('获取用户信息-未认证返回错误', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized();
});

test('修改用户名成功', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->patchJson('/api/update-username', [
            'username' => 'updated_username',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($user->fresh()->username)->toBe('updated_username');
});

test('修改密码成功', function () {
    $user = User::factory()->create([
        'password' => 'oldpassword',
    ]);

    $this->actingAsUser($user)
        ->patchJson('/api/update-password', [
            'oldPassword' => 'oldpassword',
            'newPassword' => 'newpassword123',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
});

test('修改密码失败-旧密码错误', function () {
    $user = User::factory()->create([
        'password' => 'oldpassword',
    ]);

    $this->actingAsUser($user)
        ->patchJson('/api/update-password', [
            'oldPassword' => 'wrongpassword',
            'newPassword' => 'newpassword123',
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('修改密码失败-新旧密码相同', function () {
    $user = User::factory()->create([
        'password' => 'samepassword',
    ]);

    $this->actingAsUser($user)
        ->patchJson('/api/update-password', [
            'oldPassword' => 'samepassword',
            'newPassword' => 'samepassword',
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('绑定邮箱成功', function () {
    $user = User::factory()->create();

    Cache::put('verify_code_bind_newemail@example.com', '123456', 600);

    $this->actingAsUser($user)
        ->patchJson('/api/bind-email', [
            'email' => 'newemail@example.com',
            'code' => '123456',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($user->fresh()->email)->toBe('newemail@example.com');
});

test('退出登录成功', function () {
    $user = User::factory()->create();
    UserRefreshToken::createToken($user->id);
    UserRefreshToken::createToken($user->id);
    expect(UserRefreshToken::where('user_id', $user->id)->count())->toBe(2);

    $this->actingAsUser($user)
        ->deleteJson('/api/logout')
        ->assertOk()
        ->assertJson(['code' => 1]);

    $user->refresh();
    expect($user->token_version)->toBe(1);
    expect($user->logout_at)->not->toBeNull();
    expect(UserRefreshToken::where('user_id', $user->id)->count())->toBe(0);
});

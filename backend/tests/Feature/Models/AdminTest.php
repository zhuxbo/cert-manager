<?php

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

test('密码自动哈希存储', function () {
    $admin = Admin::factory()->create(['password' => 'mypassword']);

    expect(Hash::check('mypassword', $admin->getRawOriginal('password')))->toBeTrue();
    expect($admin->getRawOriginal('password'))->not->toBe('mypassword');
});

test('密码字段在序列化时隐藏', function () {
    $admin = Admin::factory()->create();
    $array = $admin->toArray();

    expect($array)->not->toHaveKey('password');
});

test('JWT 标识符返回主键', function () {
    $admin = Admin::factory()->create();

    expect($admin->getJWTIdentifier())->toBe($admin->getKey());
});

test('JWT 自定义声明包含 token_version', function () {
    $admin = Admin::factory()->create(['token_version' => 3]);

    $claims = $admin->getJWTCustomClaims();
    expect($claims)->toHaveKey('token_version');
    expect($claims['token_version'])->toBe(3);
});

test('JWT 自定义声明 token_version 默认为 0', function () {
    $admin = Admin::factory()->create();

    $claims = $admin->getJWTCustomClaims();
    expect($claims['token_version'])->toBe(0);
});

test('status 字段为整数', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $admin->refresh();

    expect($admin->status)->toBeInt();
});

test('禁用管理员 status 为 0', function () {
    $admin = Admin::factory()->disabled()->create();
    $admin->refresh();

    expect($admin->status)->toBe(0);
});

test('日期字段正确转换', function () {
    $admin = Admin::factory()->loggedIn()->create();
    $admin->refresh();

    expect($admin->last_login_at)->toBeInstanceOf(Carbon::class);
});

test('管理员有多态通知关联', function () {
    $admin = Admin::factory()->create();

    Notification::factory()->create([
        'notifiable_type' => Admin::class,
        'notifiable_id' => $admin->id,
    ]);

    expect($admin->notifications)->toHaveCount(1);
});

test('可通过 fillable 设置基本属性', function () {
    $admin = Admin::factory()->create([
        'username' => 'testadmin',
        'email' => 'admin@example.com',
        'mobile' => '13800138000',
    ]);

    expect($admin->username)->toBe('testadmin');
    expect($admin->email)->toBe('admin@example.com');
    expect($admin->mobile)->toBe('13800138000');
});

// ==================== revokeAllSessions（与 User 侧对称的三件套单点）====================

test('revokeAllSessions 自增 token_version 并落地 logout_at', function () {
    $admin = Admin::factory()->create([
        'token_version' => 3,
        'logout_at' => null,
    ]);

    $admin->revokeAllSessions();
    $admin->refresh();

    // token_version 自增 1（旧 access token 进入黑名单宽限期）
    expect($admin->token_version)->toBe(4);
    // logout_at 落地（中间件 checkTokenVersionGraceful 据此起算宽限期，不能漏）
    expect($admin->logout_at)->not->toBeNull();
});

test('revokeAllSessions 从 token_version 为 0 起步自增到 1', function () {
    $admin = Admin::factory()->create(['token_version' => 0]);

    $admin->revokeAllSessions();
    $admin->refresh();

    expect($admin->token_version)->toBe(1);
});

test('revokeAllSessions 清除该管理员所有 refresh token', function () {
    $admin = Admin::factory()->create(['token_version' => 0]);
    AdminRefreshToken::createToken($admin->id);
    AdminRefreshToken::createToken($admin->id);

    // 另一个管理员的 refresh token 不应被波及
    $other = Admin::factory()->create();
    AdminRefreshToken::createToken($other->id);

    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(2);

    $admin->revokeAllSessions();

    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(0);
    expect(AdminRefreshToken::where('admin_id', $other->id)->count())->toBe(1);
});

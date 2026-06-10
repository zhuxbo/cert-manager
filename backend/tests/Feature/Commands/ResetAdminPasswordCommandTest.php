<?php

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use Illuminate\Support\Facades\Hash;

test('签名为 admin:reset-password', function () {
    $admin = Admin::factory()->create(['username' => 'admin_reset']);

    $this->artisan('admin:reset-password', [
        'username' => 'admin_reset',
        'password' => 'newpassword123',
    ])
        ->expectsConfirmation("确定要重置管理员 'admin_reset' 的密码吗？", 'yes')
        ->expectsOutputToContain('成功重置')
        ->assertSuccessful();

    $admin->refresh();
    expect(Hash::check('newpassword123', $admin->password))->toBeTrue();
});

test('重置不存在的管理员密码失败', function () {
    $this->artisan('admin:reset-password', [
        'username' => 'nonexistent',
        'password' => 'newpassword123',
    ])
        ->expectsOutputToContain('不存在')
        ->assertFailed();
});

test('密码长度不足6位时验证失败', function () {
    Admin::factory()->create(['username' => 'admin_short']);

    $this->artisan('admin:reset-password', [
        'username' => 'admin_short',
        'password' => '12345',
    ])
        ->expectsOutputToContain('验证失败')
        ->assertFailed();
});

test('取消操作不修改密码', function () {
    $admin = Admin::factory()->create([
        'username' => 'admin_cancel',
        'password' => 'originalpassword',
    ]);

    $this->artisan('admin:reset-password', [
        'username' => 'admin_cancel',
        'password' => 'newpassword123',
    ])
        ->expectsConfirmation("确定要重置管理员 'admin_cancel' 的密码吗？", 'no')
        ->expectsOutputToContain('操作已取消')
        ->assertSuccessful();

    $admin->refresh();
    expect(Hash::check('originalpassword', $admin->password))->toBeTrue();
});

test('重置密码后 token_version 递增', function () {
    $admin = Admin::factory()->create([
        'username' => 'admin_version',
        'token_version' => 3,
    ]);

    $this->artisan('admin:reset-password', [
        'username' => 'admin_version',
        'password' => 'newpassword123',
    ])
        ->expectsConfirmation("确定要重置管理员 'admin_version' 的密码吗？", 'yes')
        ->assertSuccessful();

    $admin->refresh();
    expect($admin->token_version)->toBe(4);
});

test('重置密码后 logout_at 落地（中间件宽限期起算所需）', function () {
    $admin = Admin::factory()->create([
        'username' => 'admin_logout_at',
        'logout_at' => null,
    ]);

    $this->artisan('admin:reset-password', [
        'username' => 'admin_logout_at',
        'password' => 'newpassword123',
    ])
        ->expectsConfirmation("确定要重置管理员 'admin_logout_at' 的密码吗？", 'yes')
        ->assertSuccessful();

    $admin->refresh();
    // 仅 bump token_version 不写 logout_at，旧令牌从 1970 起算宽限期，行为异常 → 必须落地
    expect($admin->logout_at)->not->toBeNull();
});

test('重置密码后清空该管理员全部 refresh token', function () {
    $admin = Admin::factory()->create(['username' => 'admin_refresh_clear']);
    AdminRefreshToken::createToken($admin->id);
    AdminRefreshToken::createToken($admin->id);

    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(2);

    $this->artisan('admin:reset-password', [
        'username' => 'admin_refresh_clear',
        'password' => 'newpassword123',
    ])
        ->expectsConfirmation("确定要重置管理员 'admin_refresh_clear' 的密码吗？", 'yes')
        ->assertSuccessful();

    // 重置后旧会话无法再续期，refresh token 必须清空
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(0);
});

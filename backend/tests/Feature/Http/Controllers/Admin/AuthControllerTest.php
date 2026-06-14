<?php

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

test('管理员使用正确凭证登录成功', function () {
    $admin = Admin::factory()->create([
        'username' => 'testadmin',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/admin/login', [
        'account' => 'testadmin',
        'password' => 'password123',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'username']]);

    $plainRefreshToken = $response->json('data.refresh_token');
    $storedRefreshToken = AdminRefreshToken::where('admin_id', $admin->id)->first();

    expect($storedRefreshToken)->not->toBeNull();
    expect($storedRefreshToken?->refresh_token)->toBe(hash('sha256', $plainRefreshToken));
    expect($admin->fresh()->last_login_at)->not->toBeNull();
    expect($admin->fresh()->last_login_ip)->not->toBeNull();
});

test('管理员使用邮箱登录成功', function () {
    $admin = Admin::factory()->create([
        'email' => 'admin@test.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/admin/login', [
        'account' => 'admin@test.com',
        'password' => 'password123',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(1);
    expect($admin->fresh()->last_login_at)->not->toBeNull();
});

test('管理员使用错误密码登录失败', function () {
    Admin::factory()->create([
        'username' => 'testadmin',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/admin/login', [
        'account' => 'testadmin',
        'password' => 'wrongpassword',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(AdminRefreshToken::count())->toBe(0);
});

test('管理员使用不存在的账号登录失败', function () {
    $response = $this->postJson('/api/admin/login', [
        'account' => 'nonexistent',
        'password' => 'password123',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(AdminRefreshToken::count())->toBe(0);
});

test('已禁用的管理员登录失败', function () {
    Admin::factory()->disabled()->create([
        'username' => 'disabledadmin',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/admin/login', [
        'account' => 'disabledadmin',
        'password' => 'password123',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(AdminRefreshToken::count())->toBe(0);
});

test('已认证管理员可以获取个人信息', function () {
    $admin = Admin::factory()->loggedIn()->create();

    $response = $this->actingAsAdmin($admin)->getJson('/api/admin/me');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['id', 'username', 'email', 'mobile']]);
    $response->assertJsonPath('data.id', $admin->id);
});

test('未认证用户无法获取管理员信息', function () {
    $response = $this->getJson('/api/admin/me');

    $response->assertUnauthorized();
});

test('管理员可以更新个人资料', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->patchJson('/api/admin/update-profile', [
        'email' => 'newemail@test.com',
        'mobile' => '13800138000',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $admin->refresh();
    expect($admin->email)->toBe('newemail@test.com');
    expect($admin->mobile)->toBe('13800138000');
});

test('管理员使用正确旧密码可以修改密码', function () {
    $admin = Admin::factory()->create([
        'password' => 'oldpassword',
        'token_version' => 0,
        'logout_at' => null,
    ]);
    AdminRefreshToken::createToken($admin->id);
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(1);

    $response = $this->actingAsAdmin($admin)->patchJson('/api/admin/update-password', [
        'oldPassword' => 'oldpassword',
        'newPassword' => 'newpassword123',
        'newPassword_confirmation' => 'newpassword123',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $admin->refresh();
    expect(Hash::check('newpassword123', $admin->password))->toBeTrue();
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(0);
    // 改密必须吊销旧 access token：bump token_version（旧 JWT 进黑名单宽限期）+ 落地 logout_at（宽限期起算）
    // 否则只清 refresh token，旧 access token 在 ttl 内仍有效 → 安全漏洞
    expect($admin->token_version)->toBe(1);
    expect($admin->logout_at)->not->toBeNull();
});

test('管理员使用错误旧密码无法修改密码', function () {
    $admin = Admin::factory()->create([
        'password' => 'oldpassword',
    ]);
    AdminRefreshToken::createToken($admin->id);
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(1);

    $response = $this->actingAsAdmin($admin)->patchJson('/api/admin/update-password', [
        'oldPassword' => 'wrongoldpassword',
        'newPassword' => 'newpassword123',
        'newPassword_confirmation' => 'newpassword123',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(Hash::check('oldpassword', $admin->fresh()->password))->toBeTrue();
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(1);
});

test('管理员可以退出登录', function () {
    $admin = Admin::factory()->create();
    AdminRefreshToken::createToken($admin->id);
    AdminRefreshToken::createToken($admin->id);
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(2);

    $response = $this->actingAsAdmin($admin)->deleteJson('/api/admin/logout');

    $response->assertOk()->assertJson(['code' => 1]);

    $admin->refresh();
    expect($admin->token_version)->toBe(1);
    expect($admin->logout_at)->not->toBeNull();
    expect(AdminRefreshToken::where('admin_id', $admin->id)->count())->toBe(0);
});

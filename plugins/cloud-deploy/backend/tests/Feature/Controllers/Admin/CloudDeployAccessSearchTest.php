<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

function mkAccess(string $name, string $provider): CloudDeployAccess
{
    $user = User::factory()->create();

    return CloudDeployAccess::create([
        'user_id' => $user->id, 'name' => $name, 'provider' => $provider,
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SECRET_NEVER_LEAK'],
    ]);
}

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('admin accesses 按 name LIKE 筛选', function () {
    mkAccess('prod-aliyun-key', 'aliyun');
    mkAccess('test-tencent-key', 'tencent');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access?name=prod')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin accesses 按 provider 筛选', function () {
    mkAccess('a', 'aliyun');
    mkAccess('b', 'tencent');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access?provider=tencent')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin accesses 按 username 筛选（云凭证「用户」维度，whereExists join users）', function () {
    $u1 = User::factory()->create(['username' => 'access_owner_alpha']);
    CloudDeployAccess::create([
        'user_id' => $u1->id, 'name' => 'k1', 'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $u2 = User::factory()->create(['username' => 'access_owner_beta']);
    CloudDeployAccess::create([
        'user_id' => $u2->id, 'name' => 'k2', 'provider' => 'tencent',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access?username=alpha')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.username'))->toBe('access_owner_alpha'); // 顶层 username 拍平（attachUsernames）
});

test('admin accesses 按 created_at 时间范围筛选', function () {
    $old = mkAccess('old', 'aliyun');
    $old->forceFill(['created_at' => '2026-01-01 00:00:00'])->saveQuietly();
    $new = mkAccess('new', 'aliyun');
    $new->forceFill(['created_at' => '2026-06-01 00:00:00'])->saveQuietly();

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access?created_at_start=2026-05-01 00:00:00&created_at_end=2026-07-01 00:00:00')
        ->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin accesses 任何筛选下都不带出 credentials 明文', function () {
    mkAccess('prod', 'aliyun');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access?name=prod&provider=aliyun')->assertOk();

    expect(json_encode($res->json()))->not->toContain('SECRET_NEVER_LEAK');
    expect($res->json('data.items.0'))->not->toHaveKey('credentials');
});

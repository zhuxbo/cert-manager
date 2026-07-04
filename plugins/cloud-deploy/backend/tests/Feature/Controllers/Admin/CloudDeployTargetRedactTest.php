<?php

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

/** 造 (user, access, order, target)，access.provider 与 target.product/config 由参数控制。 */
function mkRedactTarget(string $provider, string $product, array $config): CloudDeployTarget
{
    $user = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $user->id, 'name' => 'acc', 'provider' => $provider,
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    return CloudDeployTarget::create([
        'user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => $product, 'config' => $config,
    ]);
}

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('webhook target 的 secret config 键(headers/webhook_data)被打码，非 secret 键(timeout)保留', function () {
    mkRedactTarget('webhook', 'webhook', [
        'headers' => 'Authorization: Bearer SECRET_TOKEN_XYZ',
        'webhook_data' => '{"token":"LEAK_ME_123"}',
        'timeout' => 30,
    ]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target')
        ->assertOk()->assertJson(['code' => 1]);

    $cfg = $res->json('data.items.0.config');
    expect($cfg['headers'])->toBe('******');
    expect($cfg['webhook_data'])->toBe('******');
    expect($cfg['timeout'])->toBe(30); // 非 secret 保留

    // 明文绝不出现在响应中
    $body = json_encode($res->json());
    expect($body)->not->toContain('SECRET_TOKEN_XYZ');
    expect($body)->not->toContain('LEAK_ME_123');
});

test('有 domain 的 target（aliyun cdn）domain 保留、不打码（资源列依赖）', function () {
    mkRedactTarget('aliyun', 'cdn', ['domain' => 'cdn.shop.com']);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target')
        ->assertOk();

    expect($res->json('data.items.0.config.domain'))->toBe('cdn.shop.com'); // 非 secret，保留原值
});

test('未注册组合（陈旧脏数据）fail-safe 打码除 domain 外全部键，不 500', function () {
    // provider/product 组合未注册：resolveDeployer 会抛，必须 hasDeployer 守卫后 fail-safe
    mkRedactTarget('ghost-provider', 'ghost-product', [
        'domain' => 'keep.example.com',
        'secret_field' => 'SHOULD_BE_MASKED_999',
        'another' => 'MASK_TOO_888',
    ]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target')
        ->assertOk()->assertJson(['code' => 1]); // 不 500

    $cfg = $res->json('data.items.0.config');
    expect($cfg['domain'])->toBe('keep.example.com'); // 已知安全键保留
    expect($cfg['secret_field'])->toBe('******');     // 无法判定 → 打码
    expect($cfg['another'])->toBe('******');

    $body = json_encode($res->json());
    expect($body)->not->toContain('SHOULD_BE_MASKED_999');
    expect($body)->not->toContain('MASK_TOO_888');
});

test('一个全局列表里同时有 webhook(secret 打码) + 有 domain 的 cdn(保留) + 陈旧(fail-safe) 三类不互相影响、整体不 500', function () {
    mkRedactTarget('aliyun', 'cdn', ['domain' => 'a.com']);
    mkRedactTarget('webhook', 'webhook', ['headers' => 'Authorization: Bearer ZZZ', 'webhook_data' => '{}', 'timeout' => 10]);
    mkRedactTarget('ghost', 'x', ['domain' => 'b.com', 'leak' => 'PLAINTEXT_LEAK']);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?pageSize=100')
        ->assertOk()->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(3);
    $body = json_encode($res->json());
    expect($body)->not->toContain('ZZZ');
    expect($body)->not->toContain('PLAINTEXT_LEAK');
    // domain 仍可见（资源列依赖）
    expect($body)->toContain('a.com');
    expect($body)->toContain('b.com');
});

test('行暴露顶层 provider + username（经 access/user 拍平，供 admin targets tab/widget 列渲染），脱敏不影响', function () {
    // user 显式给定 username，断言响应顶层 username = 该用户名（spec §7：用户列显示用户名而非 user_id）
    $owner = User::factory()->create(['username' => 'redact_owner_u']);
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id, 'name' => 'acc', 'provider' => 'tencent',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);
    CloudDeployTarget::create([
        'user_id' => $owner->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => 'prov.example.com'],
    ]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target')
        ->assertOk();

    expect($res->json('data.items.0.provider'))->toBe('tencent'); // 顶层 provider 非空、等于 access.provider
    expect($res->json('data.items.0.username'))->toBe('redact_owner_u'); // 顶层 username 拍平（attachUsernames）
    expect($res->json('data.items.0.config.domain'))->toBe('prov.example.com'); // 脱敏未误伤 domain
});

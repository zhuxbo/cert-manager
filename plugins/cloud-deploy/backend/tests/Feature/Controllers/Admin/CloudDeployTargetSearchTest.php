<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

/**
 * 造一个 (user, access, order, cert, target)，返回 target。
 *
 * @param  array<string,mixed>  $targetAttrs  覆盖 target 属性（product/config/enabled/last_status/last_deployed_at）
 */
function mkTarget(array $targetAttrs = [], string $provider = 'aliyun', string $cn = 'x.example.com', ?string $username = null): CloudDeployTarget
{
    $user = User::factory()->create($username ? ['username' => $username] : []);
    $access = CloudDeployAccess::create([
        'user_id' => $user->id, 'name' => $targetAttrs['__access_name'] ?? 'acc', 'provider' => $provider,
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'common_name' => $cn]);
    $order->update(['latest_cert_id' => $cert->id]);
    unset($targetAttrs['__access_name']);

    return CloudDeployTarget::create(array_merge([
        'user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => $cn],
    ], $targetAttrs));
}

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('admin targets 按 order_id 精确筛选（admin widget 取本订单目标）', function () {
    $t = mkTarget();
    mkTarget(); // 另一单

    $res = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/target?order_id={$t->order_id}")
        ->assertOk()->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(1);
    expect((int) $res->json('data.items.0.id'))->toBe((int) $t->id);
});

test('admin targets 按 provider 筛选（经 access join）', function () {
    mkTarget(provider: 'aliyun');
    mkTarget(provider: 'tencent');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?provider=tencent')
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin targets 按 product 筛选', function () {
    mkTarget(['product' => 'cdn']);
    mkTarget(['product' => 'oss']);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?product=oss')
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin targets 按 enabled 筛选', function () {
    mkTarget(['enabled' => true]);
    mkTarget(['enabled' => false]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?enabled=0')
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin targets 按 last_status 筛选 + 未推送=unpushed 命中 NULL', function () {
    mkTarget(['last_status' => 'success']);
    mkTarget(['last_status' => 'failed']);
    mkTarget(); // last_status NULL = 未推送

    $ok = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?last_status=success')->assertOk();
    expect($ok->json('data.total'))->toBe(1);

    $unpushed = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?last_status=unpushed')->assertOk();
    expect($unpushed->json('data.total'))->toBe(1); // 只命中 NULL 那条
});

test('admin targets 按 user_id 筛选（沿用既有能力）', function () {
    $t = mkTarget();
    mkTarget();

    $res = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/target?user_id={$t->user_id}")
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin targets 按 username LIKE 筛选', function () {
    mkTarget(username: 'alice99');
    mkTarget(username: 'bob77');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?username=alice')
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin targets 按域名(cert.common_name) keyword 筛选（whereHas 相关子查询，不串户）', function () {
    mkTarget(cn: 'shop.alpha.com');
    mkTarget(cn: 'api.beta.com');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?keyword=alpha')
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.config.domain'))->toBe('shop.alpha.com');
});

test('admin targets 域名 keyword 与 user_id 同时使用不报 1052 歧义', function () {
    $t = mkTarget(cn: 'gamma.example.com');
    mkTarget(cn: 'gamma.example.com'); // 另一用户同域名

    // 同时带 user_id + keyword：raw join 会触发「Column 'user_id' is ambiguous」，
    // whereHas 子查询不抬 orders.user_id，故应正常返回且只命中本用户那条
    $res = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/target?user_id={$t->user_id}&keyword=gamma")
        ->assertOk()->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(1);
});

test('admin targets 按 last_deployed_at / created_at 时间范围筛选', function () {
    $old = mkTarget(['last_deployed_at' => '2026-01-01 00:00:00']);
    $new = mkTarget(['last_deployed_at' => '2026-06-01 00:00:00']);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?last_deployed_at_start=2026-05-01 00:00:00&last_deployed_at_end=2026-07-01 00:00:00')
        ->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect((int) $res->json('data.items.0.id'))->toBe((int) $new->id);
});

test('admin targets quickSearch 命中订单号/域名/用户名/凭证名（orWhere 分组）', function () {
    $byCn = mkTarget(cn: 'quicksearch-domain.com');
    $byUser = mkTarget(username: 'quickuser');
    $byAccess = mkTarget(['__access_name' => 'quick-access-name']);
    $byOrder = mkTarget();
    mkTarget(); // 不命中任何关键词的噪声

    // 域名命中
    expect($this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?quickSearch=quicksearch-domain')
        ->assertOk()->json('data.total'))->toBe(1);

    // 用户名命中
    expect($this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?quickSearch=quickuser')
        ->assertOk()->json('data.total'))->toBe(1);

    // 凭证名命中
    expect($this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target?quickSearch=quick-access-name')
        ->assertOk()->json('data.total'))->toBe(1);

    // 订单号精确命中
    expect($this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/target?quickSearch={$byOrder->order_id}")
        ->assertOk()->json('data.total'))->toBe(1);
});

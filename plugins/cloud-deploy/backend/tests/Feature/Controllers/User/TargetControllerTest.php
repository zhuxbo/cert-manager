<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->access = CloudDeployAccess::create([
        'user_id' => $this->user->id, 'name' => 'A', 'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $this->order = Order::factory()->create(['user_id' => $this->user->id]);
    $this->cert = Cert::factory()->active()->create([
        'order_id' => $this->order->id,
        'common_name' => 'target.example.com',
    ]);
    $this->order->update(['latest_cert_id' => $this->cert->id]);
});

test('创建目标成功', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $this->order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'cdn.example.com'],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    expect(CloudDeployTarget::withoutGlobalScopes()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('创建纯上传目标时允许空 config', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $this->order->id,
            'product' => 'cas',
            'config' => [],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $target = CloudDeployTarget::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->first();

    expect($target)->not->toBeNull();
    expect($target->product)->toBe('cas');
    expect($target->config)->toBe([]);
});

test('拒绝同一凭证产品配置重复绑定到另一个订单', function () {
    CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'dup.example.com'],
    ]);
    $order2 = Order::factory()->create(['user_id' => $this->user->id]);
    $cert2 = Cert::factory()->active()->create([
        'order_id' => $order2->id,
        'common_name' => 'dup-2.example.com',
    ]);
    $order2->update(['latest_cert_id' => $cert2->id]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $order2->id,
            'product' => 'cdn',
            'config' => ['domain' => 'dup.example.com'],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(1);
});

test('拒绝绑定他人的凭证', function () {
    $other = User::factory()->create();
    $otherAccess = CloudDeployAccess::create([
        'user_id' => $other->id, 'name' => 'B', 'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $otherAccess->id,
            'order_id' => $this->order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'x.example.com'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('拒绝绑定他人的订单', function () {
    $other = User::factory()->create();
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $otherOrder->id,
            'product' => 'cdn',
            'config' => ['domain' => 'x.example.com'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('拒绝绑定非候选订单', function () {
    $expired = Cert::factory()->expired()->create(['common_name' => 'expired-bind.example.com']);
    $expiredOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'latest_cert_id' => $expired->id,
        'period_till' => now()->addMonth(),
    ]);
    $expired->update(['order_id' => $expiredOrder->id]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $expiredOrder->id,
            'product' => 'cdn',
            'config' => ['domain' => 'expired-bind.example.com'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('config 缺 schema required 字段被拒（clb 缺 region）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $this->order->id,
            'product' => 'clb',
            // clb 需要 load_balancer_id / listener_port / region，这里缺 region
            'config' => ['load_balancer_id' => 'lb-1', 'listener_port' => 443],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('config 含 schema 外字段被拒（白名单校验，防额外字段静默落库）', function () {
    // 加固 2：config 的 key 集必须 ⊆ deployer configSchema 的 key 集。
    // aliyun cdn schema 仅 domain；多塞一个字段即拒绝，不静默落库。
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $this->order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'cdn.example.com', 'evil' => 'x'], // evil 是 schema 外字段
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('更新重提 config 含 schema 外字段被拒（白名单校验）', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id,
        'product' => 'cdn', 'config' => ['domain' => 'a.example.com'],
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['config' => ['domain' => 'b.example.com', 'extra' => 1]])
        ->assertOk()->assertJson(['code' => 0]);

    // 原 config 不被覆盖
    expect(CloudDeployTarget::withoutGlobalScopes()->find($target->id)->config)->toBe(['domain' => 'a.example.com']);
});

test('更新时拒绝改成已有推送目标', function () {
    $order2 = Order::factory()->create(['user_id' => $this->user->id]);
    $cert2 = Cert::factory()->active()->create([
        'order_id' => $order2->id,
        'common_name' => 'update-dup-2.example.com',
    ]);
    $order2->update(['latest_cert_id' => $cert2->id]);
    CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'used.example.com'],
    ]);
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $order2->id,
        'product' => 'cdn',
        'config' => ['domain' => 'free.example.com'],
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", [
            'config' => ['domain' => 'used.example.com'],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->find($target->id)->config)
        ->toBe(['domain' => 'free.example.com']);
});

test('product 不属于该凭证的 provider 被拒（aliyun 凭证选腾讯独有 ssl-deploy）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id, // aliyun
            'order_id' => $this->order->id,
            'product' => 'ssl-deploy', // 仅腾讯注册
            'config' => ['resource_type' => 'cdn', 'instance_id_list' => 'x'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('config 齐 required 字段创建成功（clb 全字段）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/target', [
            'access_id' => $this->access->id,
            'order_id' => $this->order->id,
            'product' => 'clb',
            'config' => ['load_balancer_id' => 'lb-1', 'listener_port' => 443, 'region' => 'cn-hangzhou'],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(1);
});

test('存量 target 仅切 enabled（不重提 config）放行，向后兼容', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id,
        'product' => 'cdn', 'config' => ['domain' => 'legacy.example.com'], 'enabled' => true,
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['enabled' => false])
        ->assertOk()->assertJson(['code' => 1]);

    expect(CloudDeployTarget::withoutGlobalScopes()->find($target->id)->enabled)->toBeFalse();
});

test('更新结构字段清空 last 状态', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'old.example.com'],
        'last_cert_id' => $this->cert->id,
        'last_status' => 'success',
        'last_error' => 'old',
        'last_deployed_at' => now(),
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['config' => ['domain' => 'new.example.com']])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $fresh = CloudDeployTarget::withoutGlobalScopes()->find($target->id);
    expect($fresh->last_cert_id)->toBeNull();
    expect($fresh->last_status)->toBeNull();
    expect($fresh->last_error)->toBeNull();
    expect($fresh->last_deployed_at)->toBeNull();
});

test('仅切 enabled 不清空 last 状态', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'same.example.com'],
        'enabled' => true,
        'last_cert_id' => $this->cert->id,
        'last_status' => 'success',
        'last_deployed_at' => now(),
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['enabled' => false])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $fresh = CloudDeployTarget::withoutGlobalScopes()->find($target->id);
    expect($fresh->enabled)->toBeFalse();
    expect($fresh->last_status)->toBe('success');
    expect((int) $fresh->last_cert_id)->toBe((int) $this->cert->id);
});

test('更新 order_id 时拒绝非候选订单', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'active.example.com'],
    ]);
    $expired = Cert::factory()->expired()->create(['common_name' => 'expired-update.example.com']);
    $expiredOrder = Order::factory()->create(['user_id' => $this->user->id, 'latest_cert_id' => $expired->id]);
    $expired->update(['order_id' => $expiredOrder->id]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['order_id' => $expiredOrder->id])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect((int) CloudDeployTarget::withoutGlobalScopes()->find($target->id)->order_id)->toBe((int) $this->order->id);
});

test('更新 access_id 但不带 config 时仍按最终组合校验旧 config', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'oss',
        'config' => ['bucket' => 'bucket-a', 'domain' => 'oss.example.com', 'region' => 'cn-hangzhou'],
    ]);
    $tencent = CloudDeployAccess::create([
        'user_id' => $this->user->id,
        'name' => 'T',
        'provider' => 'tencent',
        'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK'],
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['access_id' => $tencent->id])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect((int) CloudDeployTarget::withoutGlobalScopes()->find($target->id)->access_id)->toBe((int) $this->access->id);
});

test('更新重提 config 时按 schema 校验（缺 domain 被拒）', function () {
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id,
        'product' => 'cdn', 'config' => ['domain' => 'a.example.com'],
    ]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/target/{$target->id}", ['config' => ['domain' => '']])
        ->assertOk()->assertJson(['code' => 0]);

    // 原值未被覆盖
    expect(CloudDeployTarget::withoutGlobalScopes()->find($target->id)->config)->toBe(['domain' => 'a.example.com']);
});

test('按 order_id 过滤目标列表', function () {
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'b']]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a']]);

    $res = $this->actingAsUser($this->user)->getJson("/api/cloud-deploy/target?order_id={$this->order->id}")->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('last_status=success 等值过滤', function () {
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'last_status' => 'success']);
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'last_status' => 'failed']);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?last_status=success')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.last_status'))->toBe('success');
});

test('last_status=unpushed 命中 last_status 为 NULL 的未推送目标', function () {
    // 未推送：last_status 为 NULL（仅推送后才写 success/failed）
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a']]); // last_status 默认 NULL
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'last_status' => 'success']);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?last_status=unpushed')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.last_status'))->toBeNull();
});

test('created_at 时间范围过滤', function () {
    $old = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a']]);
    $old->forceFill(['created_at' => '2020-01-01 00:00:00'])->saveQuietly();
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    $new = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'b']]);
    $new->forceFill(['created_at' => '2026-06-01 00:00:00'])->saveQuietly();

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?created_at_start=2026-01-01 00:00:00&created_at_end=2026-12-31 23:59:59')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    // snowflake id 经 toArray 序列化为 JSON number、json() 解码为 PHP int（无 BIGINT_AS_STRING）；
    // 用 (int) 两侧归一，勿 (string)——Pest 严格 toBe 下 int!==string 会误红（与 Task 4.2/5.1 同口径）。
    expect((int) $res->json('data.items.0.id'))->toBe((int) $new->id);
});

test('last_deployed_at 时间范围过滤', function () {
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'last_deployed_at' => '2026-06-15 12:00:00']);
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'last_deployed_at' => '2020-01-01 00:00:00']);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?last_deployed_at_start=2026-06-01 00:00:00&last_deployed_at_end=2026-06-30 23:59:59')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('域名 keyword 经 order.latestCert 模糊匹配 common_name（依赖 P1 的 order() 关系）', function () {
    // 命中：cert common_name 含 keyword
    $certHit = Cert::factory()->create(['order_id' => $this->order->id, 'common_name' => 'shop.example.com', 'status' => 'active']);
    $this->order->update(['latest_cert_id' => $certHit->id]);
    // 不命中：另一订单的 cert common_name 不含 keyword
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    $certMiss = Cert::factory()->create(['order_id' => $o2->id, 'common_name' => 'other.test.org', 'status' => 'active']);
    $o2->update(['latest_cert_id' => $certMiss->id]);

    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'x']]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'y']]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?keyword=example.com')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('quickSearch 命中域名/订单号/凭证名（orWhere 分组，依赖 P1 的 order() 关系）', function () {
    $certHit = Cert::factory()->create(['order_id' => $this->order->id, 'common_name' => 'billing.acme.io', 'status' => 'active']);
    $this->order->update(['latest_cert_id' => $certHit->id]);
    $o2 = Order::factory()->create(['user_id' => $this->user->id]);
    $certMiss = Cert::factory()->create(['order_id' => $o2->id, 'common_name' => 'nomatch.example.net', 'status' => 'active']);
    $o2->update(['latest_cert_id' => $certMiss->id]);

    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'x']]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $o2->id, 'product' => 'cdn', 'config' => ['domain' => 'y']]);

    // 命中域名
    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?quickSearch=acme.io')->assertOk();
    expect($res->json('data.total'))->toBe(1);

    // 命中订单号（精确 order_id）
    $res2 = $this->actingAsUser($this->user)->getJson("/api/cloud-deploy/target?quickSearch={$this->order->id}")->assertOk();
    expect($res2->json('data.total'))->toBe(1);

    // 命中凭证名（access.name='A'）→ 两个 target 都属同一 access，故都命中
    $res3 = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target?quickSearch=A')->assertOk();
    expect($res3->json('data.total'))->toBe(2);
});

test('列表行暴露顶层 provider（经 access 拍平，供前端「云平台·产品」列渲染）', function () {
    // $this->access 既有 provider=aliyun；target 行无 provider 列，须经 with(access).setAttribute 拍平
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'p.example.com']]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/target')->assertOk();
    expect($res->json('data.items.0.provider'))->toBe('aliyun'); // 顶层 provider 非空、等于 access.provider
});

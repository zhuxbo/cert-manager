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

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('admin 看到全部用户的凭证，但不含 credentials 明文', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    foreach ([$u1, $u2] as $u) {
        CloudDeployAccess::create([
            'user_id' => $u->id, 'name' => 'acc', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SECRET789'],
        ]);
    }

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access')
        ->assertOk()->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(2);
    expect(json_encode($res->json()))->not->toContain('SECRET789');
});

test('admin 可按 user_id 筛选', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    foreach ([$u1, $u2] as $u) {
        CloudDeployAccess::create([
            'user_id' => $u->id, 'name' => 'acc', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
        ]);
    }

    $res = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/access?user_id={$u1->id}")
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin 可读取 provider catalog 供 schema 表单渲染', function () {
    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/providers')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($res->json('data.providers'))->toBeArray()->not->toBeEmpty();
});

test('admin 可为订单所属用户新增部署目标', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'common_name' => 'admin-target.example.com']);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'cdn.example.com'],
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $target = CloudDeployTarget::withoutGlobalScopes()->first();
    expect($target)->not->toBeNull();
    expect((int) $target->user_id)->toBe((int) $owner->id);
    expect((int) $target->order_id)->toBe((int) $order->id);
    expect((int) $target->access_id)->toBe((int) $access->id);
});

test('admin 可新增纯上传部署目标并允许空 config', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'common_name' => 'admin-cas.example.com']);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $order->id,
            'product' => 'cas',
            'config' => [],
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $target = CloudDeployTarget::withoutGlobalScopes()->first();
    expect($target)->not->toBeNull();
    expect((int) $target->user_id)->toBe((int) $owner->id);
    expect($target->product)->toBe('cas');
    expect($target->config)->toBe([]);
});

test('admin 新增部署目标时拒绝同一凭证产品配置重复绑定到另一个订单', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $orderA = Order::factory()->create(['user_id' => $owner->id]);
    $certA = Cert::factory()->active()->create(['order_id' => $orderA->id, 'common_name' => 'admin-dup-a.example.com']);
    $orderA->update(['latest_cert_id' => $certA->id]);
    $orderB = Order::factory()->create(['user_id' => $owner->id]);
    $certB = Cert::factory()->active()->create(['order_id' => $orderB->id, 'common_name' => 'admin-dup-b.example.com']);
    $orderB->update(['latest_cert_id' => $certB->id]);
    CloudDeployTarget::create([
        'user_id' => $owner->id,
        'access_id' => $access->id,
        'order_id' => $orderA->id,
        'product' => 'cdn',
        'config' => ['domain' => 'admin-dup.example.com'],
    ]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $orderB->id,
            'product' => 'cdn',
            'config' => ['domain' => 'admin-dup.example.com'],
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(1);
});

test('admin 可新增编辑删除云凭证但不能修改 user_id/provider', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/access', [
            'user_id' => $owner->id,
            'name' => 'owner aliyun',
            'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $access = CloudDeployAccess::withoutGlobalScopes()->where('user_id', $owner->id)->firstOrFail();

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/cloud-deploy/access/{$access->id}", ['name' => 'renamed'])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $fresh = CloudDeployAccess::withoutGlobalScopes()->find($access->id);
    expect($fresh->name)->toBe('renamed');
    expect((int) $fresh->user_id)->toBe((int) $owner->id);
    expect($fresh->provider)->toBe('aliyun');

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/cloud-deploy/access/{$access->id}", ['provider' => 'tencent'])
        ->assertOk()
        ->assertJson(['code' => 0]);

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/cloud-deploy/access/{$access->id}", ['user_id' => $other->id])
        ->assertOk()
        ->assertJson(['code' => 0]);

    $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/cloud-deploy/access/{$access->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('admin target update/delete 保持 user access order 一致并支持改绑', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $accessA = CloudDeployAccess::create([
        'user_id' => $ownerA->id,
        'name' => 'A',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
    ]);
    $accessB = CloudDeployAccess::create([
        'user_id' => $ownerB->id,
        'name' => 'B',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'BK', 'access_key_secret' => 'BSK'],
    ]);
    $orderA = Order::factory()->create(['user_id' => $ownerA->id]);
    $certA = Cert::factory()->active()->create(['order_id' => $orderA->id, 'common_name' => 'a.example.com']);
    $orderA->update(['latest_cert_id' => $certA->id]);
    $orderB = Order::factory()->create(['user_id' => $ownerB->id]);
    $certB = Cert::factory()->active()->create(['order_id' => $orderB->id, 'common_name' => 'b.example.com']);
    $orderB->update(['latest_cert_id' => $certB->id]);
    $target = CloudDeployTarget::create([
        'user_id' => $ownerA->id,
        'access_id' => $accessA->id,
        'order_id' => $orderA->id,
        'product' => 'cdn',
        'config' => ['domain' => 'a.example.com'],
        'last_status' => 'success',
        'last_cert_id' => $certA->id,
        'last_deployed_at' => now(),
        'pending_job' => [
            'job_id' => 'job-123',
            'cert_id' => (int) $certA->id,
            'remote_cert_id' => 'cert-456',
            'expires_at' => now()->addDays(10)->timestamp,
        ],
    ]);

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/cloud-deploy/target/{$target->id}", [
            'access_id' => $accessB->id,
            'order_id' => $orderB->id,
            'config' => ['domain' => 'b.example.com'],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $fresh = CloudDeployTarget::withoutGlobalScopes()->find($target->id);
    expect((int) $fresh->user_id)->toBe((int) $ownerB->id);
    expect((int) $fresh->access_id)->toBe((int) $accessB->id);
    expect((int) $fresh->order_id)->toBe((int) $orderB->id);
    expect($fresh->last_status)->toBeNull();
    expect($fresh->pending_job)->toBeNull();

    $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/cloud-deploy/target/{$target->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(CloudDeployTarget::withoutGlobalScopes()->whereKey($target->id)->exists())->toBeFalse();
});

test('admin 仅切 enabled 保留 pending job', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'common_name' => 'admin-enabled.example.com']);
    $order->update(['latest_cert_id' => $cert->id]);
    $pending = [
        'job_id' => 'job-123',
        'cert_id' => (int) $cert->id,
        'remote_cert_id' => 'cert-456',
        'expires_at' => now()->addDays(10)->timestamp,
    ];
    $target = CloudDeployTarget::create([
        'user_id' => $owner->id,
        'access_id' => $access->id,
        'order_id' => $order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'admin-enabled.example.com'],
        'enabled' => true,
        'pending_job' => $pending,
    ]);

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/cloud-deploy/target/{$target->id}", ['enabled' => false])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $fresh = CloudDeployTarget::withoutGlobalScopes()->findOrFail($target->id);
    expect($fresh->enabled)->toBeFalse();
    expect($fresh->pending_job)->toBe($pending);
});

test('pending job 不出现在 admin 目标详情和列表响应', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'common_name' => 'admin-hidden.example.com']);
    $order->update(['latest_cert_id' => $cert->id]);
    $target = CloudDeployTarget::create([
        'user_id' => $owner->id,
        'access_id' => $access->id,
        'order_id' => $order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'admin-hidden.example.com'],
        'pending_job' => [
            'job_id' => 'secret-job-id',
            'cert_id' => (int) $cert->id,
            'remote_cert_id' => 'secret-cert-id',
            'expires_at' => now()->addDays(10)->timestamp,
        ],
    ]);

    $show = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/target/{$target->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.pending_job');
    $list = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/target')
        ->assertOk()
        ->assertJsonMissingPath('data.items.0.pending_job');

    expect(json_encode([$show->json(), $list->json()]))->not->toContain('secret-job-id');
});

test('admin 新增和改绑 target 时拒绝非候选订单', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'owner aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK'],
    ]);

    $expired = Cert::factory()->expired()->create();
    $deadOrder = Order::factory()->create(['user_id' => $owner->id, 'latest_cert_id' => $expired->id]);
    $expired->update(['order_id' => $deadOrder->id]);

    $activeCert = Cert::factory()->active()->create();
    $activeOrder = Order::factory()->create(['user_id' => $owner->id, 'latest_cert_id' => $activeCert->id]);
    $activeCert->update(['order_id' => $activeOrder->id]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $deadOrder->id,
            'product' => 'cdn',
            'config' => ['domain' => 'dead.example.com'],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    $target = CloudDeployTarget::create([
        'user_id' => $owner->id,
        'access_id' => $access->id,
        'order_id' => $activeOrder->id,
        'product' => 'cdn',
        'config' => ['domain' => 'active.example.com'],
    ]);

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/cloud-deploy/target/{$target->id}", ['order_id' => $deadOrder->id])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('admin 新增部署目标时拒绝跨用户混绑凭证和订单', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $other->id,
        'name' => 'aliyun-other',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'cdn.example.com'],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});

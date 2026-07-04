<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->payload = [
        'name' => '我的阿里云',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456'],
    ];
});

test('创建凭证：落库密文、响应不回显 credentials', function () {
    $res = $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', $this->payload)
        ->assertOk()
        ->assertJson(['code' => 1]);

    $access = CloudDeployAccess::withoutGlobalScopes()->where('user_id', $this->user->id)->first();
    expect($access)->not->toBeNull();

    $raw = DB::table('cloud_deploy_accesses')->where('id', $access->id)->value('credentials');
    expect($raw)->not->toContain('AK123')->not->toContain('SECRET456');
    expect(json_encode($res->json()))->not->toContain('SECRET456'); // 响应不回显明文（success() 无 data 键，对整体 JSON 断言）
});

test('credentials 缺 schema required 字段被拒（aliyun 缺 access_key_secret）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'X', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK'], // 缺 access_key_secret
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('credentials required 字段为空串被拒（与 deployer requireConfig 同口径）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'X', 'provider' => 'tencent',
            'credentials' => ['secret_id' => 'SID', 'secret_key' => ''],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('credentials 含 schema 外字段被拒（白名单校验，防额外字段静默落库）', function () {
    // 加固 2：credentials 的 key 集必须 ⊆ provider credentialSchema 的 key 集。
    // aliyun schema 仅 access_key_id / access_key_secret；多塞一个字段即拒绝。
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'X', 'provider' => 'aliyun',
            'credentials' => [
                'access_key_id' => 'AK', 'access_key_secret' => 'SK',
                'sts_token' => 'EXTRA', // schema 外字段
            ],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('更新换 provider 时按新 provider schema 校验 credentials', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]); // aliyun

    // 切到腾讯但只给 secret_id（缺 secret_key）→ 拒绝
    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/access/{$access->id}", [
            'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->find($access->id)->provider)->toBe('aliyun'); // 未改
});

test('列表不含 credentials', function () {
    CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access')->assertOk();
    $json = json_encode($res->json());
    expect($json)->not->toContain('SECRET456');
});

test('更新时 credentials 留空不覆盖原凭证', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/access/{$access->id}", ['name' => '改名了'])
        ->assertOk()->assertJson(['code' => 1]);

    $fresh = CloudDeployAccess::withoutGlobalScopes()->find($access->id);
    expect($fresh->name)->toBe('改名了');
    expect($fresh->credentials)->toBe(['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456']); // 未被清空
});

test('不能查看他人凭证', function () {
    $other = User::factory()->create();
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $other->id]);

    $this->actingAsUser($this->user)
        ->getJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 0]); // 查无（被 UserScope 挡）→ 业务失败
});

test('查看自己的凭证：响应不回显 credentials 明文', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $res = $this->actingAsUser($this->user)
        ->getJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 1]);

    // show 走 toArray()，唯一防泄露屏障是 $hidden——HTTP 层钉死它
    expect(json_encode($res->json()))->not->toContain('AK123')->not->toContain('SECRET456');
    expect($res->json('data.credentials'))->toBeNull();
});

test('删除被 target 引用的凭证应被拒绝（删除生命周期守卫）', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);
    $order = Order::factory()->create(['user_id' => $this->user->id]);
    CloudDeployTarget::create([
        'user_id' => $this->user->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => 'cdn.example.com'],
    ]);

    $this->actingAsUser($this->user)
        ->deleteJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->whereKey($access->id)->exists())->toBeTrue();
});

test('删除未被引用的凭证成功', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $this->actingAsUser($this->user)
        ->deleteJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 1]);

    expect(CloudDeployAccess::withoutGlobalScopes()->whereKey($access->id)->exists())->toBeFalse();
});

test('更新时显式传 credentials:null 也不覆盖原凭证', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/access/{$access->id}", ['credentials' => null])
        ->assertOk()->assertJson(['code' => 1]);

    $fresh = CloudDeployAccess::withoutGlobalScopes()->find($access->id);
    expect($fresh->credentials)->toBe(['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456']);
});

test('按 provider 过滤凭证列表', function () {
    CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]); // aliyun
    CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => '腾讯', 'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK']]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access?provider=tencent')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.provider'))->toBe('tencent');
});

test('按 name 模糊过滤凭证列表', function () {
    CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]); // name=我的阿里云
    CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => '生产腾讯云', 'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK']]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access?name='.urlencode('阿里'))->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.name'))->toBe('我的阿里云');
});

test('按 created_at 时间范围过滤凭证列表', function () {
    $old = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);
    $old->forceFill(['created_at' => '2020-01-01 00:00:00'])->saveQuietly();
    $new = CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => '新', 'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK']]);
    $new->forceFill(['created_at' => '2026-06-01 00:00:00'])->saveQuietly();

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access?created_at_start=2026-01-01 00:00:00&created_at_end=2026-12-31 23:59:59')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.name'))->toBe('新');
});

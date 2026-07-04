<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    // 目标 target 属于「另一个普通用户」——admin 须能跨用户推送
    $this->owner = User::factory()->create();
    $this->access = CloudDeployAccess::create(['user_id' => $this->owner->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $this->order = Order::factory()->create(['user_id' => $this->owner->id]);
    $this->cert = Cert::factory()->create(['order_id' => $this->order->id, 'status' => 'active']);
    $this->order->update(['latest_cert_id' => $this->cert->id]);
    $this->target = CloudDeployTarget::create(['user_id' => $this->owner->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);
});

test('admin 按 target_ids 跨用户手动推送（不受 UserScope 限制）', function () {
    Queue::fake();
    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/deploy', ['target_ids' => [$this->target->id], 'force' => true])
        ->assertOk()->assertJson(['code' => 1, 'data' => ['dispatched' => 1]]);

    Queue::assertPushed(CloudDeployJob::class, 1);
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $j) => $j->targetId === $this->target->id && $j->trigger === 'manual' && $j->force === true);
    // 生产 worker 仅监听 tasks,notifications：admin 推送也必须落 tasks
    Queue::assertPushedOn(config('queue.names.tasks'), CloudDeployJob::class);
});

test('admin 推送默认 force=true（缺省也绕幂等）', function () {
    Queue::fake();
    // 不传 force，控制器内默认 true
    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/deploy', ['target_ids' => [$this->target->id]])
        ->assertOk()->assertJson(['code' => 1]);

    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $j) => $j->force === true);
});

test('admin 推送 cert 非 active 的 target 时 dispatched=0、不入队', function () {
    Queue::fake();
    $order2 = Order::factory()->create(['user_id' => $this->owner->id]);
    $cert2 = Cert::factory()->create(['order_id' => $order2->id, 'status' => 'processing']);
    $order2->update(['latest_cert_id' => $cert2->id]);
    $t2 = CloudDeployTarget::create(['user_id' => $this->owner->id, 'access_id' => $this->access->id, 'order_id' => $order2->id, 'product' => 'cdn', 'config' => ['domain' => 'c'], 'enabled' => true]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/deploy', ['target_ids' => [$t2->id]])
        ->assertOk()->assertJson(['code' => 1, 'data' => ['dispatched' => 0]]);

    Queue::assertNothingPushed();
});

test('admin 推送不存在的 target_ids 返回 code=0', function () {
    Queue::fake();
    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/deploy', ['target_ids' => [999999]])
        ->assertOk()->assertJson(['code' => 0]); // DeployService 抛「没有可推送的目标」

    Queue::assertNothingPushed();
});

test('未认证管理员被拒（route 走 api.admin 中间件）', function () {
    Queue::fake();
    $this->postJson('/api/admin/cloud-deploy/deploy', ['target_ids' => [$this->target->id]])
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

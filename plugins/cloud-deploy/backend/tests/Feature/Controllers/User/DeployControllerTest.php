<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->access = CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $this->order = Order::factory()->create(['user_id' => $this->user->id]);
    $this->cert = Cert::factory()->create(['order_id' => $this->order->id, 'status' => 'active']);
    $this->order->update(['latest_cert_id' => $this->cert->id]);
    $this->target = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);
});

test('按 order_id 手动推送该订单的 enabled target', function () {
    Queue::fake();
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/deploy', ['order_id' => $this->order->id])
        ->assertOk()->assertJson(['code' => 1]);

    Queue::assertPushed(CloudDeployJob::class, 1);
    // 不变量②：手动推送也必须落 tasks 队列（生产 worker 仅监听 tasks,notifications）
    Queue::assertPushedOn(config('queue.names.tasks'), CloudDeployJob::class);
});

test('按 target_ids 手动推送', function () {
    Queue::fake();
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/deploy', ['target_ids' => [$this->target->id]])
        ->assertOk()->assertJson(['code' => 1]);

    Queue::assertPushed(CloudDeployJob::class, 1);
});

test('拒绝推送他人订单', function () {
    Queue::fake();
    $other = User::factory()->create();
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/deploy', ['order_id' => $otherOrder->id])
        ->assertOk()->assertJson(['code' => 0]);

    Queue::assertNothingPushed();
});

test('拒绝用 target_ids 推他人的 target（被 CloudDeployTarget UserScope 滤空）', function () {
    Queue::fake();
    $other = User::factory()->create();
    $otherAccess = CloudDeployAccess::create(['user_id' => $other->id, 'name' => 'o', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'A', 'access_key_secret' => 'S']]);
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);
    $otherTarget = CloudDeployTarget::create(['user_id' => $other->id, 'access_id' => $otherAccess->id, 'order_id' => $otherOrder->id, 'product' => 'cdn', 'config' => ['domain' => 'x'], 'enabled' => true]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/deploy', ['target_ids' => [$otherTarget->id]])
        ->assertOk()->assertJson(['code' => 0]); // UserScope 滤空 → '没有可推送的目标'

    Queue::assertNothingPushed();
});

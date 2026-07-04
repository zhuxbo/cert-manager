<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Jobs\CloudDeployTriggerJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->access = CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
});

test('同 order 的 enabled target 各 fan-out 一个 CloudDeployJob', function () {
    Queue::fake();
    $order = Order::factory()->create(['user_id' => $this->user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'action' => 'new']);
    $order->update(['latest_cert_id' => $cert->id]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $order->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'enabled' => false]);

    (new CloudDeployTriggerJob($cert->id))->handle();

    Queue::assertPushed(CloudDeployJob::class, 1); // 仅 enabled 那个
});

test('续费：迁移原订单所有 target 到新订单并推送', function () {
    Queue::fake();
    $oldOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $newOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $newCert = Cert::factory()->create(['order_id' => $newOrder->id, 'status' => 'active', 'action' => 'renew', 'last_cert_id' => $oldCert->id]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);
    // 目标原本绑在旧订单
    $target = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $oldOrder->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);

    (new CloudDeployTriggerJob($newCert->id))->handle();

    expect($target->fresh()->order_id)->toBe($newOrder->id); // 已迁移
    Queue::assertPushed(CloudDeployJob::class, 1);
});

test('已成功推过同证书的 target 被幂等跳过', function () {
    Queue::fake();
    $order = Order::factory()->create(['user_id' => $this->user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'action' => 'new']);
    $order->update(['latest_cert_id' => $cert->id]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true, 'last_cert_id' => $cert->id, 'last_status' => 'success']);

    (new CloudDeployTriggerJob($cert->id))->handle();

    Queue::assertNothingPushed();
});

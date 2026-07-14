<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

test('续费迁移纯上传目标时同步订单作用域哈希', function () {
    Queue::fake();
    $oldOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $newOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $newCert = Cert::factory()->create([
        'order_id' => $newOrder->id,
        'status' => 'active',
        'action' => 'renew',
        'last_cert_id' => $oldCert->id,
    ]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $oldOrder->id,
        'product' => 'cas',
        'config' => [],
        'config_hash' => CloudDeployTarget::scopedConfigHash([], $oldOrder->id),
        'enabled' => true,
    ]);

    (new CloudDeployTriggerJob($newCert->id))->handle();

    $fresh = $target->fresh();
    expect($fresh->order_id)->toBe($newOrder->id)
        ->and($fresh->config_hash)->toBe(CloudDeployTarget::scopedConfigHash([], $newOrder->id));
});

test('续费迁移遇到新订单已有同一纯上传目标时停用旧目标', function () {
    Queue::fake();
    $oldOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $newOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $newCert = Cert::factory()->create([
        'order_id' => $newOrder->id,
        'status' => 'active',
        'action' => 'renew',
        'last_cert_id' => $oldCert->id,
    ]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);
    $oldTarget = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $oldOrder->id,
        'product' => 'cas',
        'config' => [],
        'config_hash' => CloudDeployTarget::scopedConfigHash([], $oldOrder->id),
        'enabled' => true,
    ]);
    $newTarget = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $newOrder->id,
        'product' => 'cas',
        'config' => [],
        'config_hash' => CloudDeployTarget::scopedConfigHash([], $newOrder->id),
        'enabled' => true,
    ]);

    (new CloudDeployTriggerJob($newCert->id))->handle();

    expect($oldTarget->fresh()->order_id)->toBe($oldOrder->id)
        ->and($oldTarget->fresh()->enabled)->toBeFalse()
        ->and($newTarget->fresh()->order_id)->toBe($newOrder->id);
    Queue::assertPushed(CloudDeployJob::class, 1);
});

test('续费迁移兼容新订单存量配置哈希并停用旧目标', function () {
    Queue::fake();
    $oldOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $newOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $newCert = Cert::factory()->create([
        'order_id' => $newOrder->id,
        'status' => 'active',
        'action' => 'renew',
        'last_cert_id' => $oldCert->id,
    ]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);
    $oldTarget = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $oldOrder->id,
        'product' => 'cas',
        'config' => [],
        'config_hash' => CloudDeployTarget::scopedConfigHash([], $oldOrder->id),
        'enabled' => true,
    ]);
    $legacyTarget = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $newOrder->id,
        'product' => 'cas',
        'config' => [],
        'config_hash' => CloudDeployTarget::configHash([]),
        'enabled' => true,
    ]);

    (new CloudDeployTriggerJob($newCert->id))->handle();

    expect($oldTarget->fresh()->order_id)->toBe($oldOrder->id)
        ->and($oldTarget->fresh()->enabled)->toBeFalse()
        ->and($legacyTarget->fresh()->order_id)->toBe($newOrder->id)
        ->and(CloudDeployTarget::withoutGlobalScopes()->where('order_id', $newOrder->id)->count())->toBe(1);
    Queue::assertPushed(CloudDeployJob::class, 1);
});

test('G1 迁移跨用户守卫：prevOrder 混入他用户脏 target → 只迁同 user', function () {
    Queue::fake();
    $userB = User::factory()->create();
    $accessB = CloudDeployAccess::create(['user_id' => $userB->id, 'name' => 'b', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);

    $oldOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $newOrder = Order::factory()->create(['user_id' => $this->user->id]);
    $newCert = Cert::factory()->create(['order_id' => $newOrder->id, 'status' => 'active', 'action' => 'renew', 'last_cert_id' => $oldCert->id]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);

    // 同 user target（应迁移）
    $tSame = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $oldOrder->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);
    // 脏 target：绑在旧订单但 user_id=B（越权数据，不应迁移）
    $tDirty = CloudDeployTarget::create(['user_id' => $userB->id, 'access_id' => $accessB->id, 'order_id' => $oldOrder->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'enabled' => true]);

    (new CloudDeployTriggerJob($newCert->id))->handle();

    expect($tSame->fresh()->order_id)->toBe($newOrder->id);  // 同 user 迁移
    expect($tDirty->fresh()->order_id)->toBe($oldOrder->id); // 跨 user 未迁移（越权守卫）
});

test('G1 failed() 记录 Log::error（续费迁移编排失败）', function () {
    $captured = [];
    Log::shouldReceive('error')->andReturnUsing(function (...$args) use (&$captured) {
        $captured[] = $args;
    });

    (new CloudDeployTriggerJob(123))->failed(new RuntimeException('boom'));

    expect($captured)->not->toBeEmpty();
    expect($captured[0][0])->toContain('trigger.failed');
    expect($captured[0][1]['cert'])->toBe(123);
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

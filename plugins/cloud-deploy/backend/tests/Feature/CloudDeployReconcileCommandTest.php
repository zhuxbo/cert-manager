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

function reconcileSeed(array $targetOverrides = []): array
{
    $user = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['k' => 'v']]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active']);
    $order->update(['latest_cert_id' => $cert->id]);
    $target = CloudDeployTarget::create(array_merge([
        'user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true,
    ], $targetOverrides));

    return [$target, $cert];
}

test('条件 A 漏推（last_cert_id != latest）→ dispatch', function () {
    Queue::fake();
    [$target, $cert] = reconcileSeed(['last_cert_id' => null]); // 从未推过当前证书

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertPushed(CloudDeployJob::class, fn ($job) => $job->targetId === $target->id && $job->certId === $cert->id);
});

test('已成功推过当前证书（last_cert_id==latest, success）→ 不 dispatch', function () {
    Queue::fake();
    [$target, $cert] = reconcileSeed(['last_cert_id' => null]);
    $target->update(['last_cert_id' => $cert->id, 'last_status' => 'success']);

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertNothingPushed(); // 既不漏推（A 不命中）又非失败超 7 天（B 不命中）
});

test('同证书失败但 7 天内 → 不 dispatch（防每天重扫）', function () {
    Queue::fake();
    [$target, $cert] = reconcileSeed(['last_cert_id' => null]);
    $target->update(['last_cert_id' => $cert->id, 'last_status' => 'failed', 'last_deployed_at' => now()->subDay()]);

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('条件 B 同证书失败且超 7 天 → dispatch（节流补偿）', function () {
    Queue::fake();
    [$target, $cert] = reconcileSeed(['last_cert_id' => null]);
    $target->update(['last_cert_id' => $cert->id, 'last_status' => 'failed', 'last_deployed_at' => now()->subDays(8)]);

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertPushed(CloudDeployJob::class, 1);
});

/** 造续费链：旧订单 renewed cert + 新订单 active renew cert（last_cert_id=旧），target 仍锚旧订单（未迁移）。 */
function renewChainSeed(int $targetUserId, int $newChainUserId): array
{
    $targetUser = User::find($targetUserId);
    $access = CloudDeployAccess::create(['user_id' => $targetUser->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['k' => 'v']]);
    $oldOrder = Order::factory()->create(['user_id' => $targetUser->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $oldOrder->update(['latest_cert_id' => $oldCert->id]);

    $newOrder = Order::factory()->create(['user_id' => $newChainUserId]);
    $newCert = Cert::factory()->create(['order_id' => $newOrder->id, 'status' => 'active', 'action' => 'renew', 'last_cert_id' => $oldCert->id]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);

    // target 未迁移仍锚旧订单（last_cert_id=oldCert success：A/B 均不命中，仅续费链 fallback 拾起）
    $target = CloudDeployTarget::create([
        'user_id' => $targetUser->id, 'access_id' => $access->id, 'order_id' => $oldOrder->id,
        'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true,
        'last_cert_id' => $oldCert->id, 'last_status' => 'success',
    ]);

    return [$target, $newCert];
}

test('C 续费链 fallback：未迁移 target → 派 CloudDeployTriggerJob（含错峰 delay），不派 CloudDeployJob', function () {
    Queue::fake();
    $user = User::factory()->create();
    [, $newCert] = renewChainSeed($user->id, $user->id);

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertPushed(CloudDeployTriggerJob::class, fn ($job) => $job->certId === $newCert->id && $job->delay !== null);
    Queue::assertNotPushed(CloudDeployJob::class); // A/B 被 join active 挡死，仅 fallback 派 TriggerJob
});

test('C 续费链 fallback 跨用户负例：新链属他用户 → 不派（越权守卫 o_new.user_id != target.user_id）', function () {
    Queue::fake();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    // target 属 A、旧订单属 A，但续费链的新证书/订单属 B（脏 last_cert_id 链）
    renewChainSeed($userA->id, $userB->id);

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertNothingPushed(); // 越权守卫拦截，A/B 亦不命中
});

test('disabled / 非 active cert → 不扫', function () {
    Queue::fake();
    reconcileSeed(['enabled' => false, 'last_cert_id' => null]);
    // 非 active：另造一个 pending cert 的 target
    $u = User::factory()->create();
    $acc = CloudDeployAccess::create(['user_id' => $u->id, 'name' => 'b', 'provider' => 'aliyun', 'credentials' => ['k' => 'v']]);
    $o = Order::factory()->create(['user_id' => $u->id]);
    $c = Cert::factory()->create(['order_id' => $o->id, 'status' => 'pending']);
    $o->update(['latest_cert_id' => $c->id]);
    CloudDeployTarget::create(['user_id' => $u->id, 'access_id' => $acc->id, 'order_id' => $o->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'enabled' => true, 'last_cert_id' => null]);

    $this->artisan('cloud-deploy:reconcile')->assertExitCode(0);

    Queue::assertNothingPushed();
});

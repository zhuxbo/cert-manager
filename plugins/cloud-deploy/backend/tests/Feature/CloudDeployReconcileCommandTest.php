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

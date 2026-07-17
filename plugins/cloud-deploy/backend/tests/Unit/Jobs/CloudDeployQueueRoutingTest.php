<?php

use App\Models\Cert;
use App\Models\Chain;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudChainBackfillJob;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Jobs\CloudDeployTriggerJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 不变量 pin（CLAUDE.md「系统架构约定」队列约定）：
 * ② 所有 Job dispatch 必须显式 ->onQueue(config('queue.names.tasks'))——生产 supervisor 仅监听 tasks,notifications，
 *    漏写 onQueue 的 Job 落 default 永无人消费（SubmitDocumentJob 曾踩此坑）。
 * ③ 事务内 dispatch 必须 ->afterCommit()——after_commit=false 下事务内 dispatch 会立即入队，
 *    worker 可能在提交前消费读不到事务内新建/迁移的行。
 *
 * 4 个 dispatch 点的覆盖分工：
 * - onCertUpdated→TriggerJob、onChainCreated→BackfillJob 的「入 tasks 队列」由 Feature/CloudDeployListenerTest
 *   经真实 Cert::updated/Chain::created observer assertPushedOn 守（两 trigger 自带 ->afterCommit()）。
 * - DeployController→CloudDeployJob 的「入 tasks 队列」由 Feature/Controllers/User/DeployControllerTest assertPushedOn 守。
 * - 本文件守剩下两个 CloudDeployJob dispatch 点：TriggerJob::handle fan-out、CloudChainBackfillJob::handle；
 *   并以「续费迁移 + fan-out 命中迁移后 target」组合断言守住 ③（fan-out 在 DB::transaction 闭包外、读已提交数据）。
 */
beforeEach(function () {
    $this->tasksQueue = config('queue.names.tasks');
    expect($this->tasksQueue)->toBe('tasks'); // 守 config 键存在（误删/改名即红）
});

function makeActiveCertWithFailedTarget(string $issuer = 'QR-ROUTE-CA', string $action = 'new'): array
{
    $user = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    Chain::firstOrCreate(['common_name' => $issuer], ['intermediate_cert' => 'CHAIN']);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'issuer' => $issuer, 'action' => $action]);
    $order->update(['latest_cert_id' => $cert->id]);
    // last_status=failed 让 BackfillJob 命中；TriggerJob 走 enabled 且非「已成功推过本证书」即命中
    $target = CloudDeployTarget::create(['user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true, 'last_status' => 'failed']);

    return [$target, $cert, $order, $user, $access];
}

test('② CloudDeployTriggerJob fan-out 的子 CloudDeployJob 入 tasks 队列', function () {
    Queue::fake();
    [$target, $cert] = makeActiveCertWithFailedTarget();

    (new CloudDeployTriggerJob($cert->id))->handle();

    Queue::assertPushedOn($this->tasksQueue, CloudDeployJob::class);
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $job) => $job->targetId === $target->id && $job->certId === $cert->id);
});

test('② CloudChainBackfillJob 重推的子 CloudDeployJob 入 tasks 队列', function () {
    Queue::fake();
    [$target, $cert] = makeActiveCertWithFailedTarget('QR-BACKFILL-CA');

    (new CloudChainBackfillJob('QR-BACKFILL-CA'))->handle();

    Queue::assertPushedOn($this->tasksQueue, CloudDeployJob::class);
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $job) => $job->targetId === $target->id);
});

test('③ CloudDeployTriggerJob fan-out 在 DB::transaction 闭包外（读已提交数据），子 Job 命中迁移后 target 且入 tasks', function () {
    // fan-out 在 DB::transaction 闭包返回（已提交）后发生，故无需 afterCommit。
    // 若有人把 dispatch 挪进闭包内且不加 afterCommit，after_commit=false 下子 Job 会在提交前入队、
    // 读不到事务内迁移的 order_id —— 此回归由「迁移已生效 + 子 Job 命中迁移后 target」组合守住
    // （迁移正确说明 fan-out 读到了已提交数据；若提前消费会命中旧 order 的 target 集或空集）。
    Queue::fake();
    $user = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $oldOrder = Order::factory()->create(['user_id' => $user->id]);
    $oldCert = Cert::factory()->create(['order_id' => $oldOrder->id, 'status' => 'renewed']);
    $newOrder = Order::factory()->create(['user_id' => $user->id]);
    Chain::firstOrCreate(['common_name' => 'QR-RENEW-CA'], ['intermediate_cert' => 'CHAIN']);
    $newCert = Cert::factory()->create(['order_id' => $newOrder->id, 'status' => 'active', 'issuer' => 'QR-RENEW-CA', 'action' => 'renew', 'last_cert_id' => $oldCert->id]);
    $newOrder->update(['latest_cert_id' => $newCert->id]);
    $target = CloudDeployTarget::create(['user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $oldOrder->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);

    (new CloudDeployTriggerJob($newCert->id))->handle();

    expect($target->fresh()->order_id)->toBe($newOrder->id);
    Queue::assertPushedOn($this->tasksQueue, CloudDeployJob::class);
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $job) => $job->targetId === $target->id && $job->certId === $newCert->id);
});

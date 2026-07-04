<?php

use App\Models\Cert;
use App\Models\Chain;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudChainBackfillJob;
use Plugins\CloudDeploy\Jobs\CloudDeployTriggerJob;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('证书变 active 分派 TriggerJob 到 tasks 队列', function () {
    Queue::fake();
    $order = Order::factory()->create(['user_id' => User::factory()->create()->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'approving']);

    DB::transaction(fn () => $cert->update(['status' => 'active']));

    Queue::assertPushed(CloudDeployTriggerJob::class, 1);
    // 不变量②：onCertUpdated dispatch 必须落 tasks 队列（生产 worker 仅监听 tasks,notifications）
    Queue::assertPushedOn(config('queue.names.tasks'), CloudDeployTriggerJob::class);
});

test('证书更新但状态未变 active 不触发', function () {
    Queue::fake();
    // 配 issuer + Chain 使 cert 真正稳定 active（否则 retrieved 在缺链时把内存 status 降级 approving，测试变脆）
    Chain::firstOrCreate(['common_name' => 'TEST-R3-CA'], ['intermediate_cert' => 'PEM']);
    $order = Order::factory()->create(['user_id' => User::factory()->create()->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'issuer' => 'TEST-R3-CA']);
    $cert = Cert::find($cert->id); // 经 retrieved 确认稳定 active（有 Chain 不被降级）

    DB::transaction(fn () => $cert->update(['common_name' => 'x.example.com']));

    Queue::assertNotPushed(CloudDeployTriggerJob::class);
});

test('新建 Chain 分派 BackfillJob', function () {
    Queue::fake();

    DB::transaction(fn () => Chain::create(['common_name' => 'R3-CA', 'intermediate_cert' => 'PEM']));

    Queue::assertPushed(CloudChainBackfillJob::class, 1);
    // 不变量②：onChainCreated dispatch 必须落 tasks 队列
    Queue::assertPushedOn(config('queue.names.tasks'), CloudChainBackfillJob::class);
});

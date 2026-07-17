<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudChainBackfillJob;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('补链后重推该 issuer 下缺链失败的 target', function () {
    Queue::fake();
    $user = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'issuer' => 'R3-CA']);
    $order->update(['latest_cert_id' => $cert->id]);
    CloudDeployTarget::create(['user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true, 'last_status' => 'failed']);

    (new CloudChainBackfillJob('R3-CA'))->handle();

    Queue::assertPushed(CloudDeployJob::class, 1);
});

test('其它 issuer 的失败 target 不被触发', function () {
    Queue::fake();
    $user = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'status' => 'active', 'issuer' => 'OTHER-CA']);
    $order->update(['latest_cert_id' => $cert->id]);
    CloudDeployTarget::create(['user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true, 'last_status' => 'failed']);

    (new CloudChainBackfillJob('R3-CA'))->handle();

    Queue::assertNothingPushed();
});

test('G6 failed() 记录 Log::error（缺链补推编排耗尽）', function () {
    $captured = [];
    Log::shouldReceive('error')->andReturnUsing(function (...$args) use (&$captured) {
        $captured[] = $args;
    });

    (new CloudChainBackfillJob('R3-CA'))->failed(new RuntimeException('boom'));

    expect($captured)->not->toBeEmpty();
    expect($captured[0][0])->toContain('chain-backfill.failed');
    expect($captured[0][1]['issuer'])->toBe('R3-CA');
});

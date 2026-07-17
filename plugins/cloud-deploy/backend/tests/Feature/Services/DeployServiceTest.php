<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Scopes\UserScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Services\DeployService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->access = CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SK']]);
    $this->order = Order::factory()->create(['user_id' => $this->user->id]);
    $this->cert = Cert::factory()->create(['order_id' => $this->order->id, 'status' => 'active']);
    $this->order->update(['latest_cert_id' => $this->cert->id]);
});

test('order_id 模式只推该订单 enabled target，返回 dispatched 计数', function () {
    Queue::fake();
    $on = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => true]);
    CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'b'], 'enabled' => false]);

    // crossUser=false：靠当前进程注册的 CloudDeployTarget + Order UserScope 收敛本人
    CloudDeployTarget::addGlobalScope(new UserScope($this->user->id));
    Order::addGlobalScope(new UserScope($this->user->id));

    $dispatched = app(DeployService::class)->deploy($this->order->id, [], false, false);

    expect($dispatched)->toBe(1); // 只有 enabled=true 的 $on 被推
    Queue::assertPushed(CloudDeployJob::class, 1);
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $j) => $j->targetId === $on->id && $j->certId === $this->cert->id && $j->trigger === 'manual' && $j->force === false);
    Queue::assertPushedOn(config('queue.names.tasks'), CloudDeployJob::class);
});

test('target_ids 模式不过滤 enabled，force 透传', function () {
    Queue::fake();
    $disabled = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $this->order->id, 'product' => 'cdn', 'config' => ['domain' => 'a'], 'enabled' => false]);

    CloudDeployTarget::addGlobalScope(new UserScope($this->user->id));

    $dispatched = app(DeployService::class)->deploy(null, [$disabled->id], true, false);

    expect($dispatched)->toBe(1); // enabled=false 也推（显式选 target）
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $j) => $j->targetId === $disabled->id && $j->force === true);
});

test('cert 非 active 的 target 跳过、不 dispatch', function () {
    Queue::fake();
    $order2 = Order::factory()->create(['user_id' => $this->user->id]);
    $cert2 = Cert::factory()->create(['order_id' => $order2->id, 'status' => 'processing']);
    $order2->update(['latest_cert_id' => $cert2->id]);
    $t2 = CloudDeployTarget::create(['user_id' => $this->user->id, 'access_id' => $this->access->id, 'order_id' => $order2->id, 'product' => 'cdn', 'config' => ['domain' => 'c'], 'enabled' => true]);

    CloudDeployTarget::addGlobalScope(new UserScope($this->user->id));

    $dispatched = app(DeployService::class)->deploy(null, [$t2->id], false, false);

    expect($dispatched)->toBe(0); // certs.status=processing → continue
    Queue::assertNothingPushed();
});

test('解析不到任何 target 时抛 ApiResponseException(code=0)', function () {
    Queue::fake();
    CloudDeployTarget::addGlobalScope(new UserScope($this->user->id));

    // ApiResponseException 的 msg 存在 getApiResponse()['msg']，不在 getMessage()，用 closure 形式断言
    expect(fn () => app(DeployService::class)->deploy(null, [999999], false, false))
        ->toThrow(function (ApiResponseException $e) {
            expect($e->getApiResponse()['msg'])->toContain('没有可推送的目标');
            expect($e->getApiResponse()['code'])->toBe(0);
        });
    Queue::assertNothingPushed();
});

test('crossUser=false 时 order_id 归属预检失败抛 订单不存在', function () {
    Queue::fake();
    $other = User::factory()->create();
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);

    // 进程注册本人 Order UserScope，他人 order exists() 返回 false
    Order::addGlobalScope(new UserScope($this->user->id));
    CloudDeployTarget::addGlobalScope(new UserScope($this->user->id));

    // ApiResponseException 的 msg 存在 getApiResponse()['msg']，不在 getMessage()，用 closure 形式断言
    expect(fn () => app(DeployService::class)->deploy($otherOrder->id, [], false, false))
        ->toThrow(function (ApiResponseException $e) {
            expect($e->getApiResponse()['msg'])->toContain('订单不存在');
            expect($e->getApiResponse()['code'])->toBe(0);
        });
    Queue::assertNothingPushed();
});

test('crossUser=true 用 withoutGlobalScopes 直查、可推他人 target（admin 入口语义）', function () {
    Queue::fake();
    $other = User::factory()->create();
    $otherAccess = CloudDeployAccess::create(['user_id' => $other->id, 'name' => 'o', 'provider' => 'aliyun', 'credentials' => ['access_key_id' => 'A', 'access_key_secret' => 'S']]);
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);
    $otherCert = Cert::factory()->create(['order_id' => $otherOrder->id, 'status' => 'active']);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);
    $otherTarget = CloudDeployTarget::create(['user_id' => $other->id, 'access_id' => $otherAccess->id, 'order_id' => $otherOrder->id, 'product' => 'cdn', 'config' => ['domain' => 'x'], 'enabled' => true]);

    // 即便进程注册了「当前 user」UserScope，crossUser=true 也须用 withoutGlobalScopes 越过它
    CloudDeployTarget::addGlobalScope(new UserScope($this->user->id));

    $dispatched = app(DeployService::class)->deploy(null, [$otherTarget->id], true, true);

    expect($dispatched)->toBe(1); // withoutGlobalScopes 直查到他人 target 并推送
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $j) => $j->targetId === $otherTarget->id);
});

test('crossUser=true 可用 order_id 和 user_id 推该订单 enabled target', function () {
    Queue::fake();
    $enabled = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'enabled.example.com'],
        'enabled' => true,
    ]);
    CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $this->access->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'disabled.example.com'],
        'enabled' => false,
    ]);
    $other = User::factory()->create();
    $otherAccess = CloudDeployAccess::create([
        'user_id' => $other->id,
        'name' => 'other',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'A', 'access_key_secret' => 'S'],
    ]);
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);
    $otherCert = Cert::factory()->create(['order_id' => $otherOrder->id, 'status' => 'active']);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);
    CloudDeployTarget::create([
        'user_id' => $other->id,
        'access_id' => $otherAccess->id,
        'order_id' => $otherOrder->id,
        'product' => 'cdn',
        'config' => ['domain' => 'other.example.com'],
        'enabled' => true,
    ]);

    $dispatched = app(DeployService::class)->deploy($this->order->id, [], true, true, $this->user->id);

    expect($dispatched)->toBe(1);
    Queue::assertPushed(CloudDeployJob::class, 1);
    Queue::assertPushed(CloudDeployJob::class, fn (CloudDeployJob $j) => $j->targetId === $enabled->id && $j->force === true);
});

test('入队前过滤租户错配 target 且 dispatched 不虚增', function () {
    Queue::fake();
    $other = User::factory()->create();
    $otherAccess = CloudDeployAccess::create([
        'user_id' => $other->id,
        'name' => 'other',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'A', 'access_key_secret' => 'S'],
    ]);
    $target = CloudDeployTarget::create([
        'user_id' => $this->user->id,
        'access_id' => $otherAccess->id,
        'order_id' => $this->order->id,
        'product' => 'cdn',
        'config' => ['domain' => 'x'],
        'enabled' => true,
    ]);

    $dispatched = app(DeployService::class)->deploy(null, [$target->id], true, true);

    expect($dispatched)->toBe(0);
    Queue::assertNothingPushed();
});

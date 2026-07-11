<?php

use App\Jobs\TaskJob;
use App\Models\Acme;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取任务列表', function () {
    Task::factory()->count(3)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/task');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按操作类型筛选任务', function () {
    Task::factory()->create(['action' => 'new']);
    Task::factory()->reissue()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/task?action=reissue');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按状态筛选任务', function () {
    Task::factory()->create(['status' => 'executing']);
    Task::factory()->failed()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/task?status=failed');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按订单ID筛选任务', function () {
    $task = Task::factory()->create();
    Task::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/task?order_id=$task->order_id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看任务详情', function () {
    $task = Task::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/task/$task->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $task->id);
});

test('查看不存在的任务返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/task/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以删除任务', function () {
    $task = Task::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/task/$task->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Task::find($task->id))->toBeNull();
});

test('管理员可以批量删除任务', function () {
    $tasks = Task::factory()->count(3)->create();
    $ids = $tasks->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/task/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Task::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量启动已停止的任务', function () {
    Queue::fake();

    $tasks = Task::factory()->count(2)->create(['status' => 'stopped']);
    $ids = $tasks->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-start', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    foreach ($tasks as $task) {
        $task->refresh();
        expect($task->status)->toBe('executing');
    }
});

test('T3：batchStart 恢复 cancel 任务补 delay（≈started_at+3s，消队头 no-op）', function () {
    Queue::fake();
    $task = Task::factory()->create(['status' => 'stopped', 'action' => 'cancel']);
    $before = now();

    $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-start', ['ids' => [$task->id]])
        ->assertOk()->assertJson(['code' => 1]);

    // started_at 置 now+120（cancel 类），delay 跟随 started_at + 3s = now+123
    $task->refresh();
    expect($task->started_at->gt($before))->toBeTrue();
    Queue::assertPushed(TaskJob::class, function (TaskJob $job) use ($before) {
        expect($job->delay)->toBeInstanceOf(Carbon::class);
        if (! $job->delay instanceof Carbon) {
            return false;
        }
        // delay ≈ now+123（started_at now+120 + 3s 缓冲）；容忍执行耗时抖动
        $expected = $before->copy()->addSeconds(123);
        expect(abs($job->delay->diffInSeconds($expected)))->toBeLessThanOrEqual(3);

        return true;
    });
});

test('T3：batchStart 恢复 cancel_acme 任务补 delay', function () {
    Queue::fake();
    $task = Task::factory()->create(['status' => 'stopped', 'action' => 'cancel_acme']);

    $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-start', ['ids' => [$task->id]])
        ->assertOk()->assertJson(['code' => 1]);

    Queue::assertPushed(TaskJob::class, fn (TaskJob $job) => $job->delay instanceof Carbon);
});

test('T3：batchStart 恢复非取消类任务无 delay（现行为回归）', function () {
    Queue::fake();
    $task = Task::factory()->create(['status' => 'stopped', 'action' => 'commit']);

    $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-start', ['ids' => [$task->id]])
        ->assertOk()->assertJson(['code' => 1]);

    Queue::assertPushed(TaskJob::class, fn (TaskJob $job) => $job->delay === null);
});

test('管理员可以批量停止执行中的任务', function () {
    $tasks = Task::factory()->count(2)->create(['status' => 'executing']);
    $ids = $tasks->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-stop', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    foreach ($tasks as $task) {
        $task->refresh();
        expect($task->status)->toBe('stopped');
    }
});

test('停止非执行中的任务返回错误', function () {
    $tasks = Task::factory()->count(2)->create(['status' => 'successful']);
    $ids = $tasks->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-stop', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('batchExecute 路由 cancel_acme 到 AcmeAction', function () {
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->create([
        'product_id' => $product->id,
        'status' => Acme::STATUS_CANCELLING,
        'api_id' => 'upstream-batch',
    ]);
    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'cancel_acme',
        'status' => 'executing',
    ]);

    // 配置 gateway + fake 上游返回 cancelled
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => 'CA', 'weight' => 2]);
    foreach (['url' => 'https://fake-gateway.test/api/v2', 'token' => 'x'] as $k => $v) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $k],
            ['type' => 'string', 'value' => $v, 'weight' => 0]
        );
    }
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']]),
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-execute', [
        'ids' => [$task->id],
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $task->refresh();
    expect($task->status)->toBe('successful');
});

test('batchExecute 路由 commit_acme 到 AcmeAction', function () {
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->create([
        'product_id' => $product->id,
        'status' => Acme::STATUS_PENDING,
    ]);
    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'commit_acme',
        'status' => 'executing',
    ]);

    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => 'CA', 'weight' => 2]);
    foreach (['url' => 'https://fake-gateway.test/api/v2', 'token' => 'x'] as $k => $v) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $k],
            ['type' => 'string', 'value' => $v, 'weight' => 0]
        );
    }
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => [
            'order_id' => 'X1', 'eab_kid' => 'K1', 'eab_hmac' => 'H1', 'directory_url' => 'https://g',
        ]]),
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-execute', [
        'ids' => [$task->id],
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $task->refresh();
    expect($task->status)->toBe('successful');
});

test('batchExecute 路由 sync_acme 到 AcmeAction', function () {
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->create([
        'product_id' => $product->id,
        'status' => Acme::STATUS_ACTIVE,
        'api_id' => 'upstream-sync',
    ]);
    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'sync_acme',
        'status' => 'executing',
    ]);

    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => 'CA', 'weight' => 2]);
    foreach (['url' => 'https://fake-gateway.test/api/v2', 'token' => 'x'] as $k => $v) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $k],
            ['type' => 'string', 'value' => $v, 'weight' => 0]
        );
    }
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/task/batch-execute', [
        'ids' => [$task->id],
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $task->refresh();
    expect($task->status)->toBe('successful');
});

test('管理员可以分页获取任务列表', function () {
    Task::factory()->count(15)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/task?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问任务管理', function () {
    $response = $this->getJson('/api/admin/task');

    $response->assertUnauthorized();
});

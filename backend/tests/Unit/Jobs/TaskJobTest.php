<?php

use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Acme;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Api\Api;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

/**
 * TaskJob::handle 是 commit/cancel/sync（含 _acme）所有延时与异步任务的统一入口，
 * 承载多项并发与资金/状态约定。现有测试只覆盖了 commit_acme/sync_acme 成功主路径
 * （tests/Unit/Services/Acme/ActionTest.php）+ CorrelationId 守卫
 * （tests/Feature/Services/CorrelationIdTest.php）。
 *
 * 本文件补齐点名的高风险分支：
 * - task 不存在 / 非 executing / started_at 未到 → 静默跳过（不执行 action）
 * - order 与 acme 两类 action 正确分发
 * - ApiResponseException(code=0) → 任务标 failed 且落库
 * - 内层任意 Throwable（含「方法不存在」RuntimeException）→ 任务标 failed + 触发 fail()
 * - failed() 钩子构造 task_failed NotificationIntent 派发到 NotificationCenter（含早返回守卫）
 */
uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    // sync 有 10s Cache dedup；清缓存避免跨用例串扰
    Cache::flush();
});

/**
 * 配置 gateway 系统设置（ACME SDK 通过回落机制使用 ca.url/token）
 */
function taskJobSetupGateway(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => 'CA', 'weight' => 2]);
    foreach (['url' => 'https://fake-gateway.test/api/v2', 'token' => 'x'] as $k => $v) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $k],
            ['type' => 'string', 'value' => $v, 'weight' => 0]
        );
    }
}

// ==================== 锁内守卫：静默跳过 ====================

test('task 不存在时静默跳过，不执行 action 也不抛异常', function () {
    // id 指向不存在的 task —— lockForUpdate 直接 first() 返回 null
    $job = new TaskJob(['id' => 999999]);

    $job->handle();

    // 没有任何 task 被创建/更新，无异常即通过
    expect(Task::count())->toBe(0);
});

test('data 缺失 id 时按 id=0 处理，静默跳过', function () {
    $job = new TaskJob([]);

    $job->handle();

    expect(Task::count())->toBe(0);
});

test('task 非 executing 状态时静默跳过，action 不执行', function () {
    // stopped 状态的 cancel_acme：若被执行会去调上游；这里不配置 Http::fake，
    // 一旦误执行 Action 会因缺 gateway 配置/上游调用而留下痕迹（状态变化）
    $task = Task::factory()->create([
        'action' => 'commit_acme',
        'status' => 'stopped',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    // 状态保持 stopped，未被改成 successful/failed，attempts 未自增
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('stopped');
    expect($fresh->attempts)->toBe(0);
    expect($fresh->last_execute_at)->toBeNull();
});

test('started_at 在未来时静默跳过（延时取消未到点不提前执行）', function () {
    // 杀手场景：cancel_acme 延时 123s，撤回取消后任务被删；即便未删，
    // started_at 未到也绝不能提前执行，否则会调上游 cancel + 退费
    $task = Task::factory()->create([
        'action' => 'cancel_acme',
        'status' => 'executing',
        'started_at' => now()->addMinutes(5),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('executing');
    expect($fresh->attempts)->toBe(0);
    expect($fresh->last_execute_at)->toBeNull();
});

// ==================== 分发：order vs acme ====================

test('acme action 去 _acme 后缀路由到 Acme\\Action（sync_acme → sync）', function () {
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->active()->create([
        'product_id' => $product->id,
        'api_id' => 'upstream-sync-1',
    ]);
    taskJobSetupGateway();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);

    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'sync_acme',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    // 命中 Acme\Action::sync（上游被调用），任务成功落库
    Http::assertSent(fn ($req) => str_contains($req->url(), 'fake-gateway.test'));
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('successful');
    expect($fresh->result['code'])->toBe(1);
    expect($fresh->attempts)->toBe(1);
    expect($fresh->last_execute_at)->not->toBeNull();
});

test('非 acme action 路由到 Order\\Action（commit → Order\\Action::commit）', function () {
    // 不造订单：commit 找不到订单会立即 $this->error()（ApiResponseException code=0），
    // 这正好同时证明「路由到 Order\Action」+「ApiResponseException(code=0) → failed」两件事。
    $task = Task::factory()->create([
        'order_id' => 888888, // 不存在的订单
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['code'])->toBe(0);
    // Order\Action::commit 的订单不存在文案
    expect($fresh->result['msg'])->toContain('订单或相关数据不存在');
});

// ==================== ApiResponseException 分流 ====================

test('ApiResponseException(code=0) 任务标 failed 且结果落库', function () {
    // sync_acme 指向无 api_id 的 acme → Acme\Action::sync 抛 $this->error('订单尚未提交到上游')
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->active()->create([
        'product_id' => $product->id,
        'api_id' => null, // 无 api_id → sync 报错（code=0）
    ]);

    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'sync_acme',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('订单尚未提交到上游');
    expect($fresh->attempts)->toBe(1);
    expect($fresh->last_execute_at)->not->toBeNull();
});

// ==================== C1：取消类 action 业务失败触发 fail() ====================

test('cancel 业务失败（订单不存在）触发 fail() 且 task 标 failed', function () {
    // 杀手场景：cancel 被上游/本地校验拒绝时，退款永不发生、订单永久卡 cancelling。
    // 内层 catch 对 cancel 白名单设 $failedException → $this->fail() → failed() 发 admin 告警。
    // order 888888 不存在 → Action::cancel 在锁内 error('订单或相关数据不存在')（ApiResponseException code=0）。
    $task = Task::factory()->create([
        'order_id' => 888888,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed(); // C1：修复前 ApiResponseException 分支不设 $failedException → 不 fail
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('订单或相关数据不存在');
});

test('cancel_acme 业务失败（状态不是取消中）触发 fail() 且 task 标 failed', function () {
    // Acme::firstOrFail 存在 → 状态非 cancelling → error('订单状态不是取消中')（ApiResponseException code=0），
    // 命中内层 catch（非 ModelNotFound 的 generic 分支），验证 cancel_acme 白名单同样触发 fail()。
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->active()->create([ // active != cancelling
        'product_id' => $product->id,
    ]);

    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'cancel_acme',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed();
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('订单状态不是取消中');
});

test('commit 业务失败不触发 fail()（action 白名单未扩散，commit 由 reconcile 兜底告警）', function () {
    // 回归护栏：仅 cancel/cancel_acme 触发 fail()，commit 业务失败仍只标 failed、不 fail，
    // 避免告警风暴（commit 到顶由 ReconcilePendingCommand 转人工扫描 alertMaxedOrders 每日快照兜底）。
    $task = Task::factory()->create([
        'order_id' => 888888, // commit 不存在订单 → error(code=0)
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotFailed(); // commit 不在白名单，不触发 fail()
    expect($task->fresh()->status)->toBe('failed');
});

// ==================== Imp-1：取消类幂等拒绝不误报 admin 告警 ====================

test('cancel 幂等拒绝（本地已 cancelled 且已退款）视为 no-op：task 标 failed 但不触发 fail() 告警', function () {
    // Imp-1 复现：并发/历史遗留的 cancel task 在订单已退款并置 cancelled 后被 TaskJob 唤醒，
    // 撞 cancelLocked 锁内 status===cancelled → error('订单已取消')（code=0）。退款已发生，属幂等 no-op，
    // 绝不能再发 admin task_failed 假告警。修复前 C1 无差别对 cancel 失败 fail() → 假告警（本断言 RED）。
    // ②修复后：cancelled 豁免需以「已退款（有 cancel 流水）」为真实前提 —— 本用例补建 cancel 流水
    // 忠实反映「退款已发生」，判据读到流水正确豁免。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product); // amount 默认 100
    $this->createTestCert($order, ['action' => 'new', 'status' => 'cancelled']); // 本地已终态
    // 退款已发生的真实前提：cancelLocked/refundForSyncedCancel 退款置 cancelled 时建 cancel 流水
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'cancel',
        'transaction_id' => $order->id,
        'amount' => '100.00',
        'standard_count' => -1,
        'wildcard_count' => 0,
    ]);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotFailed(); // 幂等拒绝不告警（修复前无条件 fail → 本行 RED）
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');        // 仍标 failed，绝不标 successful（红线：取消不静默成功）
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('订单已取消');
});

test('cancel_acme 幂等拒绝（本地已 cancelled 终态）视为 no-op：task 标 failed 但不触发 fail() 告警', function () {
    // Imp-1 ACME 对称：孤儿延时 cancel_acme 任务唤醒时 acme 已终态（cancelled），撞 cancelLocked
    // status!==cancelling → error('订单状态不是取消中')（code=0）。幂等 no-op，绝不假告警（修复前 RED）。
    $product = $this->createTestProduct([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->cancelled()->create([ // 本地已终态
        'product_id' => $product->id,
    ]);

    $task = Task::factory()->create([
        'order_id' => $acme->id,
        'action' => 'cancel_acme',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotFailed();
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('订单状态不是取消中');
});

test('cancel 真失败（本地 cancelling 非终态 + 上游拒绝）必须照常触发 fail() 告警', function () {
    // 反向严格边界：本地仍 cancelling（非终态）且上游 api->cancel 真拒绝 → 真·CA 取消失败，
    // 退款未发生、订单卡 cancelling，必须告警。守卫幂等豁免绝不误伤真失败（本用例修复前后恒绿）。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'cancelling']); // 非终态

    // 上游 cancel 抛 ApiResponseException（cancelLocked 捕获后 re-throw error('上游拒绝取消')）
    $stub = new class extends Api
    {
        public function cancel(int $orderId): array
        {
            throw new ApiResponseException('上游拒绝取消');
        }
    };
    app()->instance(Api::class, $stub);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed(); // 非终态 + 真失败 → 必须告警
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['msg'])->toContain('上游拒绝取消');
});

test('cancel 撞已 failed 订单（failed 无任何退款路径）：不豁免、必须触发 fail() 告警', function () {
    // ② 子面1：force sync 可把上游 failed 写过 cancelling（守卫集不含 cancelling）→ cancel task 到点撞
    // cancelLocked 锁内「status != cancelling → error('订单状态不是取消中')」（code=0）读到 failed。
    // failed 全系统无退款路径（commitCancel/V2 cancel 均拒 failed），「failed 但退款已发生」不存在合法形态
    // → 真·CA 取消失败，必须告警。修复前 failed 在豁免集 → 静默零告警（本断言 RED）。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'failed']); // 终态但非取消中

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed(); // failed 剔除豁免集 → 告警（修复前 assertNotFailed）
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['msg'])->toContain('订单状态不是取消中');
});

test('cancel 撞已 cancelled 但未退款订单（付费单无 cancel 流水）：不豁免、必须触发 fail() 告警', function () {
    // ② 子面2（与 ③ 同根因）：autoRefundOnSync=false 时 sync 直写 cancelled 而不退款；用户的 cancel
    // 任务到点撞「status===cancelled → error('订单已取消')」，退款诉求丢失。cancelled 不能无条件豁免——
    // 付费单（应退金额>0）却无 cancel 流水 = 退款未发生的真失败，必须告警。修复前无条件豁免 → 静默（RED）。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product); // amount 默认 100（应退>0）
    $this->createTestCert($order, ['action' => 'new', 'status' => 'cancelled']);
    // 付过费（type=order 扣费流水），但没有 cancel 退款流水 —— 退款未发生
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-100.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed(); // cancelled 但应退>0 且无 cancel 流水 → 告警（修复前 assertNotFailed）
    expect($task->fresh()->result['msg'])->toContain('订单已取消');
});

test('cancel 撞已 cancelled 的 0 元订单（应退=0 本就不建流水）：仍豁免、不告警', function () {
    // ② 反向边界：0 元订单取消不产生 cancel 流水（Transaction::creating amount=0 短路），
    // 无 cancel 流水属合法幂等，绝不能误告警。判据以「应退金额（order.amount）是否>0」区分，非「有无流水」。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product, ['amount' => '0.00']); // 0 元订单
    $this->createTestCert($order, ['action' => 'new', 'status' => 'cancelled']);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotFailed(); // 0 元取消无流水属合法幂等
    expect($task->fresh()->result['msg'])->toContain('订单已取消');
});

test('cancel 撞已 cancelled 的 reissue 零增量单但订单金额大于 0：未退款必须告警', function () {
    // 已提交上游的 reissue 取消恢复订单全额退款口径；即使本次增量为 0，只要 order.amount>0，
    // cancelled 且无 cancel 流水仍代表整单退款缺失，不能按 cert.amount=0 误判为合法幂等。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product, ['amount' => '100.00']); // 原始订单付费
    $this->createTestCert($order, ['action' => 'reissue', 'status' => 'cancelled', 'amount' => '0.00']); // 零增量

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed();
    expect($task->fresh()->result['msg'])->toContain('订单已取消');
});

test('failed() 对 ApiResponseException 取 getApiResponse()[msg] 而非空 getMessage()', function () {
    // 反模式 16：ApiResponseException::getMessage() 恒空，可读消息在 getApiResponse()['msg']；
    // 若 failed() 用 getMessage() 则 error_message 恒空 = 告警邮件无据 = 白修。
    $admin = Admin::factory()->create(['email' => 'ops@example.com']);
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@example.com', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);

    $task = Task::factory()->create([
        'action' => 'cancel',
        'status' => 'failed',
    ]);

    $captured = null;
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($intent) use (&$captured) {
            $captured = $intent;

            return $intent instanceof NotificationIntent;
        }));
    app()->instance(NotificationCenter::class, $mock);

    $job = new TaskJob(['id' => $task->id]);
    $job->failed(new ApiResponseException('CA取消失败：上游拒绝'));

    expect($captured)->not->toBeNull();
    expect($captured->context['error_message'])->toBe('CA取消失败：上游拒绝');
});

// ==================== Throwable 分流（含方法不存在） ====================

test('内层 Throwable 任务标 failed 且捕获异常元数据（file/line/error_code）', function () {
    // 「方法不存在」的 RuntimeException 在内层 try 抛出，被 catch (Throwable) 接住，
    // 转成 failed 任务并捕获完整元数据；不向 handle() 外冒泡。
    // order action 路由：action 是 Action 上不存在的方法。
    $task = Task::factory()->create([
        'order_id' => 1,
        'action' => 'definitelyMissingActionMethod',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('方法不存在');
    // Throwable 分支独有的元数据结构
    expect($fresh->result['data'])->toHaveKeys(['file', 'line', 'error_code']);
    expect($fresh->attempts)->toBe(1);
});

test('acme 路由下方法不存在同样转 failed（acmeAction 分支的 RuntimeException）', function () {
    // 构造一个 _acme 后缀但去后缀后 Acme\Action 无对应方法的 action，
    // 走 method_exists 守卫抛 RuntimeException → Throwable 分支 → failed。
    $task = Task::factory()->create([
        'order_id' => 1,
        'action' => 'bogus_acme',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->result['msg'])->toContain('方法不存在');
});

test('内层 Throwable 不向 handle() 外冒泡（不触发 Laravel 自动 rollback，task 状态可落库）', function () {
    // 回归保护：若 RuntimeException 漏出闭包，DB::transaction 会自动 rollback，
    // task.update(failed) 将丢失。这里断言「调用不抛 + 状态确实落库为 failed」。
    $task = Task::factory()->create([
        'order_id' => 1,
        'action' => 'anotherMissingMethod',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $threw = false;
    try {
        (new TaskJob(['id' => $task->id]))->handle();
    } catch (Throwable $e) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect($task->fresh()->status)->toBe('failed');
});

// ==================== 并发错误（死锁）分流：冒泡而非吞掉 ====================

test('内层并发错误（死锁）未达上限：handle 自行 release 错峰重试、不冒泡、task 保持 executing', function () {
    // P0-1 杀手场景延续 + 降噪方案 C：死锁回滚整个 InnoDB 事务后，并发错误必须冒出闭包（绝不在死事务上
    // $task->update()，否则抛 "There is no active transaction" + 雪崩，线上 2026-06-03 现象）。
    // 未达 tries 上限时由 handle() 自己 $this->release() 静默错峰重试（不抛 → worker 不进异常上报路径
    // → 无死锁日志噪音），task 保持 executing 等下次拾取；不再冒出 handle() 交 worker 兜底重试。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'processing']);

    // sync(force=false) 在事务外调 Api::get（Action.php:462/473）；让它抛死锁，
    // 等效于事务内 FOR UPDATE 死锁后异常向 TaskJob 闭包冒泡的情形
    $stub = new class extends Api
    {
        public function get(int $orderId): array
        {
            throw new DeadlockException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
            );
        }
    };
    app()->instance(Api::class, $stub);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'sync',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions(); // FakeJob attempts=1 < tries=3

    $job->handle(); // 不冒泡

    $job->assertReleased();  // 静默放回队列错峰重试，worker 不会 report
    $job->assertNotFailed(); // 自愈中，未 fail
    // 未在死事务内被标 failed：保持 executing 等下次重试
    expect($task->fresh()->status)->toBe('executing');
});

test('内层并发错误（死锁）达 tries 上限：冒泡交 worker failJob + report 一次、不再 release', function () {
    // 降噪方案 C 边界：attempts 达到 tries 时不再静默 release，而是冒出 handle()，
    // 让 worker 记一次最终失败（report）+ failJob → failed() 钩子兜底标 task failed。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'processing']);

    $stub = new class extends Api
    {
        public function get(int $orderId): array
        {
            throw new DeadlockException('SQLSTATE[40001]: 1213 Deadlock found');
        }
    };
    app()->instance(Api::class, $stub);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'sync',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 5; // == tries（最后一次执行；C5 将 tries 3→5，边界值随之上移）

    $threw = false;
    try {
        $job->handle();
    } catch (DeadlockException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();                    // 达上限冒泡（worker 据此 report 一次 + failJob）
    expect($job->job->isReleased())->toBeFalse();  // 不再 release
    expect($task->fresh()->status)->toBe('executing'); // 未在死事务标 failed，由 failed() 兜底
});

test('commit 嵌套事务内并发错误：未达上限 release + 连接计数复位可复用（回归：禁手写事务）', function () {
    // CRITICAL 回归保护：commit 曾用手写 DB::beginTransaction，经 TaskJob 嵌套调用时 1213 死锁会让
    // catch 内 DB::rollback() 抛 1305「SAVEPOINT does not exist」淹没死锁异常 + 连接事务计数漂移 →
    // 下一个 job「There is (no) active transaction」雪崩。改 DB::transaction(fn,1) 闭包后：嵌套并发错误
    // 由 Laravel 统一抛成 DeadlockException、task 留 executing、连接事务计数复位可复用。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'pending']);

    // commit 事务内的上游下单抛底层并发错误（QueryException 含 Deadlock），模拟事务内 FOR UPDATE 死锁
    $stub = new class extends Api
    {
        public function new(array $data): array
        {
            $pdo = new PDOException('SQLSTATE[40001]: 1213 Deadlock found when trying to get lock; try restarting transaction');
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

            throw new QueryException('mysql', 'select * from `tasks` ... for update', [], $pdo);
        }
    };
    app()->instance(Api::class, $stub);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $levelBefore = DB::transactionLevel();

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();

    $job->handle(); // 未达上限 → release，不冒泡

    $job->assertReleased();                              // 静默错峰重试
    expect($task->fresh()->status)->toBe('executing');   // 未在死事务里标 failed
    expect(DB::transactionLevel())->toBe($levelBefore);  // 连接事务计数复位，无漂移

    // 连接干净可复用：能再开事务不报错（改造前计数漂移会让这里炸）
    $reusable = false;
    DB::transaction(function () use (&$reusable) {
        $reusable = true;
    });
    expect($reusable)->toBeTrue();
});

// ==================== 事务包裹不变量（lockForUpdate 真锁） ====================

test('handle 整体在事务内执行：action 运行时 transactionLevel > 0（lockForUpdate 真锁）', function () {
    // 硬约束：handle() 必须整体包 DB::transaction，否则 lockForUpdate 在自动提交模式下是
    // 「假锁」（SELECT 返回即释放）。这里走真实 Order\Action::commit 路径，把上游 Api
    // 换成 stub，在 stub 被调用一刻抓 DB::transactionLevel()——它由 TaskJob 闭包持有，必须 > 0。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'pending']);

    // new Action 内部 app(Api::class)（App\Services\Order\Api\Api）→ 容器 stub 生效
    $stub = new class extends Api
    {
        public ?int $level = null;

        public function new(array $data): array
        {
            $this->level = DB::transactionLevel();

            return ['code' => 1, 'data' => ['api_id' => 'stub-api-id', 'cert_apply_status' => 0]];
        }
    };
    app()->instance(Api::class, $stub);

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    expect($stub->level)->not->toBeNull();
    expect($stub->level)->toBeGreaterThan(0);
    // 任务成功落库（事务已 COMMIT），证明闭包内 update 真实持久化
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('successful');
    expect($fresh->result['code'])->toBe(1);
});

// ==================== failed() 兜底标记 + 告警钩子 ====================

test('failed() 把仍 executing 的 task 兜底标记为 failed（并发错误抛出后防永久卡死）', function () {
    // P0-1：handle() 对并发错误改为抛出（不在死事务内 update），重试耗尽进入本钩子时 task 仍 executing。
    // 必须兜底标 failed，否则被 checkRepeat 当"处理中"永久阻塞该订单后续 commit/sync。
    // 无 admin 配置 → 兜底 update 在通知早返回之前执行，不派发通知。
    $task = Task::factory()->create([
        'action' => 'sync',
        'status' => 'executing',
        'attempts' => 2,
    ]);

    (new TaskJob(['id' => $task->id]))->failed(
        new DeadlockException('1213 Deadlock found')
    );

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->attempts)->toBe(3);
    expect($fresh->result['code'])->toBe(0);
    expect($fresh->result['msg'])->toContain('Deadlock');
});

test('failed() 对已落库 failed 的 task 不重复 update（普通异常路径守卫）', function () {
    // 普通业务异常已在 handle() 内标 failed；failed() 钩子守卫 status==='executing'，
    // 跳过重复 update，不覆盖原始失败原因、不重复自增 attempts。
    $task = Task::factory()->create([
        'action' => 'commit_acme',
        'status' => 'failed',
        'attempts' => 1,
        'result' => ['code' => 0, 'msg' => '原始失败原因'],
    ]);

    (new TaskJob(['id' => $task->id]))->failed(new RuntimeException('new error'));

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->attempts)->toBe(1);                   // 守卫跳过，未再自增
    expect($fresh->result['msg'])->toBe('原始失败原因');  // 未被覆盖
});

test('failed() 构造 task_failed NotificationIntent 派发到 NotificationCenter', function () {
    // site.adminEmail 是运维别名，Admin 登录邮箱为空；Admin 仅作为通知归属。
    $admin = Admin::factory()->create(['email' => null]);
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops-alias@example.com', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);

    $task = Task::factory()->create([
        'action' => 'commit_acme',
        'status' => 'failed',
    ]);

    $captured = null;
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($intent) use (&$captured) {
            $captured = $intent;

            return $intent instanceof NotificationIntent;
        }));
    app()->instance(NotificationCenter::class, $mock);

    $job = new TaskJob(['id' => $task->id]);
    $job->failed(new RuntimeException('boom upstream timeout'));

    expect($captured)->not->toBeNull();
    expect($captured->code)->toBe('task_failed');
    expect($captured->notifiableType)->toBe('admin');
    expect($captured->notifiableId)->toBe($admin->id);
    expect($captured->context['task_id'])->toBe($task->id);
    expect($captured->context['error_message'])->toBe('boom upstream timeout');
    expect($captured->context['admin_email'])->toBe('ops-alias@example.com');
});

test('failed() 在 task 不存在时直接返回，不派发通知', function () {
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldNotReceive('dispatch');
    app()->instance(NotificationCenter::class, $mock);

    $job = new TaskJob(['id' => 777777]);
    $job->failed(new RuntimeException('x'));

    // shouldNotReceive 在 Mockery::close()（afterEach）校验
    expect(true)->toBeTrue();
});

test('failed() 在无任何 admin 邮箱时直接返回，不派发通知', function () {
    // 不创建任何 Admin、不设 adminEmail → admin 为 null / 无 email
    Admin::query()->delete();

    $task = Task::factory()->create([
        'action' => 'commit_acme',
        'status' => 'failed',
    ]);

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldNotReceive('dispatch');
    app()->instance(NotificationCenter::class, $mock);

    $job = new TaskJob(['id' => $task->id]);
    $job->failed(new RuntimeException('x'));

    expect(true)->toBeTrue();
});

afterEach(function () {
    Mockery::close();
});

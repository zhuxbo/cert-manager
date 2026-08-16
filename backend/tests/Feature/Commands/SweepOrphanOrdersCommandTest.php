<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\PendingReconcileQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * O4（schedule:sweep-orphan-orders）守门测试 —— 清理 channel=auto 卡死的 unpaid/pending 孤儿单。
 *
 * pending→cancelPending 是退款 mutation：余额一律 withBalance()（Fund 钩子产合规流水）、扣费经真实
 * Transaction（type=order）建立，退款经 cancelPending 走 getCancelTransaction 汇总取反，afterEach 跑资金
 * invariant 守门。到顶/产品缺失判据一律消费 PendingReconcileQuery 共享物（与 reconcile/T5 同源、禁手抄）。
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'reconcile.max_attempts' => 3,
        'reconcile.orphan.unpaid_enabled' => true,
        'reconcile.orphan.unpaid_stale_minutes' => 60,
        'reconcile.orphan.batch' => 50,
    ]);
});

/**
 * 造一个「unpaid renew 孤儿」：旧证书 renewed + 新单 unpaid（channel 可控，无扣费流水）。
 *
 * @return array{0: User, 1: Order, 2: Cert, 3: Cert}
 */
function makeOrphanUnpaid(string $channel = 'auto', array $certOverrides = []): array
{
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();

    $sourceOrder = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $oldCert = Cert::factory()->create(['order_id' => $sourceOrder->id, 'status' => 'renewed', 'action' => 'new']);
    $sourceOrder->update(['latest_cert_id' => $oldCert->id]);

    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'amount' => '100.00']);
    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id, 'status' => 'unpaid', 'action' => 'renew', 'api_id' => null,
        'channel' => $channel, 'amount' => '100.00', 'last_cert_id' => $oldCert->id,
        'created_at' => now()->subMinutes(90),
    ], $certOverrides));
    $order->update(['latest_cert_id' => $cert->id]);

    return [$user, $order, $cert, $oldCert];
}

/**
 * 造一个「pending renew 孤儿」：旧证书 renewed + 新单 pending（已扣费，channel 可控）。
 *
 * @return array{0: User, 1: Order, 2: Cert, 3: Cert}
 */
function makeOrphanPending(string $channel = 'auto', string $amount = '100.00', array $certOverrides = []): array
{
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();

    $sourceOrder = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $oldCert = Cert::factory()->create(['order_id' => $sourceOrder->id, 'status' => 'renewed', 'action' => 'new']);
    $sourceOrder->update(['latest_cert_id' => $oldCert->id]);

    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'amount' => $amount]);
    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id, 'status' => 'pending', 'action' => 'renew', 'api_id' => null,
        'channel' => $channel, 'amount' => $amount, 'last_cert_id' => $oldCert->id,
        'created_at' => now()->subMinutes(20),
    ], $certOverrides));
    $order->update(['latest_cert_id' => $cert->id]);

    // 扣费流水（-amount）：cancelPending 退款按 getCancelTransaction 汇总取反
    Transaction::create([
        'user_id' => $user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-'.$amount, 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    return [$user, $order, $cert, $oldCert];
}

/** 在「当前周期」内造 $count 条失败 commit task（last_execute_at 晚于 cert.created_at=subMinutes(20)）。 */
function orphanFailedCommits(int $orderId, int $count = 3): void
{
    for ($i = $count; $i >= 1; $i--) {
        Task::factory()->failed()->create([
            'order_id' => $orderId, 'action' => 'commit', 'attempts' => 1,
            'last_execute_at' => now()->subMinutes($i * 3),
        ]);
    }
}

/** 造一条产品缺失（Product not found）失败 commit task。 */
function orphanProductMissingTask(int $orderId): void
{
    Task::factory()->failed()->create([
        'order_id' => $orderId, 'action' => 'commit', 'attempts' => 1,
        'last_execute_at' => now()->subMinutes(5),
        'result' => ['code' => 0, 'msg' => 'Product not found'],
    ]);
}

/** 造一条 executing commit task。 */
function orphanExecutingCommit(int $orderId): void
{
    Task::factory()->create([
        'order_id' => $orderId, 'action' => 'commit', 'status' => 'executing', 'started_at' => now(),
    ]);
}

test('签名为 schedule:sweep-orphan-orders', function () {
    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();
});

test('⑬ unpaid：channel=auto 超 stale → delete 恢复旧证书；未超 stale / 非 auto → 不动', function () {
    [, $order, , $oldCert] = makeOrphanUnpaid('auto', ['created_at' => now()->subMinutes(90)]);
    [, $orderFresh, $certFresh] = makeOrphanUnpaid('auto', ['created_at' => now()->subMinutes(10)]);
    [, $orderWeb, $certWeb] = makeOrphanUnpaid('web', ['created_at' => now()->subMinutes(90)]);

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    // 超 stale + auto：新单删除、旧证书恢复 active
    expect(Order::find($order->id))->toBeNull()
        ->and($oldCert->fresh()->status)->toBe('active');
    // 未超 stale / 非 auto：保持不动
    expect(Order::find($orderFresh->id))->not->toBeNull()
        ->and($certFresh->fresh()->status)->toBe('unpaid')
        ->and(Order::find($orderWeb->id))->not->toBeNull()
        ->and($certWeb->fresh()->status)->toBe('unpaid');
});

test('⑭ pending 到顶（channel=auto）→ cancelPending 退款 + 旧证书恢复 + cert cancelled；未到顶不动', function () {
    [$user, $order, $cert, $oldCert] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($order->id, 3); // 到顶
    [$user2, $order2, $cert2] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($order2->id, 2); // 未到顶

    expect($user->fresh()->balance)->toBe('900.00'); // 扣费后

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    // 到顶单：cancelPending 退款（cert cancelled、旧证书恢复 active、退款回 1000）
    expect($cert->fresh()->status)->toBe('cancelled')
        ->and($oldCert->fresh()->status)->toBe('active')
        ->and(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(1)
        ->and($user->fresh()->balance)->toBe('1000.00');
    // 未到顶单：保持 pending、不退款
    expect($cert2->fresh()->status)->toBe('pending')
        ->and($user2->fresh()->balance)->toBe('900.00');
});

test('⑮ K3：failed count < max（reconcile 仍在重试窗）→ 不接手', function () {
    [$user, $order, $cert] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($order->id, 2); // < max=3

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    expect($cert->fresh()->status)->toBe('pending')
        ->and($user->fresh()->balance)->toBe('900.00');
});

test('⑯ K6：processing 单（PurgeCommand 领地）→ O4 零命中（状态不相交）', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->approving()->create([
        'order_id' => $order->id, 'status' => 'processing', 'channel' => 'auto',
        'created_at' => now()->subMinutes(90),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);
    orphanFailedCommits($order->id, 3);

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    expect($cert->fresh()->status)->toBe('processing'); // O4 status 过滤天然排除
});

test('⑰ K7：markRenewed 单（latestCert=renewed 无接替链）→ O4 零动作（红线）', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id, 'status' => 'renewed', 'action' => 'new', 'channel' => 'auto',
        'created_at' => now()->subMinutes(90),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    // status IN(unpaid,pending) 过滤天然排除 renewed；订单/证书未被触碰
    expect(Order::find($order->id))->not->toBeNull()
        ->and($cert->fresh()->status)->toBe('renewed');
});

test('⑱ 产品缺失排除：pending 单含 Product not found → O4 不接手（留人工）', function () {
    [$user, $order, $cert] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($order->id, 2);
    orphanProductMissingTask($order->id); // 3 条失败、含产品缺失信号

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    expect($cert->fresh()->status)->toBe('pending')
        ->and($user->fresh()->balance)->toBe('900.00');
});

test('⑲ executing 保护：pending 到顶 + executing commit task → 不接手（等 commit 落定）', function () {
    [$user, $order, $cert] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($order->id, 3);
    orphanExecutingCommit($order->id);

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    expect($cert->fresh()->status)->toBe('pending')
        ->and($user->fresh()->balance)->toBe('900.00');
});

test('⑳ pending 到顶退款不可被旧 pending_enabled 配置关闭，unpaid 开关仍独立生效', function () {
    // 即使升级前残留 pending_enabled=false，已扣费且确认未提交上游的 pending 到顶单仍必须退款。
    config(['reconcile.orphan.pending_enabled' => false, 'reconcile.orphan.unpaid_enabled' => true]);
    [$userP, $orderP, $certP] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($orderP->id, 3);
    [, $orderU, , $oldCertU] = makeOrphanUnpaid('auto', ['created_at' => now()->subMinutes(90)]);

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    expect($certP->fresh()->status)->toBe('cancelled')
        ->and($userP->fresh()->balance)->toBe('1000.00')
        ->and(Transaction::where('transaction_id', $orderP->id)->where('type', 'cancel')->count())->toBe(1)
        ->and(Order::find($orderU->id))->toBeNull()
        ->and($oldCertU->fresh()->status)->toBe('active');

    // unpaid 开关仍只控制无资金的 stale unpaid 清理，不影响 pending 退款。
    config(['reconcile.orphan.pending_enabled' => false, 'reconcile.orphan.unpaid_enabled' => false]);
    [, $orderU2, $certU2] = makeOrphanUnpaid('auto', ['created_at' => now()->subMinutes(90)]);
    [$userP2, $orderP2, $certP2] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($orderP2->id, 3);

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    expect($certU2->fresh()->status)->toBe('unpaid')
        ->and($certP2->fresh()->status)->toBe('cancelled')
        ->and($userP2->fresh()->balance)->toBe('1000.00')
        ->and(Transaction::where('transaction_id', $orderP2->id)->where('type', 'cancel')->count())->toBe(1);
});

test('㉑ 并发：cancelPending 与并发 late-commit（推 processing）→ 锁内 guard 早退、不误退款', function () {
    [$user, $order, $cert] = makeOrphanPending('auto', '100.00');
    orphanFailedCommits($order->id, 3);

    // DB::listen 在扫描 SELECT（唯一含 lc join/last_execute_at 的非锁定读）后、cancelPending 锁内复检前，
    // 把 cert 推到 processing（模拟并发 late-commit）→ cancelPending 锁内 status!='pending' 早退（try/catch 吞）。
    $flipped = false;
    DB::listen(function ($query) use ($cert, &$flipped) {
        $sql = strtolower($query->sql);
        if (! $flipped
            && str_contains($sql, 'as `lc`')          // O4 pending 扫描的 certs 锚点 JOIN（非锁定读）
            && ! str_contains($sql, 'for update')) {
            $flipped = true;
            DB::table('certs')->where('id', $cert->id)->update(['status' => 'processing']);
        }
    });

    $this->artisan('schedule:sweep-orphan-orders')->assertSuccessful();

    // 被并发推 processing → 锁内 guard 早退：无退款、无 cancel 流水、余额保持扣费态
    expect($cert->fresh()->status)->toBe('processing')
        ->and(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(0)
        ->and($user->fresh()->balance)->toBe('900.00');
});

test('㉒ 补集断言（防漂移锁）：O4 pending 接手集 == 转人工集 ∩ 非产品缺失 ∩ channel=auto ∩ 无 executing', function () {
    // 矩阵：到顶/未到顶 × 产品缺失/非缺失 × executing/无 × channel auto/deploy
    [, $o1] = makeOrphanPending('auto');
    orphanFailedCommits($o1->id, 3);                         // 到顶 非缺失 无executing auto → 接手
    [, $o2] = makeOrphanPending('auto');
    orphanProductMissingTask($o2->id);                       // 产品缺失（不接手）
    [, $o2b] = makeOrphanPending('auto');
    orphanFailedCommits($o2b->id, 2);
    orphanProductMissingTask($o2b->id);                      // 到顶 + 缺失（不接手）
    [, $o3] = makeOrphanPending('auto');
    orphanFailedCommits($o3->id, 2);                         // 未到顶（不接手）
    [, $o4] = makeOrphanPending('auto');
    orphanFailedCommits($o4->id, 3);
    orphanExecutingCommit($o4->id);                          // 到顶 + executing（不接手）
    [, $o5] = makeOrphanPending('deploy');
    orphanFailedCommits($o5->id, 3);                         // 到顶但 channel=deploy（不接手）

    $max = (int) config('reconcile.max_attempts');

    // 左：O4 实际接手集（maxedAndNotProductMissing + channel=auto whereHas + 无 executing）
    $left = PendingReconcileQuery::maxedAndNotProductMissing(
        Order::query()->whereHas('latestCert', fn ($q) => $q
            ->where('status', 'pending')->whereNull('api_id')->where('channel', 'auto'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tasks')
                ->whereColumn('tasks.order_id', 'orders.id')
                ->where('tasks.action', 'commit')->where('tasks.status', 'executing')),
        $max
    )->pluck('id')->sort()->values()->all();

    // 右：从 T5 转人工集（maxedOrProductMissing）PHP 过滤 非产品缺失 + 无 executing 推导（两侧消费同一共享物）
    $right = PendingReconcileQuery::maxedOrProductMissing(
        Order::query()->with('latestCert')->whereHas('latestCert', fn ($q) => $q
            ->where('status', 'pending')->whereNull('api_id')->where('channel', 'auto')),
        $max
    )->get()->filter(function (Order $order) {
        $anchor = $order->latestCert?->created_at;
        $missing = Task::where('order_id', $order->id)->where('action', 'commit')->where('status', 'failed')
            ->when($anchor, fn ($q) => $q->where('last_execute_at', '>=', $anchor))->get()
            ->contains(fn (Task $t) => PendingReconcileQuery::matchesProductMissing($t->result['msg'] ?? null));
        $executing = Task::where('order_id', $order->id)->where('action', 'commit')
            ->where('status', 'executing')->exists();

        return ! $missing && ! $executing;
    })->pluck('id')->sort()->values()->all();

    expect($left)->toBe($right)
        ->and($left)->toBe([$o1->id]); // 仅 o1 落接手集
});

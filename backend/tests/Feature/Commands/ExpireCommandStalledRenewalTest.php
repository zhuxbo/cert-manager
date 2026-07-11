<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\Builders\CertRenewStalledNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| 包X — 续期停滞孤儿止血（cert_renew_stalled）ExpireCommand 派发 + 双侧同源
|--------------------------------------------------------------------------
|
| 检测框架：证书为轴前驱侧扫描（前驱 ∈ {renewed,reissued} + 节点窗口 + EXISTS 接替
| ∈ 5 态 {unpaid,pending,processing,approving,failed} + 48h 在途门槛）。
| markRenewed 单无接替、结构性免疫（红线，测试 1）。
*/

afterEach(function () {
    Mockery::close();
    Carbon\Carbon::setTestNow();
});

/**
 * 装一个「捕获式」NotificationCenter mock，收集全部派发的 intent；返回 ArrayObject（对象句柄，
 * 命令执行后即含全部 intent）。允许任意 code（cert_expire / acme_expire / cert_renew_stalled）。
 */
function captureExpireDispatch(): ArrayObject
{
    $captured = new ArrayObject;
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $mock);

    return $captured;
}

/** 该批 intent 中是否对某 user 派发了指定 code。 */
function dispatchedCodeForUser(ArrayObject $captured, string $code, int $userId): bool
{
    foreach ($captured as $intent) {
        if ($intent->code === $code && $intent->notifiableId === $userId) {
            return true;
        }
    }

    return false;
}

/**
 * 造一组续期停滞孤儿：前驱证书 C（renewed/reissued，expires_at 落窗口）+ 接替证书 S（last_cert_id→C，
 * 指定停滞态 + created_at 年龄）。renew 默认接替另开新订单，reissue 默认接替复用同订单。
 *
 * @return array{predecessor: Cert, successor: Cert, pred_order: Order, suc_order: Order}
 */
function makeStalledOrphan(User $user, Product $product, array $opts = []): array
{
    $predStatus = $opts['pred_status'] ?? 'renewed';
    $expiresAt = $opts['expires_at'] ?? now()->addDays(6)->addHours(12); // node-7 窗口 [now+6, now+7]
    $sucStatus = $opts['successor_status'] ?? 'unpaid';
    $sucAgeHours = $opts['successor_age_hours'] ?? 72;                    // > 48h：默认真停滞
    $sameOrder = $opts['same_order'] ?? ($predStatus === 'reissued');
    $commonName = $opts['common_name'] ?? 'stalled-'.fake()->unique()->domainName();

    $predOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => false,
        'auto_reissue' => false,
    ]);
    $predecessor = Cert::factory()->create([
        'order_id' => $predOrder->id,
        'status' => $predStatus,
        'expires_at' => $expiresAt,
        'common_name' => $commonName,
        'channel' => 'web',
    ]);

    $sucOrder = $sameOrder
        ? $predOrder
        : Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $successor = Cert::factory()->create([
        'order_id' => $sucOrder->id,
        'last_cert_id' => $predecessor->id,
        'status' => $sucStatus,
        'channel' => 'web',
    ]);
    // created_at 直写 DB（绕过 Eloquent 时间戳托管），精确控制在途年龄
    DB::table('certs')->where('id', $successor->id)->update(['created_at' => now()->subHours($sucAgeHours)]);

    return ['predecessor' => $predecessor, 'successor' => $successor, 'pred_order' => $predOrder, 'suc_order' => $sucOrder];
}

/** 直接调真 Builder 重查某用户的停滞载荷（Feature 端到端，验证双侧同源）。 */
function stalledPayloadFor(User $user): ?NotificationPayload
{
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);

    return app(CertRenewStalledNotificationBuilder::class)->build(
        new NotificationIntent('cert_renew_stalled', 'user', $user->id, ['email' => $user->email ?: 'x@example.com']),
        $user
    );
}

// ── 测试 1（红线）：markRenewed 单不误伤 ───────────────────────────────────────
test('[红线] markRenewed 手工标记单（renewed 但无接替）零派发 cert_renew_stalled', function () {
    $user = User::factory()->create(['email' => 'mark@example.com']);
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    // markRenewed 语义：latestCert=renewed，且无任何证书 last_cert_id 指向它（EXISTS 接替恒 falsy）
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'renewed',
        'expires_at' => now()->addDays(6)->addHours(12), // 落 node-7，排除只能靠「无接替」
        'common_name' => 'marked.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeFalse();
});

// ── 测试 2：续费 unpaid 孤儿命中 + 中性文案 ────────────────────────────────────
test('续费 unpaid 孤儿命中：派发 + stall_status=unpaid + 中性文案（可重新支付或取消，不硬承诺）', function () {
    $user = User::factory()->create(['email' => 'unpaid@example.com']);
    $product = Product::factory()->create();

    makeStalledOrphan($user, $product, [
        'pred_status' => 'renewed',
        'successor_status' => 'unpaid',
        'successor_age_hours' => 72, // 3 天前
        'common_name' => 'unpaid-orphan.com',
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeTrue();

    $payload = stalledPayloadFor($user);
    expect($payload)->toBeInstanceOf(NotificationPayload::class);
    $cert = $payload->data['certificates'][0];
    expect($cert['domain'])->toBe('unpaid-orphan.com')
        ->and($cert['stall_status'])->toBe('unpaid')
        ->and($cert['action_hint'])->toContain('可重新支付以继续签发，或取消该订单')
        ->and($cert['action_hint'])->toContain('可能被系统自动清理');
});

// ── 测试 3（路径 3）：processing/approving/failed 接替均命中 ─────────────────────
test('[路径3] 已扣费在途/失败接替（processing/approving/failed）孤儿命中 + 文案', function (string $sucStatus) {
    $user = User::factory()->create(['email' => $sucStatus.'@example.com']);
    $product = Product::factory()->create();

    makeStalledOrphan($user, $product, [
        'pred_status' => 'renewed',
        'successor_status' => $sucStatus,
        'successor_age_hours' => 72,
        'common_name' => $sucStatus.'-orphan.com',
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeTrue();

    $cert = stalledPayloadFor($user)->data['certificates'][0];
    expect($cert['stall_status'])->toBe($sucStatus);

    if ($sucStatus === 'failed') {
        expect($cert['action_hint'])->toContain('重新购买证书')
            ->and($cert['action_hint'])->toContain('联系客服');
    } else {
        expect($cert['action_hint'])->toContain('域名验证/审核')
            ->and($cert['action_hint'])->toContain('费用已扣除');
    }
})->with(['processing', 'approving', 'failed']);

// ── 测试 4：重签孤儿命中（同 order，前驱 reissued ← 接替 pending） ──────────────
test('重签 pending 孤儿命中：同订单前驱 reissued ← 接替 pending → 派发 + 勿重复支付', function () {
    $user = User::factory()->create(['email' => 'reissue@example.com']);
    $product = Product::factory()->create();

    $r = makeStalledOrphan($user, $product, [
        'pred_status' => 'reissued',
        'successor_status' => 'pending',
        'same_order' => true, // 重签复用同一 order_id
        'common_name' => 'reissue-orphan.com',
    ]);
    // 重签语义核对：前驱与接替同订单
    expect($r['successor']->order_id)->toBe($r['predecessor']->order_id);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeTrue();

    $cert = stalledPayloadFor($user)->data['certificates'][0];
    expect($cert['stall_status'])->toBe('pending')
        ->and($cert['action_hint'])->toContain('请勿重复下单或重复支付');
});

// ── 测试 5：孤儿消解（前驱回 active、断链）→ 停发 stalled + 回归 cert_expire ──────
test('孤儿消解后前驱回 active、断链 → 不发 cert_renew_stalled，且该 active 证书正常进 cert_expire', function () {
    $user = User::factory()->create([
        'email' => 'resolved@example.com',
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
    ]);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => false,
        'auto_reissue' => false,
        'period_till' => now()->addDays(7),
    ]);

    // cancelPending 消解后的落库态：前驱回 active（expires_at 落窗口）
    $predecessor = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(6)->addHours(12),
        'common_name' => 'resolved.example.com',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $predecessor->id]);

    // 接替证书断链（last_cert_id=null）——EXISTS 接替归 falsy
    Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'cancelled',
        'last_cert_id' => null,
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeFalse()
        ->and(dispatchedCodeForUser($captured, 'cert_expire', $user->id))->toBeTrue();
});

// ── 测试 6：节点窗口固化（间隙不发 / 节点日发） ─────────────────────────────────
test('节点窗口固化：前驱 expires_at 距今 10 天（节点间隙）不派发，node-7 当日派发', function () {
    $product = Product::factory()->create();

    $gapUser = User::factory()->create(['email' => 'gap@example.com']);
    makeStalledOrphan($gapUser, $product, [
        'expires_at' => now()->addDays(10), // 节点间隙 [node-14, node-7] 之外
        'successor_status' => 'processing',
        'successor_age_hours' => 72,
    ]);

    $nodeUser = User::factory()->create(['email' => 'node@example.com']);
    makeStalledOrphan($nodeUser, $product, [
        'expires_at' => now()->addDays(6)->addHours(12), // node-7 窗口
        'successor_status' => 'processing',
        'successor_age_hours' => 72,
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $gapUser->id))->toBeFalse()
        ->and(dispatchedCodeForUser($captured, 'cert_renew_stalled', $nodeUser->id))->toBeTrue();
});

// ── 测试 7：既有 cert_expire 零回归 ────────────────────────────────────────────
test('既有 cert_expire 零回归：孤儿分支存在不改变 active 证书的 cert_expire 派发集', function () {
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);

    // active 证书（手动、auto off）→ 应发 cert_expire
    $activeUser = User::factory()->create([
        'email' => 'active@example.com',
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
    ]);
    $activeOrder = Order::factory()->create([
        'user_id' => $activeUser->id,
        'product_id' => $product->id,
        'auto_renew' => false,
        'auto_reissue' => false,
        'period_till' => now()->addDays(7),
    ]);
    $activeCert = Cert::factory()->active()->create([
        'order_id' => $activeOrder->id,
        'expires_at' => now()->addDays(6)->addHours(12),
        'channel' => 'web',
    ]);
    $activeOrder->update(['latest_cert_id' => $activeCert->id]);

    // 另一用户有停滞孤儿（stalled 分支）
    $stalledUser = User::factory()->create(['email' => 'stalled@example.com']);
    makeStalledOrphan($stalledUser, $product, ['successor_status' => 'unpaid', 'successor_age_hours' => 72]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    // cert_expire 照常发给 active 用户；stalled 发给孤儿用户；互不干扰
    expect(dispatchedCodeForUser($captured, 'cert_expire', $activeUser->id))->toBeTrue()
        ->and(dispatchedCodeForUser($captured, 'cert_renew_stalled', $stalledUser->id))->toBeTrue()
        ->and(dispatchedCodeForUser($captured, 'cert_renew_stalled', $activeUser->id))->toBeFalse();
});

// ── 测试 8（年龄门槛-抑制）：健康在途不误报 ─────────────────────────────────────
test('[48h 门槛-抑制] 健康在途接替（age 9h / 47h）不派发', function (int $ageHours) {
    $user = User::factory()->create(['email' => 'fresh'.$ageHours.'@example.com']);
    $product = Product::factory()->create();

    makeStalledOrphan($user, $product, [
        'successor_status' => 'processing',
        'successor_age_hours' => $ageHours,
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeFalse();
})->with([9, 47]);

// ── 测试 9（年龄门槛-放行）：真停滞命中（边界 49h） ─────────────────────────────
test('[48h 门槛-放行] 接替 age 49h（越过门槛）派发', function () {
    $user = User::factory()->create(['email' => 'stale49@example.com']);
    $product = Product::factory()->create();

    makeStalledOrphan($user, $product, [
        'successor_status' => 'processing',
        'successor_age_hours' => 49,
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeTrue();
});

// ── 测试 10：链延长不误报 + 下一环自接 ─────────────────────────────────────────
test('链延长：C(renewed)←S1(reissued)←S2(pending)——C 不误报（S1∉5态），S1 在自身窗口独立命中', function () {
    $user = User::factory()->create(['email' => 'chain@example.com']);
    $product = Product::factory()->create();

    $orderC = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    // C：前驱，renewed，expires_at 在窗口
    $C = Cert::factory()->create([
        'order_id' => $orderC->id,
        'status' => 'renewed',
        'expires_at' => now()->addDays(6)->addHours(12),
        'common_name' => 'chain-C.com',
    ]);
    // S1：C 的接替，但自身又被重签（reissued，∉5态）——C 不应因 S1 命中
    $orderS1 = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $S1 = Cert::factory()->create([
        'order_id' => $orderS1->id,
        'last_cert_id' => $C->id,
        'status' => 'reissued',
        'expires_at' => now()->addDays(2)->addHours(12), // 落 node-3，供 S1 自身作前驱时命中
        'common_name' => 'chain-S1.com',
    ]);
    DB::table('certs')->where('id', $S1->id)->update(['created_at' => now()->subHours(72)]);
    // S2：S1 的接替，pending 停滞
    $orderS2 = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $S2 = Cert::factory()->create([
        'order_id' => $orderS2->id,
        'last_cert_id' => $S1->id,
        'status' => 'pending',
    ]);
    DB::table('certs')->where('id', $S2->id)->update(['created_at' => now()->subHours(72)]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    // 该 user 被派发（因 S1 作前驱命中）——验证锚定的是 S1、不含 C
    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeTrue();

    $domains = collect(stalledPayloadFor($user)->data['certificates'])->pluck('domain')->all();
    expect($domains)->toContain('chain-S1.com')     // S1 到期在即且接替 S2 停滞 → 命中
        ->and($domains)->not->toContain('chain-C.com'); // C 的接替 S1 曾签发成功（∉5态）→ 不误报
});

// ── 测试 11：action='new' 弃单不误伤 ───────────────────────────────────────────
test("action='new' 弃单（latestCert=unpaid 且 last_cert_id=NULL）不派发", function () {
    $user = User::factory()->create(['email' => 'newabandon@example.com']);
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    // 独立新单：unpaid + 无 last_cert_id → 不构成任何前驱的接替
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'unpaid',
        'last_cert_id' => null,
        'action' => 'new',
    ]);
    DB::table('certs')->where('id', $cert->id)->update(['created_at' => now()->subHours(72)]);
    $order->update(['latest_cert_id' => $cert->id]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeFalse();
});

// ── 测试 12（r3/r2-N2）：接替=active（带链）零派发——生产库存量最大形态 ──────────
test('[误伤防护] 已完成续签（前驱 renewed ← 接替 active，带链）零派发——生产最常见形态', function () {
    $user = User::factory()->create(['email' => 'completed@example.com']);
    $product = Product::factory()->create();

    // 前驱 renewed（expires_at 在窗）← 接替 active（带 last_cert_id）：active∉5态 → EXISTS falsy
    makeStalledOrphan($user, $product, [
        'pred_status' => 'renewed',
        'successor_status' => 'active',
        'successor_age_hours' => 72, // 即便年龄足够，active 也必须被状态集排除
        'expires_at' => now()->addDays(6)->addHours(12),
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeFalse();
});

// ── 测试 13（r3/r2-N1）：排除态参数化钉死（cancelled/expired 接替不派发） ─────────
test('[排除表] cancelled/expired 接替（带链、age>48h、前驱在窗）零派发——设计行为非遗漏', function (string $sucStatus) {
    $user = User::factory()->create(['email' => 'excl-'.$sucStatus.'@example.com']);
    $product = Product::factory()->create();

    makeStalledOrphan($user, $product, [
        'pred_status' => 'renewed',
        'successor_status' => $sucStatus,
        'successor_age_hours' => 72,
        'expires_at' => now()->addDays(6)->addHours(12),
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $user->id))->toBeFalse();
})->with(['cancelled', 'expired']);

// ── 测试 14（I2 强制）：派发 ⇒ 可重查 + 双用户隔离 ──────────────────────────────
test('[双侧同源] 派发 ⇒ Builder 可重查非空 + 双用户各自仅含本人条目（防跨用户串邮）', function () {
    $product = Product::factory()->create();

    // 用户 A：unpaid（renew）孤儿
    $userA = User::factory()->create(['email' => 'a@example.com']);
    makeStalledOrphan($userA, $product, [
        'pred_status' => 'renewed',
        'successor_status' => 'unpaid',
        'successor_age_hours' => 72,
        'common_name' => 'a-domain.com',
    ]);

    // 用户 B：processing（reissue）孤儿 + failed（renew）孤儿（多形态）
    $userB = User::factory()->create(['email' => 'b@example.com']);
    makeStalledOrphan($userB, $product, [
        'pred_status' => 'reissued',
        'successor_status' => 'processing',
        'same_order' => true,
        'successor_age_hours' => 72,
        'common_name' => 'b-processing.com',
    ]);
    makeStalledOrphan($userB, $product, [
        'pred_status' => 'renewed',
        'successor_status' => 'failed',
        'successor_age_hours' => 72,
        'common_name' => 'b-failed.com',
    ]);

    $captured = captureExpireDispatch();
    $this->artisan('schedule:expire')->assertSuccessful();

    // 两用户均被派发
    expect(dispatchedCodeForUser($captured, 'cert_renew_stalled', $userA->id))->toBeTrue()
        ->and(dispatchedCodeForUser($captured, 'cert_renew_stalled', $userB->id))->toBeTrue();

    // 派发 ⇒ 可重查非空；且各自仅含本人条目（forUser 的 user 过滤钉死）
    $domainsA = collect(stalledPayloadFor($userA)->data['certificates'])->pluck('domain')->all();
    $domainsB = collect(stalledPayloadFor($userB)->data['certificates'])->pluck('domain')->all();

    expect($domainsA)->toBe(['a-domain.com']);
    expect($domainsB)->toHaveCount(2)
        ->and($domainsB)->toContain('b-processing.com')
        ->and($domainsB)->toContain('b-failed.com')
        ->and($domainsB)->not->toContain('a-domain.com');
    expect($domainsA)->not->toContain('b-processing.com')
        ->and($domainsA)->not->toContain('b-failed.com');
});

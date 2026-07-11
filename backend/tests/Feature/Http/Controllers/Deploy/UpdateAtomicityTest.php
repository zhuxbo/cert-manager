<?php

use App\Exceptions\MutationBusyException;
use App\Models\Cert;
use App\Models\DeployToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Api\Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * O3（Deploy update 续费入口移植 V2 一条龙）守门测试 —— 真实 charge。
 *
 * 资金断言（原子回滚 / commit 吞）走本独立文件，余额一律 withBalance()（Fund 钩子产合规流水），显式登记
 * fundAuditGuardedTestPaths() → afterEach 跑资金 invariant。契约/行为回归用例留 OrderControllerTest.php。
 * 助手函数用 deployAtomic* 前缀避免与 OrderControllerTest 全局函数冲突。
 */
uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/** @return array{0: User, 1: DeployToken} */
function deployAtomicAuth(string $balance = '1000.00'): array
{
    $user = User::factory()->withBalance($balance)->create([
        'auto_settings' => ['auto_renew' => true, 'auto_reissue' => true],
    ]);
    $token = DeployToken::factory()->create(['user_id' => $user->id]);

    return [$user, $token];
}

/** @return array{0: Order, 1: Cert, 2: Product} */
function deployAtomicRenewable(User $user, string $price = '100.00'): array
{
    $product = Product::factory()->create([
        'status' => 1, 'renew' => 1, 'reissue' => 1, 'reuse_csr' => 0, 'source' => 'default',
        'product_type' => Product::TYPE_SSL, 'validation_type' => 'dv',
        'validation_methods' => ['delegation', 'txt', 'http'],
        'common_name_types' => ['standard'], 'alternative_name_types' => ['standard'],
        'periods' => [12], 'standard_min' => 0, 'standard_max' => 1,
        'wildcard_min' => 0, 'wildcard_max' => 0, 'total_min' => 1, 'total_max' => 1,
        'refund_period' => 30,
    ]);
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => $price, 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);

    $domain = 'deploy-'.uniqid().'.example.com';
    $order = Order::factory()->create([
        'user_id' => $user->id, 'product_id' => $product->id,
        'period' => 12, 'amount' => $price, 'auto_renew' => true,
        'period_from' => now()->subYear(), 'period_till' => now()->addDays(5), // ≤15 → 续费
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id, 'common_name' => $domain, 'alternative_names' => $domain,
        'standard_count' => 1, 'wildcard_count' => 0, 'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return [$order, $cert, $product];
}

function deployAtomicUpdate(DeployToken $token, int $orderId): TestResponse
{
    return test()->withHeaders(['Authorization' => "Bearer $token->token"])
        ->postJson('/api/deploy/', ['order_id' => $orderId]);
}

test('O3-A 原子防孤儿：charge 并发失败 → 旧证书回滚保持 active、无新单、余额未变、HTTP 报错', function () {
    [$user, $token] = deployAtomicAuth('1000.00');
    [$order, $cert] = deployAtomicRenewable($user, '100.00');

    // 预检通过后、charge 锁 user 前并发耗尽余额（原生 UPDATE 在 O3 事务内注入）→ charge 二次校验失败 → 整体回滚
    $depleted = false;
    Cert::created(function (Cert $c) use ($user, &$depleted) {
        if ($depleted) {
            return;
        }
        $depleted = true;
        DB::table('users')->where('id', $user->id)->update(['balance' => '0.00']);
    });

    deployAtomicUpdate($token, $order->id)->assertOk()->assertJson(['code' => 0]);

    // 旧证书回滚保持 active、无新续费单、无 charge 流水、余额复原
    expect($cert->fresh()->status)->toBe('active')
        ->and(Order::where('user_id', $user->id)->where('id', '!=', $order->id)->count())->toBe(0)
        ->and(Transaction::where('type', 'order')->count())->toBe(0)
        ->and($user->fresh()->balance)->toBe('1000.00');
});

test('O3-D 余额预检 fail-fast：余额不足直接报错、旧证书未终态化、无新单', function () {
    [$user, $token] = deployAtomicAuth('0.00');
    [$order, $cert] = deployAtomicRenewable($user, '100.00');

    $resp = deployAtomicUpdate($token, $order->id)->assertOk()->assertJson(['code' => 0]);
    expect((string) $resp->json('msg'))->toContain('余额不足');

    // 未进事务：旧证书保持 active、无新单
    expect($cert->fresh()->status)->toBe('active')
        ->and(Order::where('user_id', $user->id)->where('id', '!=', $order->id)->count())->toBe(0)
        ->and($user->fresh()->balance)->toBe('0.00');
});

test('O3-C commit 超时/失败被吞：HTTP 200 + status=pending、扣费保留（下游可 poll 自愈）', function () {
    // 上游 commit 返回 code=0（模拟超时/失败），getData(commit) 吞 → 订单停 pending、已扣费保留
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('renew')->andReturn(['code' => 0, 'msg' => '上游超时']);
    $api->shouldReceive('new')->andReturn(['code' => 0, 'msg' => '上游超时']);
    app()->instance(Api::class, $api);

    [$user, $token] = deployAtomicAuth('1000.00');
    [$order, $cert] = deployAtomicRenewable($user, '100.00');

    $resp = deployAtomicUpdate($token, $order->id)->assertOk()->assertJson(['code' => 1]);
    expect($resp->json('data.status'))->toBe('pending');

    // renew+charge 已原子落库：新单 pending、扣费保留、余额 1000→900
    $newOrder = Order::where('user_id', $user->id)->where('id', '!=', $order->id)->first();
    expect($newOrder)->not->toBeNull()
        ->and($newOrder->latestCert->status)->toBe('pending')
        ->and(Transaction::where('type', 'order')->where('transaction_id', $newOrder->id)->count())->toBe(1)
        ->and($user->fresh()->balance)->toBe('900.00');
    // 旧证书已终态化 renewed（renew 本地部分已提交）
    expect($cert->fresh()->status)->toBe('renewed');
});

test('O3-C commit 抢锁忙被吞：MutationBusyException 不外抛 503、返回 pending、扣费保留', function () {
    // 代理测 getData(commit) 的 MutationBusyException 分支：真实忙锁来自 withMutex，此处令上游 commit 抛
    // 同类型异常，经 commitLocked→withMutex 传播到 getData 被吞（与 code=0 分支同归停 pending 自愈）。
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('renew')->andThrow(new MutationBusyException('order_mutate_x'));
    $api->shouldReceive('new')->andThrow(new MutationBusyException('order_mutate_x'));
    app()->instance(Api::class, $api);

    [$user, $token] = deployAtomicAuth('1000.00');
    [$order, $cert] = deployAtomicRenewable($user, '100.00');

    $resp = deployAtomicUpdate($token, $order->id)->assertOk()->assertJson(['code' => 1]);
    expect($resp->json('data.status'))->toBe('pending');

    $newOrder = Order::where('user_id', $user->id)->where('id', '!=', $order->id)->first();
    expect($newOrder->latestCert->status)->toBe('pending')
        ->and($user->fresh()->balance)->toBe('900.00');
});

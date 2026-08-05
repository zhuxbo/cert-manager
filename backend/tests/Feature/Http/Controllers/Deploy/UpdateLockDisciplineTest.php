<?php

use App\Models\Cert;
use App\Models\DeployToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Order\Api\Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 锁纪律回归：Deploy update 续费/重签时，Action::initParams（CSR keygen +
 * 委托 TXT 上游 DNS 写）必须发生在源订单行 FOR UPDATE 之前——否则 DNSPod 劣化时逐 token 15s
 * 串行会把持锁时长顶过 innodb_lock_wait_timeout=50，同订单 sync/renew 抢锁即 1205（违反锁内
 * 不做上游 HTTP 红线）。
 *
 * 修复：移除 Deploy 事务闭包首行的显式预锁
 *   `Order::...->whereHas('user')->...->lock()->find()`
 * 该预锁是全流程唯一「带 users 存在性子查询 + FOR UPDATE」的 orders 锁定读；renew(persistOrder)/
 * reissue 内部锁只有 `whereHas('latestCert')`（无 users）。故探针：整个续费/重签请求中不得出现
 * 任何「引用 users 的 orders FOR UPDATE」——修复前预锁命中（红），修复后仅剩内部锁（绿）。
 * 该探针对每次 renew/reissue 必触发（不依赖委托是否被继承），且直接绑定被移除的预锁。
 *
 * 路由：POST /api/deploy → Deploy\ApiController@update（route:list 核实）。
 */
beforeEach(function () {
    $this->user = User::factory()->create([
        'balance' => 1000,
        'credit_limit' => 0,
        'auto_settings' => ['auto_renew' => true, 'auto_reissue' => true],
    ]);
    $this->token = DeployToken::factory()->create(['user_id' => $this->user->id]);
    // 仅覆盖标量/扁平列表字段；periods/cost 用工厂默认（[12,24] 扁平列表，Product::getCost 遍历值转字符串，
    // 传 map 形态会「Array to string」）。
    $this->product = Product::factory()->create([
        'status' => 1,
        'renew' => 1,
        'reissue' => 1,
        'validation_methods' => ['delegation', 'txt', 'http'],
    ]);

    // 续费/重签走交易计价路径（getLatestCertAmount）：缺价格行会在锁前抛错、让探针用例前提落空。
    ProductPrice::firstOrCreate(
        ['product_id' => $this->product->id, 'level_code' => 'standard', 'period' => 12],
        ['price' => '10.00', 'alternative_standard_price' => '10.00', 'alternative_wildcard_price' => '20.00'],
    );

    // 上游提交（commit 段，事务外）挡真实 HTTP：renew/reissue 订单的上游提交分发到 Api::renew()/reissue()，
    // 统一返回 code=0 让 getData('commit') 吞掉、订单停 pending（不影响本用例只观测锁前 initParams）。
    $api = Mockery::mock(Api::class);
    foreach (['renew', 'reissue', 'commit', 'get', 'sync', 'cancel', 'revoke'] as $m) {
        $api->shouldReceive($m)->andReturn(['code' => 0, 'msg' => 'mocked-no-upstream']);
    }
    app()->instance(Api::class, $api);
});

afterEach(function () {
    Mockery::close();
});

function makeActiveDeployOrder(User $user, Product $product, int $daysTillExpire = 5): Order
{
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'period_till' => now()->addDays($daysTillExpire), // <15 天 → 续费；否则重签
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'active',
        'alternative_names' => 'example.com',
        'standard_count' => 1,
        'wildcard_count' => 0,
        'action' => 'new',
    ]);

    $order->update(['latest_cert_id' => $cert->id]);

    return $order;
}

/**
 * 断言：委托处理（initParams→handleValidation→CnameDelegationService，访问 cname_delegations）
 * 先于首个 orders 行 FOR UPDATE。委托 TXT 上游写就在此链上；委托表访问不早于订单行锁 = keygen/上游
 * 落进锁内。修复前 Deploy 预锁把 orders FOR UPDATE 抢到 initParams 之前（委托在锁后）→ 红；
 * 修复后仅剩 renew/reissue 内部锁（在 initParams 之后）→ 委托在锁前 → 绿。
 */
function assertDelegationBeforeLock(callable $act): void
{
    $seq = [];
    DB::listen(function ($query) use (&$seq) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'cname_delegations')) {
            $seq[] = 'delegation';
        } elseif (str_contains($sql, 'for update') && str_contains($sql, '`orders`')) {
            $seq[] = 'orders_for_update';
        }
    });

    $act();

    $firstDelegation = array_search('delegation', $seq, true);
    $firstLock = array_search('orders_for_update', $seq, true);

    expect($firstDelegation)->not->toBeFalse('未观测到 cname_delegations 访问，委托探针失效');
    expect($firstLock)->not->toBeFalse('未观测到 orders 行 FOR UPDATE，行锁探针失效');
    expect($firstDelegation)->toBeLessThan(
        $firstLock,
        '委托处理/CSR 生成落在订单行锁内——违反锁内不做上游 HTTP 红线'
    );
}

it('续费：委托处理/CSR 生成在订单行锁之前（keygen+上游 DNS 不在锁内）', function () {
    $order = makeActiveDeployOrder($this->user, $this->product, 5); // 续费

    assertDelegationBeforeLock(function () use ($order) {
        $this->withToken($this->token->token)
            ->postJson('/api/deploy', ['order_id' => $order->id])
            ->assertOk(); // 硬守卫：非 404/500，请求确实进入 update 业务逻辑
    });
})->group('audit-w5');

it('重签：委托处理/CSR 生成在订单行锁之前', function () {
    $order = makeActiveDeployOrder($this->user, $this->product, 60); // >15 天 → 重签

    assertDelegationBeforeLock(function () use ($order) {
        $this->withToken($this->token->token)
            ->postJson('/api/deploy', ['order_id' => $order->id])
            ->assertOk();
    });
})->group('audit-w5');

it('并发翻 renewed：移除 Deploy 预锁后内部 O1 CAS 仍挡下双开（code=0，不逃逸接替单）', function () {
    // 移除 Deploy 显式预锁后，防并发双开由 renew(persistOrder) 内部「源订单行锁 + 前驱翻转
    // affected-rows CAS」承担（对齐 V2 一条龙）。确定性复现「锁内首条 orders FOR UPDATE 后、
    // 前驱被并发翻 renewed」：内部 CAS 命中 status!='active' → affected=0 → code=0 回滚，不建逃逸接替单。
    $order = makeActiveDeployOrder($this->user, $this->product, 5); // 续费
    $cert = $order->latestCert;

    $beforeOrders = Order::count();
    $beforePending = Cert::where('status', 'pending')->count();

    // 用独立连接翻转前驱，避免落进被测事务随其回滚（模拟真正的并发续费已提交）。
    $flipped = false;
    DB::listen(function ($query) use (&$flipped, $cert) {
        if (! $flipped
            && str_contains(strtolower($query->sql), 'for update')
            && str_contains($query->sql, '`orders`')) {
            $flipped = true;
            DB::connection()->getPdo()->exec(
                "update `certs` set `status` = 'renewed' where `id` = {$cert->id}"
            );
        }
    });

    $this->withToken($this->token->token)
        ->postJson('/api/deploy', ['order_id' => $order->id])
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 内部 CAS 挡下：钩子确实触发；无逃逸接替单 / 新 pending 证书
    expect($flipped)->toBeTrue('未触发锁内 FOR UPDATE 钩子，用例前提未成立');
    expect(Order::count())->toBe($beforeOrders);
    expect(Cert::where('status', 'pending')->count())->toBe($beforePending);
})->group('audit-w5');

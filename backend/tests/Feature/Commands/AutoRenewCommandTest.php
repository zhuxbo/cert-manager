<?php

use App\Exceptions\ApiResponseException;
use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Action;
use App\Services\Order\AutoRenewService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    // Mock AutoRenewService 避免真实 DNS 检查
    $this->autoRenewService = Mockery::mock(AutoRenewService::class);
    $this->app->instance(AutoRenewService::class, $this->autoRenewService);

    // Mock NotificationCenter
    $this->notificationCenter = Mockery::mock(NotificationCenter::class);
    $this->app->instance(NotificationCenter::class, $this->notificationCenter);
});

test('签名为 schedule:auto-renew', function () {
    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('无需续费或重签订单时正常退出', function () {
    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('开始自动续费/重签任务')
        ->expectsOutputToContain('自动续费/重签任务完成')
        ->assertSuccessful();
});

test('有续费订单时调用 renew → pay(不提交) → 创建延时 commit', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10), // ≤15天，走续费
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'amount' => '100.00',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')
        ->andReturn(true);

    // A4：配置价格行让续费守卫放行（否则缺价跳过、renew 不被调）
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);

    // Mock Action：renew 和 pay 通过 ApiResponseException 返回成功
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('renew')->once()
        ->andThrow(new ApiResponseException('', null, ['order_id' => $order->id], 1));
    $actionMock->shouldReceive('pay')->once()->with($order->id, false)
        ->andThrow(new ApiResponseException('', null, null, 1));
    $actionMock->shouldReceive('createTask')->once()
        ->with($order->id, 'commit', Mockery::type('int'));
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('计划于')
        ->assertSuccessful();
});

test('有重签订单时处理重签逻辑', function () {
    $user = User::factory()->create([
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => true],
    ]);
    $product = Product::factory()->create(['status' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6), // 订单还有很多余量
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5), // 证书即将到期
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')
        ->andReturn(true);

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('委托检查失败时跳过订单（节点窗口内发失败通知）', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10), // ≤15天，走续费
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(3), // 节点窗口 [now+2, now+3] 内
        'amount' => '100.00',
        'channel' => 'web',
        'common_name' => 'renew.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')
        ->andReturn(false);

    // 无委托 + 节点窗口内 → 应发 auto_renew_failed（避免被 ExpireCommand 排除后两头空）；
    // 通知用 common_name 标识证书（非 order_id）
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(function ($intent) {
            return $intent->code === 'auto_renew_failed'
                && str_contains($intent->context['reason'] ?? '', '委托')
                && ($intent->context['common_name'] ?? null) === 'renew.example.com'
                && ! isset($intent->context['order_id'])
                // 命令不再传 site_url —— 由 AutoRenewFailedNotificationBuilder 从系统设置注入
                && ! isset($intent->context['site_url']);
        }));

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('跳过')
        ->assertSuccessful();
});

test('委托检查失败但不在节点窗口 → 不发失败通知（节点 gate，避免每日重复）', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5), // 非节点（节点为 14/7/3/1）
        'amount' => '100.00',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')
        ->andReturn(false);

    // 非节点窗口：不应发任何通知
    $this->notificationCenter->shouldNotReceive('dispatch');

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('跳过')
        ->assertSuccessful();
});

test('域名含 IP 且在节点窗口 → 发失败通知（兜底归一文案、不泄露 IP 细节，去重铁律 A）', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(7), // 节点窗口 [now+6, now+7] 内
        'alternative_names' => '8.8.8.8',
        'common_name' => '8.8.8.8',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // IP 跳过不会调委托检查
    $this->autoRenewService->shouldNotReceive('checkDelegationValidity');

    // 含 IP 无法自动续签，但在节点窗口 → 应发 auto_renew_failed；
    // 用户端归一为兜底文案，不泄露 IP/系统细节
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(function ($intent) {
            return $intent->code === 'auto_renew_failed'
                && str_contains($intent->context['reason'] ?? '', '自动续签未成功')
                && ! str_contains($intent->context['reason'] ?? '', 'IP');
        }));

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('域名包含 IP 地址')
        ->assertSuccessful();
});

test('余额不足时续费失败发送通知', function () {
    $user = User::factory()->withBalance('0.00')->create([
        'credit_limit' => '0.00',
    ]);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    // 配齐定价（level=standard / period=12 / 价 100），让续费估价 >0 真正触发余额分支
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10), // ≤15天，走续费
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(3),
        'amount' => '100.00',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')
        ->andReturn(true);

    // 余额不足 → 跳过并发清晰可行动文案（不暴露内部估价数字）
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(function ($intent) {
            return $intent->code === 'auto_renew_failed'
                && str_contains($intent->context['reason'] ?? '', '余额不足');
        }));

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('跳过')
        ->assertSuccessful();
});

test('上游/系统错误 → 发失败通知但归一文案、不泄露原始异常（兜底安全网）', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(3), // 节点窗口内
        'amount' => '100.00',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // A4：配置价格行让续费守卫放行（缺价会在余额/renew 之前跳过）
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);

    // renew 抛上游错误（无 order_id）→ 命令转 \Exception('上游下单失败xyz')
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('renew')->once()
        ->andThrow(new ApiResponseException('上游下单失败xyz', null, null, 0));
    $this->app->bind(Action::class, fn () => $actionMock);

    // 用户端只看到归一兜底文案，不泄露原始系统异常；原始异常仍进 cron 日志
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(function ($intent) {
            return $intent->code === 'auto_renew_failed'
                && str_contains($intent->context['reason'] ?? '', '自动续签未成功')
                && ! str_contains($intent->context['reason'] ?? '', '上游下单失败xyz');
        }));

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('失败') // 原始异常进 cron 日志供运维排查
        ->assertSuccessful();
});

test('域名包含 IP 地址时跳过订单', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'alternative_names' => '8.8.8.8',
        'common_name' => '8.8.8.8',
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 不应调用委托检查和 Action
    $this->autoRenewService->shouldNotReceive('checkDelegationValidity');

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('域名包含 IP 地址')
        ->assertSuccessful();
});

test('A3：smime 重签单被选单 SQL 过滤排除 → 不调 reissue、不建委托', function () {
    // A3 选单过滤：getReissueOrders 的 whereHas(product) 加 ssl 白名单，非 ssl 不进选单。
    $user = User::factory()->create([
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => true],
    ]);
    $product = Product::factory()->create(['status' => 1, 'reissue' => 1, 'product_type' => 'smime']);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6), // >15 天，本应走重签
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5), // 证书临期
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 被 SQL 排除 → 根本不进 processOrder，故不调委托检查、不调 reissue
    $this->autoRenewService->shouldNotReceive('checkDelegationValidity');
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldNotReceive('reissue');
    $actionMock->shouldNotReceive('renew');
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('自动续费构造的 params 不含 encryption（依赖后端 initParams 继承，不注入降级默认）', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1, 'reuse_csr' => 0]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'amount' => '100.00',
        'channel' => 'web',
        'encryption_alg' => 'ECDSA',
        'encryption_bits' => 256,
        'signature_digest_alg' => 'SHA256',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // A4：配置价格行让续费守卫放行
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);

    $captured = null;
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('renew')->once()
        ->andReturnUsing(function ($params) use (&$captured, $order) {
            $captured = $params;
            throw new ApiResponseException('', null, ['order_id' => $order->id], 1);
        });
    $actionMock->shouldReceive('pay')->once()->with($order->id, false)
        ->andThrow(new ApiResponseException('', null, null, 1));
    $actionMock->shouldReceive('createTask')->once()
        ->with($order->id, 'commit', Mockery::type('int'));
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    expect($captured)->not->toBeNull();
    expect(isset($captured['encryption']))->toBeFalse();
    expect($captured['csr_generate'] ?? null)->toBe(1);
});

// ==================== A2：余额不足独立去重键（脱离节点 gate） ====================

/** A2 余额不足单公共装配：balance 0 + credit 0 + 价格行 100 + 指定 expires_at，触发余额分支 */
function makeBalanceShortOrder(object $test, $expiresAt, ?User $user = null): array
{
    $user ??= User::factory()->withBalance('0.00')->withAutoRenew()->create(['credit_limit' => '0.00']);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(14),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => $expiresAt,
        'channel' => 'web',
        'common_name' => 'shortbalance.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return [$user, $product, $order, $cert];
}

test('A2：余额不足 + 非节点日 → 仍发（脱离节点 gate，独立去重键首发）', function () {
    // 现状（节点 gate）非节点日不发；A2 改独立去重后首发（Cache::add 成功）→ 本用例改前红
    [$user] = makeBalanceShortOrder($this, now()->addDays(5)); // 非节点（节点 14/7/3/1）
    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'auto_renew_failed'
            && str_contains($intent->context['reason'] ?? '', '余额不足')));

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('A2：余额不足 N 天内复跑 → 不重发（per-user cache 去重）', function () {
    [$user] = makeBalanceShortOrder($this, now()->addDays(12)); // 非节点，两轮均非 final-window
    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // 两轮合计仅一封
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'auto_renew_failed'));

    $this->artisan('schedule:auto-renew')->assertSuccessful();
    Carbon::setTestNow(now()->addDay()); // 推进 1 天（< 3 天间隔）
    $this->artisan('schedule:auto-renew')->assertSuccessful();
    Carbon::setTestNow();
});

test('A2：余额不足推进 > N 天 → 再发（去重键 TTL 到期）', function () {
    [$user] = makeBalanceShortOrder($this, now()->addDays(12)); // 推进 4 天后 now+8，仍非节点
    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    $this->notificationCenter->shouldReceive('dispatch')->twice()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'auto_renew_failed'));

    $this->artisan('schedule:auto-renew')->assertSuccessful();
    Carbon::setTestNow(now()->addDays(4)); // > 3 天间隔，键已过期
    $this->artisan('schedule:auto-renew')->assertSuccessful();
    Carbon::setTestNow();
});

test('A2：同用户 3 张余额不足单 → 只发一封（per-user 去重防风暴）', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create(['credit_limit' => '0.00']);
    for ($i = 0; $i < 3; $i++) {
        makeBalanceShortOrder($this, now()->addDays(5), $user); // 同用户，非节点
    }
    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // per-user 键 → 3 单只一封
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'auto_renew_failed'));

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('A2：final-window（到期≤1天）即使去重键存续也豁免必发（I2 兜底必达下界）', function () {
    [$user] = makeBalanceShortOrder($this, now()->addHours(12)); // 节点 1 窗口 [now, now+1]
    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // 预置去重键（模拟 day-12 已发过）——final-window 应豁免、仍发
    Cache::put("auto_renew_balance_notified:{$user->id}", true, now()->addDays(3));

    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'auto_renew_failed'
            && str_contains($intent->context['reason'] ?? '', '余额不足')));

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('A2：余额充足（续费成功路径）清除欠费去重键 → 恢复后再欠费不被陈旧键抑制', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 预置欠费去重键（模拟之前欠费发过信）
    $key = "auto_renew_balance_notified:{$user->id}";
    Cache::put($key, true, now()->addDays(3));
    expect(Cache::has($key))->toBeTrue();

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('renew')->once()
        ->andThrow(new ApiResponseException('', null, ['order_id' => $order->id], 1));
    $actionMock->shouldReceive('pay')->once()->with($order->id, false)
        ->andThrow(new ApiResponseException('', null, null, 1));
    $actionMock->shouldReceive('createTask')->once()
        ->with($order->id, 'commit', Mockery::type('int'));
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 余额充足 → 健康分支清键；恢复后再欠费立即发（键已清）
    expect(Cache::has($key))->toBeFalse();
});

test('A2 护栏：委托失败仍受节点 gate（非节点日不发，只改余额分支）', function () {
    // 与 A2 余额分支对照：委托失败走 sendFailureNotification 节点 gate，非节点日不发（现状保持）
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5), // 非节点
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(false);

    // 委托失败 + 非节点 → 不发（节点 gate 未变）
    $this->notificationCenter->shouldNotReceive('dispatch');

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

// ==================== A4：零价成单守卫 ====================

test('A4：续费单缺价格行 → 不调 renew/pay，发兜底通知 + SystemAlert 缺价告警', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    // 故意不建任何 ProductPrice → 缺价
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(3), // 节点窗口 [now+2,now+3] → 兜底通知会发
        'channel' => 'web',
        'common_name' => 'missingprice.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // SystemAlert 容器 mock：缺价 → 以 missing_price + 固定指纹 + 72h + (product,period) 去重键调用一次
    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('send')->once()
        ->with(
            'missing_price',
            Mockery::type('string'),
            Mockery::type('string'),
            Mockery::on(fn ($details) => (int) $details['product_id'] === $product->id
                && (int) $details['period'] === (int) $order->period
                && $details['level_code'] === 'standard'
                && (int) $details['sample_order_id'] === $order->id),
            "missing_price:{$product->id}:{$order->period}",
            72,
            'missing'
        )
        ->andReturnTrue();
    $systemAlert->shouldNotReceive('clearDedupe');
    $this->app->instance(SystemAlert::class, $systemAlert);

    // 缺价 → 不建单：renew/pay 不被调
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldNotReceive('renew');
    $actionMock->shouldNotReceive('pay');
    $this->app->bind(Action::class, fn () => $actionMock);

    // 用户端发兜底文案（节点窗口内），非用户可行动、不暴露内部
    $this->notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'auto_renew_failed'
            && str_contains($intent->context['reason'] ?? '', '自动续签未成功')));

    $this->artisan('schedule:auto-renew')
        ->expectsOutputToContain('价格未配置')
        ->assertSuccessful();
});

test('A4：续费单有价格行（含 price=0.00 真免费）→ 正常 renew→pay→createTask + clearDedupe', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    // 显式免费产品：行存在 price=0.00 → 守卫放行（不误伤真 0 元）
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '0.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // healthy → clearDedupe 被调（复位再次缺价立即告警），send 不被调
    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('clearDedupe')->once()->with("missing_price:{$product->id}:{$order->period}");
    $systemAlert->shouldNotReceive('send');
    $this->app->instance(SystemAlert::class, $systemAlert);

    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('renew')->once()
        ->andThrow(new ApiResponseException('', null, ['order_id' => $order->id], 1));
    $actionMock->shouldReceive('pay')->once()->with($order->id, false)
        ->andThrow(new ApiResponseException('', null, null, 1));
    $actionMock->shouldReceive('createTask')->once()
        ->with($order->id, 'commit', Mockery::type('int'));
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

test('A4：重签单缺价格行 → 不受守卫影响（守卫仅 renew），SystemAlert 不被调', function () {
    $user = User::factory()->create(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = Product::factory()->create(['status' => 1, 'reissue' => 1]); // ssl，无价格行
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6), // >15 天 → 走重签
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // 守卫仅 renew → reissue 路径 SystemAlert 完全不被调（send/clearDedupe 皆不）
    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldNotReceive('send');
    $systemAlert->shouldNotReceive('clearDedupe');
    $this->app->instance(SystemAlert::class, $systemAlert);

    // reissue 照常被调（守卫不拦重签）
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('reissue')->once()
        ->andThrow(new ApiResponseException('', null, ['order_id' => $order->id], 1));
    $actionMock->shouldReceive('pay')->once()->with($order->id, false)
        ->andThrow(new ApiResponseException('', null, null, 1));
    $actionMock->shouldReceive('createTask')->once()
        ->with($order->id, 'commit', Mockery::type('int'));
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();
});

// ===== 过期防御 + pull scheduler 签发失败自写记录 =====

test('过期防御：证书已过期（expires_at < now 但 status 仍 active）的订单不再自动重签', function () {
    // ExpireCommand（09:00）尚未把 active 翻 expired 前的时序缝：00:00 auto-renew 靠 expires_at>=now 兜底
    $user = User::factory()->create(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = Product::factory()->create(['status' => 1, 'reissue' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6), // >15 天 → 本应走重签
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->subDay(), // 已过期
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 过期防御生效：不被选单 → renew/reissue 均不被调、无失败记录
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldNotReceive('reissue');
    $actionMock->shouldNotReceive('renew');
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

test('pull scheduler 自动重签失败 → 服务端自写 ip 留空的签发失败记录', function () {
    $user = User::factory()->create(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = Product::factory()->create(['status' => 1, 'reissue' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6), // >15 天 → 重签
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5), // 即将到期、未过期、非节点窗口（避免用户兜底通知 dispatch）
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // reissue 抛业务失败（无 data.order_id）→ 内层 rethrow → processOrders catch → 服务端自写签发失败记录
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('reissue')->once()
        ->andThrow(new ApiResponseException('上游拒绝', null, null, 0));
    $actionMock->shouldNotReceive('pay');
    $actionMock->shouldNotReceive('createTask');
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    $report = AutoDeployReport::where('order_id', $order->id)->sole();
    expect($report->status)->toBe('failure')
        ->and($report->cert_id)->toBe($cert->id)
        ->and($report->ip)->toBeNull()
        ->and($report->message)->toStartWith('自动重签失败：');
});

test('pull scheduler 自动重签成功且失败在案 → 服务端自写恢复行并清去重键', function () {
    $user = User::factory()->create(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = Product::factory()->create(['status' => 1, 'reissue' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6), // >15 天 → 重签
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 失败在案：前一轮失败行 + 去重键已置（M1 场景：无客户端回调的 web 订单）
    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'failure',
        'ip' => null,
        'message' => '自动重签失败：系统处理异常',
    ]);
    Cache::put("system_alert:deploy_failure_{$order->id}", 'deploy_failure', 3600);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    // reissue 成功信号：ApiResponseException code=1 携 data.order_id（同订单）→ pay → createTask 延时 commit
    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('reissue')->once()
        ->andThrow(new ApiResponseException('', null, ['order_id' => $order->id], 1));
    $actionMock->shouldReceive('pay')->once()->with($order->id, false);
    $actionMock->shouldReceive('createTask')->once();
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 恢复行已写（最后一条转 success，reminder 状态判定收敛）+ 去重键已清（复发立即再告警）
    $recovery = AutoDeployReport::where('order_id', $order->id)->orderByDesc('id')->first();
    expect($recovery->status)->toBe('success')
        ->and($recovery->ip)->toBeNull()
        ->and($recovery->message)->toBe('自动重签成功：前次失败已恢复')
        ->and(AutoDeployReport::where('order_id', $order->id)->count())->toBe(2)
        ->and(Cache::get("system_alert:deploy_failure_{$order->id}"))->toBeNull();
});

test('pull scheduler 自动重签成功但无失败在案 → 不写恢复行（避免全量成功噪音）', function () {
    $user = User::factory()->create(['auto_settings' => ['auto_renew' => false, 'auto_reissue' => true]]);
    $product = Product::factory()->create(['status' => 1, 'reissue' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_reissue' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addMonths(6),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(5),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->autoRenewService->shouldReceive('checkDelegationValidity')->andReturn(true);

    $actionMock = Mockery::mock(Action::class);
    $actionMock->shouldReceive('reissue')->once()
        ->andThrow(new ApiResponseException('', null, ['order_id' => $order->id], 1));
    $actionMock->shouldReceive('pay')->once()->with($order->id, false);
    $actionMock->shouldReceive('createTask')->once();
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

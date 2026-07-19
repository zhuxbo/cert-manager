<?php

use App\Models\Cert;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use Database\Seeders\NotificationTemplateSeeder;

afterEach(function () {
    Mockery::close();
});

test('balance_forecast 模板渲染无异常且外部文本 Blade 转义（防 XSS 进邮箱）', function () {
    (new NotificationTemplateSeeder)->run();
    $template = NotificationTemplate::where('code', 'balance_forecast')->first();
    expect($template)->not->toBeNull();

    $out = $template->render([
        'available' => '50.00',
        'required' => '200.00',
        'shortfall' => '150.00',
        'site_url' => 'https://console.example.com',
        'certificates' => [
            ['common_name' => 'a.example.com', 'expires_at' => '2026-08-01', 'amount' => '100.00'],
            // 域名位置注入脚本，验证 Blade {{ }} 转义
            ['common_name' => '<script>alert(1)</script>', 'expires_at' => '2026-08-05', 'amount' => '100.00'],
        ],
    ]);

    expect($out)->toContain('200.00')
        ->and($out)->toContain('a.example.com')
        ->and($out)->toContain('预计最多需要')
        ->and($out)->not->toContain('<script>alert(1)</script>')
        ->and($out)->toContain('&lt;script&gt;');
});

/**
 * 装配一张「30 天内到期、续费轨道、ssl、非 api」的前瞻候选单，价格行默认 100。
 *
 * @return array{0: Product, 1: Order, 2: Cert}
 */
function makeForecastOrder(User $user, array $opts = []): array
{
    $product = Product::factory()->create(array_merge(['status' => 1, 'renew' => 1], $opts['product'] ?? []));
    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code,
        'period' => 12,
        'price' => $opts['price'] ?? '100.00',
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ]);
    $order = Order::factory()->create(array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_from' => now()->subYear(),
        'period_till' => now()->addDays(10), // ≤15 天 → 续费轨道
    ], $opts['order'] ?? []));
    $cert = Cert::factory()->active()->create(array_merge([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(20), // < 30 天前瞻窗口
        'channel' => 'web',
        'standard_count' => 1,
        'wildcard_count' => 0,
        'common_name' => $opts['cn'] ?? 'fc.example.com',
    ], $opts['cert'] ?? []));
    $order->update(['latest_cert_id' => $cert->id]);

    return [$product, $order, $cert];
}

test('签名为 schedule:balance-forecast 可运行', function () {
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('余额充足用户 → 不发前瞻通知', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create(['email' => 'rich@example.com']);
    makeForecastOrder($user); // required 100 < 可用 1000

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('单用户多单不足 → 只发一封，certificates 含全部单，required=各单估价之和', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => 'poor@example.com', 'credit_limit' => '0.00',
    ]);
    makeForecastOrder($user, ['cn' => 'a.example.com']);
    makeForecastOrder($user, ['cn' => 'b.example.com']);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(function ($intent) use ($user) {
            return $intent->code === 'balance_forecast'
                && $intent->notifiableId === $user->id
                && $intent->context['required'] === '200.00'
                && $intent->context['available'] === '0.00'
                && $intent->context['shortfall'] === '200.00'
                && count($intent->context['certificates']) === 2;
        }));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('免费重签排除（I1）：period_till > 15 天的临期单不计入 required、不发', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => 'reissue@example.com', 'credit_limit' => '0.00',
    ]);
    // period_till now+20（>15）→ 当前走免费重签，forecast 不应计入
    makeForecastOrder($user, [
        'order' => ['period_till' => now()->addDays(20)],
        'cert' => ['expires_at' => now()->addDays(10)],
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('credit_limit 授信：balance 不足但 balance+|credit_limit| ≥ required → 不发', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => 'credit@example.com', 'credit_limit' => '-500.00',
    ]);
    makeForecastOrder($user); // required 100，可用 = 0 + |−500| = 500 ≥ 100

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('ssl 过滤：同用户 smime 到期单不进 required 聚合（只计 ssl）', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => 'mix@example.com', 'credit_limit' => '0.00',
    ]);
    makeForecastOrder($user, ['cn' => 'ssl.example.com']); // ssl 100
    makeForecastOrder($user, ['cn' => 'smime.example.com', 'product' => ['product_type' => 'smime']]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // 只计 ssl → required 100、certificates 仅 1 张
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(function ($intent) {
            return $intent->code === 'balance_forecast'
                && $intent->context['required'] === '100.00'
                && count($intent->context['certificates']) === 1;
        }));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('过期防御（与 getRenewOrders 同构）：已过期证书（expires_at < now 但 status 仍 active）不计入前瞻', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => 'expired@example.com', 'credit_limit' => '0.00',
    ]);
    makeForecastOrder($user, ['cert' => ['expires_at' => now()->subDay()]]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('channel=api 单不计入前瞻', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => 'api@example.com', 'credit_limit' => '0.00',
    ]);
    makeForecastOrder($user, ['cert' => ['channel' => 'api']]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

test('无 email 用户跳过', function () {
    $user = User::factory()->withBalance('0.00')->withAutoRenew()->create([
        'email' => null, 'credit_limit' => '0.00',
    ]);
    makeForecastOrder($user);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:balance-forecast')->assertSuccessful();
});

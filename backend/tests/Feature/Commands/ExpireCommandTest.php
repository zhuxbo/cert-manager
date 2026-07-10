<?php

use App\Models\Acme;
use App\Models\Cert;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\NotificationCenter;

test('标记已过期的证书状态为 expired', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'active',
        'expires_at' => now()->subDay(),
    ]);

    // Mock NotificationCenter
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    $cert->refresh();
    expect($cert->status)->toBe('expired');
});

test('未过期的证书状态不变', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(30),
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    $cert->refresh();
    expect($cert->status)->toBe('active');
});

test('即将到期的证书发送通知（14天内）', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    // 13-14 天后到期的证书应该触发通知
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(13)->addHours(12),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->atLeast()->once();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('订单到期但证书未到期时不标记 expired', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->subDay(),
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'active',
        'expires_at' => now()->addDays(30),
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    $cert->refresh();
    expect($cert->status)->toBe('active');
});

test('订单到期且证书也到期时标记 expired', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->subDay(),
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'processing',
        'expires_at' => now()->subDay(),
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    $cert->refresh();
    expect($cert->status)->toBe('expired');
});

test('订单到期且证书无到期时间时标记 expired', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->subDay(),
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'processing',
        'expires_at' => null,
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    $cert->refresh();
    expect($cert->status)->toBe('expired');
});

test('无过期证书时正常退出', function () {
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('终态证书的 csr/private_key/cert 被清空', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $terminal = ['expired', 'cancelled', 'revoked', 'renewed', 'reissued', 'failed'];
    $terminalCerts = [];
    foreach ($terminal as $status) {
        $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        $terminalCerts[] = Cert::factory()->create([
            'order_id' => $order->id,
            'status' => $status,
            'csr' => 'csr-'.$status,
            'private_key' => 'pk-'.$status,
            'cert' => 'pem-'.$status,
        ]);
    }

    // 活跃单保留敏感字段
    $activeOrder = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $activeCert = Cert::factory()->active()->create([
        'order_id' => $activeOrder->id,
        'expires_at' => now()->addDays(30),
        'csr' => 'csr-active',
        'private_key' => 'pk-active',
        'cert' => 'pem-active',
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    foreach ($terminalCerts as $cert) {
        $cert->refresh();
        expect($cert->csr)->toBeNull()
            ->and($cert->private_key)->toBeNull()
            ->and($cert->cert)->toBeNull();
    }

    $activeCert->refresh();
    expect($activeCert->csr)->toBe('csr-active')
        ->and($activeCert->private_key)->toBe('pk-active')
        ->and($activeCert->cert)->toBe('pem-active');
});

test('刚标记为 expired 的证书在同一次执行中也会被清理敏感字段', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'active',
        'expires_at' => now()->subDay(),
        'csr' => 'csr-just-expired',
        'private_key' => 'pk-just-expired',
        'cert' => 'pem-just-expired',
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    $cert->refresh();
    expect($cert->status)->toBe('expired')
        ->and($cert->csr)->toBeNull()
        ->and($cert->private_key)->toBeNull()
        ->and($cert->cert)->toBeNull();
});

test('去重：开启自动续费的非 api 订单不发 cert_expire（交给 AutoRenewCommand）', function () {
    // B2 前置：排除依赖 auto_renew_failed 模板启用（默认生产态 status=1）。停用时 B2 改为回落发 cert_expire。
    NotificationTemplate::create(['code' => 'auto_renew_failed', 'name' => '自动续费失败', 'content' => 'x', 'status' => 1]);

    // 开 auto_renew + 非 api + period_till ≤15 天 → willAutoRenewExecute=true → ExpireCommand 排除
    $user = User::factory()->create(['email' => 'auto@example.com']);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_till' => now()->addDays(10), // ≤15 走续费
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(7), // 节点窗口内
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // 被排除 → 不应发 cert_expire
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('B2：auto_renew_failed 模板停用时自动续签订单回落发 cert_expire（防两头空静默过期）', function () {
    // auto_renew_failed 停用（status=0）→ AutoRenewCommand 发不出失败通知；ExpireCommand 若仍排除则两头空。
    // B2：排除前置 gate 于模板启用，停用时不排除 → 照发 cert_expire（回落，方向安全=少排除）。
    NotificationTemplate::create(['code' => 'auto_renew_failed', 'name' => '自动续费失败', 'content' => 'x', 'status' => 0]);

    $user = User::factory()->create(['email' => 'fallback@example.com']);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(7),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // 模板停用 → 不排除 → 照发 cert_expire（回落）
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'cert_expire'));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('去重：未开自动续费的订单照常发 cert_expire', function () {
    $user = User::factory()->create([
        'email' => 'manual@example.com',
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
    ]);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => false,
        'auto_reissue' => false,
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(7),
        'channel' => 'web',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // 未开 auto → 照常发
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'cert_expire'));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('去重铁律 B：api channel 即使开 auto 也照常发 cert_expire（AutoRenewCommand 不处理 api，防漏发）', function () {
    // 开 auto_renew 但 channel=api → AutoRenewCommand getRenewOrders 排除它（不处理）
    // → ExpireCommand 必须照常发，否则两头空（杀手场景）
    $user = User::factory()->create(['email' => 'api@example.com']);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_till' => now()->addDays(10),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(7),
        'channel' => 'api', // 下游控制
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // api channel 不被排除 → 照常发 cert_expire
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'cert_expire'));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('去重：用户同时有 auto 订单和手动订单 → 仍发一次 cert_expire（手动订单未被排除）', function () {
    $user = User::factory()->create(['email' => 'mix@example.com']);
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);

    // auto 订单（被排除）
    $autoOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => true,
        'period_till' => now()->addDays(10),
    ]);
    $autoCert = Cert::factory()->active()->create([
        'order_id' => $autoOrder->id,
        'expires_at' => now()->addDays(7),
        'channel' => 'web',
    ]);
    $autoOrder->update(['latest_cert_id' => $autoCert->id]);

    // 手动订单（未被排除）
    $manualOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'auto_renew' => false,
        'auto_reissue' => false,
        'period_till' => now()->addDays(10),
    ]);
    $manualCert = Cert::factory()->active()->create([
        'order_id' => $manualOrder->id,
        'expires_at' => now()->addDays(3),
        'channel' => 'web',
    ]);
    $manualOrder->update(['latest_cert_id' => $manualCert->id]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // 同一用户去重 → 一次（因手动订单存在）
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'cert_expire' && $intent->notifiableId === $user->id));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

// --- B1: ACME 订阅到期提醒 + set-expired ---

test('B1：active ACME 订阅 period_till 落 14 天节点窗口 → 派发 acme_expire', function () {
    $user = User::factory()->create(['email' => 'acme@example.com']);
    $product = Product::factory()->create();
    Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(13)->addHours(12), // 节点 14 窗口 [now+13, now+14]
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->once()
        ->with(Mockery::on(fn ($intent) => $intent->code === 'acme_expire' && $intent->notifiableId === $user->id));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

test('B1：active ACME 订阅 period_till 已过 → 置 expired（纯本地簿记，不触碰 EAB/上游）', function () {
    $user = User::factory()->create(['email' => 'acme2@example.com']);
    $product = Product::factory()->create();
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->subDay(),
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    // eab_kid/eab_hmac 不变（EAB 未触碰），仅 status 置 expired
    $fresh = $acme->fresh();
    expect($fresh->status)->toBe('expired');
    expect($fresh->eab_kid)->toBe($acme->eab_kid);
});

test('B1：active ACME 订阅 period_till 在 14 天窗口外（30 天后）→ 不派发、不改状态', function () {
    $user = User::factory()->create(['email' => 'acme3@example.com']);
    $product = Product::factory()->create();
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(30),
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    expect($acme->fresh()->status)->toBe('active');
});

test('B1：cancelled/expired 状态的 ACME 不受 set-expired / 节点通知影响', function () {
    $user = User::factory()->create(['email' => 'acme4@example.com']);
    $product = Product::factory()->create();
    // 已取消订阅（period_till 落窗口内也不应派发 / 不应被 set-expired 覆盖）
    $cancelled = Acme::factory()->cancelled()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(7),
    ]);

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    expect($cancelled->fresh()->status)->toBe('cancelled');
});

test('多个到期时间段的证书都会触发通知', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);
    $product = Product::factory()->create();

    // 创建不同到期时间段的订单/证书
    $timeRanges = [
        now()->addDays(13)->addHours(12), // 14天区段
        now()->addDays(6)->addHours(12),  // 7天区段
        now()->addDays(2)->addHours(12),  // 3天区段
        now()->addHours(12),               // 1天区段
    ];

    foreach ($timeRanges as $expiresAt) {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $cert = Cert::factory()->active()->create([
            'order_id' => $order->id,
            'expires_at' => $expiresAt,
        ]);
        $order->update(['latest_cert_id' => $cert->id]);
    }

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    // 同一用户只发一次通知（去重）
    $notificationCenter->shouldReceive('dispatch')->once();
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();
});

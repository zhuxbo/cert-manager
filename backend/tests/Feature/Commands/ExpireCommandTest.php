<?php

use App\Models\Cert;
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

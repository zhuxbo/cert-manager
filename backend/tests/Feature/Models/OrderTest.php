<?php

use App\Models\Cert;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;

test('订单属于用户', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    expect($order->user)->toBeInstanceOf(User::class);
    expect($order->user->id)->toBe($user->id);
});

test('订单属于产品', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    expect($order->product)->toBeInstanceOf(Product::class);
    expect($order->product->id)->toBe($product->id);
});

test('订单有多个证书', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    Cert::factory()->count(3)->create(['order_id' => $order->id]);

    expect($order->certs)->toHaveCount(3);
});

test('订单关联最新证书', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create(['order_id' => $order->id]);
    $order->update(['latest_cert_id' => $cert->id]);
    $order->refresh();

    expect($order->latestCert)->toBeInstanceOf(Cert::class);
    expect($order->latestCert->id)->toBe($cert->id);
});

test('订单有多态通知关联', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    Notification::factory()->create([
        'notifiable_type' => Order::class,
        'notifiable_id' => $order->id,
    ]);

    expect($order->notifications)->toHaveCount(1);
});

test('datetime 字段正确转换', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $periodFrom = now()->subYear();
    $periodTill = now()->addYear();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_from' => $periodFrom,
        'period_till' => $periodTill,
    ]);

    $order->refresh();
    expect($order->period_from)->toBeInstanceOf(Carbon::class);
    expect($order->period_till)->toBeInstanceOf(Carbon::class);
});

test('amount 字段为 decimal:2 格式', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'amount' => '123.456',
    ]);

    $order->refresh();
    expect($order->amount)->toBe('123.46');
});

test('organization 和 contact 为 JSON cast', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->withOrganization()->withContact()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $order->refresh();
    expect($order->organization)->toBeArray();
    expect($order->contact)->toBeArray();
});

test('cancelled_at 字段正确转换为 datetime', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->cancelled()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $order->refresh();
    expect($order->cancelled_at)->toBeInstanceOf(Carbon::class);
});

test('period 字段为整数', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => '12',
    ]);

    $order->refresh();
    expect($order->period)->toBeInt();
    expect($order->period)->toBe(12);
});

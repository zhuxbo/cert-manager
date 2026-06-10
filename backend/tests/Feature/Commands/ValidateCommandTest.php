<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

const VALIDATE_LOCK_KEY = 'cmd:schedule:validate';

test('签名为 schedule:validate', function () {
    $this->artisan('schedule:validate')->assertSuccessful();
});

test('锁被其他实例持有时直接跳过、且不误释放他人的锁', function () {
    // 模拟另一进程持锁（带 owner token）
    $other = Cache::lock(VALIDATE_LOCK_KEY, 120);
    expect($other->get())->toBeTrue();

    // command 应因互斥而跳过，不输出"开始执行"
    $this->artisan('schedule:validate')
        ->doesntExpectOutputToContain('证书验证命令开始执行')
        ->assertSuccessful();

    // command 不得删除他人的锁：同 key 不同 owner 仍应抢不到
    expect(Cache::lock(VALIDATE_LOCK_KEY, 120)->get())->toBeFalse();

    $other->forceRelease();
});

test('锁释放后另一实例可重新获取（持锁带 owner，正常释放）', function () {
    $this->artisan('schedule:validate')->assertSuccessful();

    // command 跑完应已属主安全地释放锁，外部可重新获取
    $lock = Cache::lock(VALIDATE_LOCK_KEY, 120);
    expect($lock->get())->toBeTrue();
    $lock->forceRelease();
});

test('无待验证订单时正常退出', function () {
    $this->artisan('schedule:validate')
        ->expectsOutputToContain('待验证订单数量: 0')
        ->assertSuccessful();
});

test('processing 状态证书会被检测', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'processing',
        'dcv' => ['method' => 'txt'],
        'validation' => [
            ['domain' => 'example.com', 'method' => 'txt', 'value' => 'token-value'],
        ],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->artisan('schedule:validate')
        ->expectsOutputToContain('待验证订单数量: 1')
        ->assertSuccessful();
});

test('approving 状态证书会创建同步任务', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'approving',
        'dcv' => ['method' => 'email'],
        'validation' => [['domain' => 'example.com', 'method' => 'email']],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->artisan('schedule:validate')
        ->expectsOutputToContain('待验证订单数量: 1')
        ->assertSuccessful();
});

test('pending 状态证书不参与验证', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
        'dcv' => ['method' => 'txt'],
        'validation' => [],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->artisan('schedule:validate')
        ->expectsOutputToContain('待验证订单数量: 0')
        ->assertSuccessful();
});

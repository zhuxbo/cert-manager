<?php

use App\Models\Acme;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\DB;

// 收敛护栏：cert_expire / cert_renew_stalled / acme_expire 三派发循环共用 dispatchExpiryNotifications
// 批量加载消 N+1。本护栏用 DB::listen 证明「按单 id 查 users」的 N+1 签名归零（改前每用户一条 find），
// 且派发计数不变（行为等价）。收件人闸门（email 判空）单点。

test('到期派发批量加载：零单-id users 查询（N+1 消除）+ 派发计数等价', function () {
    // 3 个手动到期用户（auto 全关 → 不被 willBeHandledByAutoRenew 排除 → 发 cert_expire）
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);
    for ($i = 0; $i < 3; $i++) {
        $user = User::factory()->create([
            'email' => "manual$i@example.com",
            'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'auto_renew' => false,
            'auto_reissue' => false,
            'period_till' => now()->addDays(10),
        ]);
        $cert = Cert::factory()->active()->create([
            'order_id' => $order->id,
            'expires_at' => now()->addDays(7), // 节点窗口内
            'channel' => 'web',
        ]);
        $order->update(['latest_cert_id' => $cert->id]);
    }

    // 2 个 ACME 到期用户 → 发 acme_expire
    for ($i = 0; $i < 2; $i++) {
        $acmeUser = User::factory()->create(['email' => "acme$i@example.com"]);
        Acme::factory()->active()->create([
            'user_id' => $acmeUser->id,
            'product_id' => $product->id,
            'period_till' => now()->addDays(7),
        ]);
    }

    // 派发计数（顺带断言 code 分布）
    $dispatched = [];
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use (&$dispatched) {
        $dispatched[] = $intent->code;
    });
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    // N+1 签名：对 users 表按单 id 精确查询（User::find 生成 `where `users`.`id` = ?`）。
    // 批量 whereIn 生成 `id` in (...)，eager load 亦 in，whereHas 是相关子查询（= `users`.`id` 非占位符），均不匹配。
    $singleIdUserFinds = 0;
    DB::listen(function ($query) use (&$singleIdUserFinds) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from `users`') && preg_match('/`id`\s*=\s*\?/', $sql)) {
            $singleIdUserFinds++;
        }
    });

    $this->artisan('schedule:expire')->assertSuccessful();

    // N+1 消除：派发循环零单-id users 查询（改前为 5：3 cert + 2 acme 各一条 find）
    expect($singleIdUserFinds)->toBe(0);

    // 行为等价：3 cert_expire + 2 acme_expire = 5 次派发
    expect($dispatched)->toHaveCount(5);
    expect(collect($dispatched)->filter(fn ($c) => $c === 'cert_expire'))->toHaveCount(3);
    expect(collect($dispatched)->filter(fn ($c) => $c === 'acme_expire'))->toHaveCount(2);
});

test('收件人闸门：email 为空的到期用户不派发（单点闸门等价）', function () {
    $product = Product::factory()->create(['status' => 1, 'renew' => 1]);

    // 无 email 用户（应被闸门跳过）
    $noEmail = User::factory()->create([
        'email' => null,
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
    ]);
    $order = Order::factory()->create([
        'user_id' => $noEmail->id,
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

    $dispatched = [];
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use (&$dispatched) {
        $dispatched[] = $intent->code;
    });
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:expire')->assertSuccessful();

    expect($dispatched)->toHaveCount(0);
});

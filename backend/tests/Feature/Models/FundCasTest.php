<?php

/**
 * Fund::transitionToSuccessful CAS 单元测试。
 *
 * 覆盖 6 个 WHERE 分支：成功 / fund 不存在 / status 已 1 /
 * amount 不匹配 / pay_method 不匹配 / type 不匹配。
 * 整合断言：成功转换后 transaction 已写、user.balance 已加。
 */

use App\Models\Fund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('CAS 成功 - status 0→1，pay_sn 写入，transaction 创建，user.balance 增加', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
        'pay_sn' => null,
    ]);

    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id,
        '100.00',
        'addfunds',
        'alipay',
        'PAY_SN_TEST_001',
    ));

    expect($result)->toBeInstanceOf(Fund::class);
    expect($result->id)->toBe($fund->id);

    $fund->refresh();
    expect($fund->status)->toBe(1);
    expect($fund->pay_sn)->toBe('PAY_SN_TEST_001');

    // transaction 创建（与 fund 配对）
    $tx = Transaction::where('user_id', $user->id)
        ->where('type', 'addfunds')
        ->where('transaction_id', $fund->id)
        ->first();
    expect($tx)->not()->toBeNull();
    expect((string) $tx->amount)->toBe('100.00');

    // user.balance 加了 100
    $user->refresh();
    expect((string) $user->balance)->toBe('1100.00');
});

test('CAS 同 fund 第二次调返回 null - status 已 1', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
    ]);

    DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id, '100.00', 'addfunds', 'alipay', 'PAY_FIRST',
    ));

    // 第二次：status 已是 1，CAS 应返回 null
    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id, '100.00', 'addfunds', 'alipay', 'PAY_SECOND',
    ));

    expect($result)->toBeNull();

    // pay_sn 仍为第一次的值
    $fund->refresh();
    expect($fund->pay_sn)->toBe('PAY_FIRST');

    // 仅 1 条 transaction
    $count = Transaction::where('user_id', $user->id)
        ->where('type', 'addfunds')
        ->where('transaction_id', $fund->id)
        ->count();
    expect($count)->toBe(1);

    // balance 仅加 1 次
    $user->refresh();
    expect((string) $user->balance)->toBe('1100.00');
});

test('CAS status 已为 1 时调返回 null', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    // 直接造一个 status=1 的 fund（走 factory completed 状态会触发 createRecord 写 transaction）
    $fund = Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
    ]);

    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id, '100.00', 'addfunds', 'alipay', 'PAY_NEW',
    ));

    expect($result)->toBeNull();
});

test('CAS amount 不匹配返回 null - fund 不被改', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'status' => 0,
        'pay_sn' => null,
    ]);

    // 上游回调金额 99.00，本地 fund 金额 100.00 — 不匹配
    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id, '99.00', 'addfunds', 'alipay', 'PAY_BAD',
    ));

    expect($result)->toBeNull();

    $fund->refresh();
    expect($fund->status)->toBe(0);
    expect($fund->pay_sn)->toBeNull();

    // 无 transaction 创建
    $exists = Transaction::where('transaction_id', $fund->id)->exists();
    expect($exists)->toBeFalse();
});

test('CAS pay_method 不匹配返回 null - fund 不被改', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay', // 本地是 alipay
        'status' => 0,
    ]);

    // 期望 pay_method=wechat，与本地不匹配
    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id, '100.00', 'addfunds', 'wechat', 'PAY_WX',
    ));

    expect($result)->toBeNull();

    $fund->refresh();
    expect($fund->status)->toBe(0);
});

test('CAS type 不匹配返回 null - fund 不被改', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $fund = Fund::factory()->deduct()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'pay_method' => 'alipay',
        'status' => 0,
    ]);

    // 期望 type=addfunds，本地是 deduct
    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        $fund->id, '100.00', 'addfunds', 'alipay', 'PAY_X',
    ));

    expect($result)->toBeNull();

    $fund->refresh();
    expect($fund->status)->toBe(0);
});

test('CAS fund 不存在返回 null', function () {
    $result = DB::transaction(fn () => Fund::transitionToSuccessful(
        '99999999999999', '100.00', 'addfunds', 'alipay', 'PAY_Z',
    ));

    expect($result)->toBeNull();
});

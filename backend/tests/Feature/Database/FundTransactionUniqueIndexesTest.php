<?php

use App\Bootstrap\ApiExceptions;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * funds + transactions 唯一索引物理阻断 + ApiExceptions 翻译守门。
 *
 * DB::table 直接 INSERT 绕过 Eloquent 钩子的 exists 校验，直击 DB 层唯一约束。
 */
test('funds_pay_method_pay_sn_unique 索引阻断不同 type 同 (pay_method, pay_sn) 重复', function () {
    $user = User::factory()->create();

    DB::table('funds')->insert([
        'id' => 9991, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'addfunds',
        'pay_method' => 'alipay', 'pay_sn' => 'PAYSN_001', 'status' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 用嵌套事务/savepoint 隔离预期异常，避免污染 RefreshDatabase 外层测试事务。
    expect(fn () => DB::transaction(fn () => DB::table('funds')->insert([
        'id' => 9992, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'refunds',
        'pay_method' => 'alipay', 'pay_sn' => 'PAYSN_001', 'status' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ])))
        ->toThrow(QueryException::class);

    // 清理避免后续 invariant 检查
    DB::table('funds')->where('id', '>=', 9991)->delete();
});

test('funds 唯一索引允许 pay_sn=NULL 多行共存（处理中订单不冲突）', function () {
    $user = User::factory()->create();

    DB::table('funds')->insert([
        'id' => 9993, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'addfunds',
        'pay_method' => 'alipay', 'pay_sn' => null, 'status' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('funds')->insert([
        'id' => 9994, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'addfunds',
        'pay_method' => 'alipay', 'pay_sn' => null, 'status' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('funds')->whereNull('pay_sn')->count())->toBe(2);

    DB::table('funds')->where('id', '>=', 9993)->delete();
});

test('transactions_dedup_unique 索引阻断 type != order 的重复 transaction_id', function () {
    $user = User::factory()->create();

    DB::table('transactions')->insert([
        'id' => 9991, 'user_id' => $user->id, 'type' => 'addfunds', 'transaction_id' => 88888,
        'amount' => '100.00', 'balance_before' => '0.00', 'balance_after' => '100.00',
        'created_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('transactions')->insert([
        'id' => 9992, 'user_id' => $user->id, 'type' => 'addfunds', 'transaction_id' => 88888,
        'amount' => '100.00', 'balance_before' => '100.00', 'balance_after' => '200.00',
        'created_at' => now(),
    ])))
        ->toThrow(QueryException::class);

    DB::table('transactions')->where('id', '>=', 9991)->delete();
});

test('transactions order 类型仍允许重复 transaction_id（重签增域名场景）', function () {
    $user = User::factory()->create();

    DB::table('transactions')->insert([
        'id' => 9993, 'user_id' => $user->id, 'type' => 'order', 'transaction_id' => 77777,
        'amount' => '0.01', 'balance_before' => '0.00', 'balance_after' => '0.01',
        'created_at' => now(),
    ]);
    DB::table('transactions')->insert([
        'id' => 9994, 'user_id' => $user->id, 'type' => 'order', 'transaction_id' => 77777,
        'amount' => '0.01', 'balance_before' => '0.01', 'balance_after' => '0.02',
        'created_at' => now(),
    ]);

    expect(DB::table('transactions')->where('transaction_id', 77777)->count())->toBe(2);

    DB::table('transactions')->where('id', '>=', 9993)->delete();
});

test('ApiExceptions::causedByDuplicateKey 识别 funds 唯一冲突并返回业务消息', function () {
    $user = User::factory()->create();
    DB::table('funds')->insert([
        'id' => 9995, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'addfunds',
        'pay_method' => 'wechat', 'pay_sn' => 'PAYSN_DUP_2', 'status' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $caught = null;
    try {
        DB::transaction(fn () => DB::table('funds')->insert([
            'id' => 9996, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'refunds',
            'pay_method' => 'wechat', 'pay_sn' => 'PAYSN_DUP_2', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();

    $api = new ApiExceptions;
    $reflection = new ReflectionMethod($api, 'causedByDuplicateKey');
    $message = $reflection->invoke($api, $caught);

    expect($message)->toBe('支付编号重复请勿重复支付');

    DB::table('funds')->where('id', '>=', 9995)->delete();
});

test('ApiExceptions::causedByDuplicateKey 识别 transactions 唯一冲突并返回业务消息', function () {
    $user = User::factory()->create();
    DB::table('transactions')->insert([
        'id' => 9995, 'user_id' => $user->id, 'type' => 'deduct', 'transaction_id' => 66666,
        'amount' => '-50.00', 'balance_before' => '100.00', 'balance_after' => '50.00',
        'created_at' => now(),
    ]);

    $caught = null;
    try {
        DB::transaction(fn () => DB::table('transactions')->insert([
            'id' => 9996, 'user_id' => $user->id, 'type' => 'deduct', 'transaction_id' => 66666,
            'amount' => '-50.00', 'balance_before' => '50.00', 'balance_after' => '0.00',
            'created_at' => now(),
        ]));
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();

    $api = new ApiExceptions;
    $reflection = new ReflectionMethod($api, 'causedByDuplicateKey');
    $message = $reflection->invoke($api, $caught);

    expect($message)->toBe('交易记录已存在');

    DB::table('transactions')->where('id', '>=', 9995)->delete();
});

test('ApiExceptions::getExceptionStatusCode 把唯一冲突翻译成 409', function () {
    $user = User::factory()->create();
    DB::table('funds')->insert([
        'id' => 9997, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'addfunds',
        'pay_method' => 'alipay', 'pay_sn' => 'PAYSN_DUP_3', 'status' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $caught = null;
    try {
        DB::transaction(fn () => DB::table('funds')->insert([
            'id' => 9998, 'user_id' => $user->id, 'amount' => '10.00', 'type' => 'refunds',
            'pay_method' => 'alipay', 'pay_sn' => 'PAYSN_DUP_3', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();

    $api = new ApiExceptions;
    $reflection = new ReflectionMethod($api, 'getExceptionStatusCode');

    expect($reflection->invoke($api, $caught))->toBe(409);

    DB::table('funds')->where('id', '>=', 9997)->delete();
});

test('ApiExceptions::causedByDuplicateKey 对非 DB 异常返回 null', function () {
    $api = new ApiExceptions;
    $reflection = new ReflectionMethod($api, 'causedByDuplicateKey');

    expect($reflection->invoke($api, new RuntimeException('boom')))->toBeNull();
    expect($reflection->invoke($api, new Exception('plain')))->toBeNull();
});

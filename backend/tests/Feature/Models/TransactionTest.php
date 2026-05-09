<?php

use App\Models\Transaction;
use App\Models\User;

test('交易记录属于用户', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '100.00',
    ]);

    expect($transaction->user)->toBeInstanceOf(User::class);
    expect($transaction->user->id)->toBe($user->id);
});

test('创建交易记录会更新用户余额', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '200.00',
    ]);

    $user->refresh();
    expect($user->balance)->toBe('1200.00');
});

test('创建扣款交易减少用户余额', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '-300.00',
    ]);

    $user->refresh();
    expect($user->balance)->toBe('700.00');
});

test('记录交易前后余额', function () {
    $user = User::factory()->withBalance('500.00')->create();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '100.00',
    ]);

    expect($transaction->balance_before)->toBe('500.00');
    expect($transaction->balance_after)->toBe('600.00');
});

test('金额为 0 的交易不创建', function () {
    $user = User::factory()->create();

    $result = Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '0.00',
    ]);

    // creating 返回 false 时模型不会保存
    expect($result->exists)->toBeFalse();
});

test('禁止更新交易记录', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '100.00',
    ]);

    $transaction->remark = 'updated';
    $result = $transaction->save();

    // updating 返回 false
    expect($result)->toBeFalse();
});

test('禁止删除交易记录', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '100.00',
    ]);

    $result = $transaction->delete();

    // deleting 返回 false
    expect($result)->toBeFalse();
    expect(Transaction::find($transaction->id))->not->toBeNull();
});

test('非 order 类型不允许重复交易记录', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $transactionId = fake()->unique()->randomNumber(8);

    Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => $transactionId,
        'amount' => '100.00',
    ]);

    expect(fn () => Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => $transactionId,
        'amount' => '200.00',
    ]))->toThrow(Exception::class, '交易记录已存在');
});

test('acme_order 不允许重复 transaction_id', function () {
    $user = User::factory()->withBalance('10000.00')->create();
    $transactionId = fake()->unique()->randomNumber(8);

    Transaction::create([
        'user_id' => $user->id,
        'type' => 'acme_order',
        'transaction_id' => $transactionId,
        'amount' => '-100.00',
    ]);

    expect(fn () => Transaction::create([
        'user_id' => $user->id,
        'type' => 'acme_order',
        'transaction_id' => $transactionId,
        'amount' => '-200.00',
    ]))->toThrow(Exception::class, '交易记录已存在');
});

test('order 类型允许重复 transaction_id', function () {
    $user = User::factory()->withBalance('10000.00')->create();
    $transactionId = fake()->unique()->randomNumber(8);

    $t1 = Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $transactionId,
        'amount' => '-100.00',
    ]);

    $t2 = Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $transactionId,
        'amount' => '-200.00',
    ]);

    expect($t1->exists)->toBeTrue();
    expect($t2->exists)->toBeTrue();
});

test('amount 金额字段为 decimal:2 格式', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => fake()->unique()->randomNumber(8),
        'amount' => '123.456',
    ]);

    expect($transaction->amount)->toMatch('/^\d+\.\d{2}$/');
});

<?php

use App\Models\Fund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('资金 CAS 状态转换在事务中完成状态和入账', function () {
    $user = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '10.00',
        'type' => 'addfunds',
        'pay_method' => 'manual',
        'status' => 0,
        'pay_sn' => null,
    ]);

    $result = Fund::transitionToSuccessful(
        $fund->id,
        '10.00',
        'addfunds',
        'manual',
        'pay-transaction-success',
    );

    expect($result?->id)->toBe($fund->id)
        ->and($fund->fresh()->status)->toBe(1)
        ->and($fund->fresh()->pay_sn)->toBe('pay-transaction-success')
        ->and(Transaction::where('transaction_id', $fund->id)->sole()->amount)->toBe('10.00')
        ->and((string) $user->fresh()->balance)->toBe('10.00');
});

test('已完成资金不能回退为处理中', function () {
    $user = User::factory()->create();
    $fund = DB::transaction(fn () => Fund::factory()->completed()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_sn' => null,
    ]));

    $fund->status = 0;
    $fund->save();

    expect($fund->fresh()->status)->toBe(1)
        ->and(Transaction::where('type', 'addfunds')->where('transaction_id', $fund->id)->count())->toBe(1)
        ->and((string) $user->fresh()->balance)->toBe('100.00');
});

test('处理中资金不能直接改为已退', function () {
    $user = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'status' => 0,
        'pay_sn' => null,
    ]);

    $fund->status = 2;
    $fund->save();

    expect($fund->fresh()->status)->toBe(0)
        ->and(Transaction::where('transaction_id', $fund->id)->exists())->toBeFalse()
        ->and((string) $user->fresh()->balance)->toBe('0.00');
});

test('已退资金不能再次改变状态', function () {
    $user = User::factory()->create();
    $fund = DB::transaction(function () use ($user) {
        $fund = Fund::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => '100.00',
            'type' => 'addfunds',
            'pay_sn' => null,
        ]);
        $fund->type = 'refunds';
        $fund->status = 2;
        $fund->save();

        return $fund;
    });

    $fund->status = 1;
    $fund->save();

    expect($fund->fresh()->status)->toBe(2)
        ->and(Transaction::where('transaction_id', $fund->id)->count())->toBe(2)
        ->and((string) $user->fresh()->balance)->toBe('0.00');
});

test('处理中资金改为完成时只入账一次', function () {
    $user = User::factory()->create();
    $fund = Fund::factory()->create([
        'user_id' => $user->id,
        'amount' => '-123.456',
        'type' => 'addfunds',
        'status' => 0,
        'pay_sn' => null,
    ]);

    DB::transaction(function () use ($fund) {
        $fund->status = 1;
        $fund->save();
    });

    $transaction = Transaction::where('type', 'addfunds')
        ->where('transaction_id', $fund->id)
        ->sole();

    expect($fund->fresh()->status)->toBe(1)
        ->and((string) $fund->fresh()->amount)->toBe('123.46')
        ->and((string) $transaction->amount)->toBe('123.46')
        ->and((string) $user->fresh()->balance)->toBe('123.46');
});

test('处理中资金满两小时即可删除但未满两小时不可删除', function () {
    $now = now()->startOfSecond();
    $this->travelTo($now);
    $user = User::factory()->create();
    $atBoundary = Fund::factory()->create([
        'user_id' => $user->id,
        'created_at' => $now->copy()->subHours(2),
        'pay_sn' => null,
    ]);
    $insideWindow = Fund::factory()->create([
        'user_id' => $user->id,
        'created_at' => $now->copy()->subHours(2)->addSecond(),
        'pay_sn' => null,
    ]);

    expect($atBoundary->delete())->toBeTrue()
        ->and($insideWindow->delete())->toBeFalse()
        ->and(Fund::find($atBoundary->id))->toBeNull()
        ->and(Fund::find($insideWindow->id))->not->toBeNull();
});

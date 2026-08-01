<?php

use App\Models\Fund;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FundAudit\FundInvariants;
use App\Utils\SnowFlake;
use Illuminate\Support\Facades\DB;

/**
 * FundInvariants 4 条不变式 SQL 校验。
 *
 * L1/L3/L4 各 1 正 1 反；L2 仅正例（反向由 transactions_dedup_unique 唯一索引
 * 在 DB 层拦截，进不到 invariant）。反例用 DB::table 绕过 Eloquent 钩子构造撕裂数据，
 * 断言后立即清理避免污染后续测试。
 */
beforeEach(function () {
    $this->invariants = new FundInvariants;
});

// =============== L1 账目恒等 ===============

test('L1 正例：用户走正常 Fund/Transaction 路径，invariant 通过', function () {
    $user = User::factory()->withBalance('100.00')->create();

    // 走 Eloquent 路径正常充值（钩子自动写 transaction + 改 balance）
    DB::transaction(function () use ($user) {
        Fund::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'type' => 'addfunds',
            'pay_method' => 'alipay',
            'pay_sn' => 'PAYSN_L1_OK',
            'status' => 1,
        ]);
    });

    $user->refresh();
    expect((string) $user->balance)->toBe('150.00');

    expect($this->invariants->accountingIdentity())->toBeNull();
    expect($this->invariants->all())->toBe([]);
});

test('L1 反例：直接 UPDATE balance 制造账目偏离，invariant 报违反', function () {
    $user = User::factory()->withBalance('200.00')->create();

    // 直接 SQL 改 balance，绕过 transaction 钩子 → 制造 SUM(tx) != balance
    DB::table('users')->where('id', $user->id)->update([
        'balance' => DB::raw('balance + 999'),
    ]);

    $report = $this->invariants->accountingIdentity();

    expect($report)->not->toBeNull();
    expect($report['layer'])->toBe('L1');
    expect($report['rows'])->toHaveCount(1);
    expect((int) $report['rows'][0]['id'])->toBe($user->id);
    // 防 ConcatRemoveLeft/Right + RemoveArrayItem：layer 标识 + 描述关键词都要在
    expect($report['message'])->toContain('L1 账目恒等破');
    expect($report['message'])->toContain('个用户');
    expect($report['message'])->toBe(
        'L1 账目恒等破：1 个用户的 balance 与 transactions 累计存在偏离'
    );

    // 清理：恢复 balance 让后续测试干净
    DB::table('users')->where('id', $user->id)->update(['balance' => '200.00']);
});

// =============== L2 事件唯一 ===============

test('L2 正例：正常 transactions 没有重复 (type, transaction_id)，invariant 通过', function () {
    $user = User::factory()->withBalance('100.00')->create();

    // 创建几条不同 type / transaction_id 组合
    DB::transaction(function () use ($user) {
        Fund::create([
            'user_id' => $user->id,
            'amount' => '30.00',
            'type' => 'addfunds',
            'pay_method' => 'alipay',
            'pay_sn' => 'PAYSN_L2_OK_1',
            'status' => 1,
        ]);
        Fund::create([
            'user_id' => $user->id,
            'amount' => '20.00',
            'type' => 'addfunds',
            'pay_method' => 'wechat',
            'pay_sn' => 'PAYSN_L2_OK_2',
            'status' => 1,
        ]);
    });

    expect($this->invariants->eventUniqueness())->toBeNull();
});

// =============== L3 状态-事件配对 ===============

test('L3 正例：所有 funds.status=1 都有对应 transaction，invariant 通过', function () {
    $user = User::factory()->withBalance('100.00')->create();

    DB::transaction(function () use ($user) {
        Fund::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'type' => 'addfunds',
            'pay_method' => 'alipay',
            'pay_sn' => 'PAYSN_L3_OK',
            'status' => 1,
        ]);
    });

    expect($this->invariants->statePairing())->toBeNull();
});

test('L3 反例（正向）：直接 INSERT funds.status=1 不写 transaction，invariant 报违反', function () {
    $user = User::factory()->withBalance('100.00')->create();
    $fundId = SnowFlake::generateParticle();

    // 绕过 Fund::creating 钩子，造出"成功但无对应 transaction"的撕裂记录
    DB::table('funds')->insert([
        'id' => $fundId,
        'user_id' => $user->id,
        'amount' => '50.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'pay_sn' => 'PAYSN_L3_BAD',
        'ip' => '127.0.0.1',
        'remark' => '',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = $this->invariants->statePairing();

    expect($report)->not->toBeNull();
    expect($report['layer'])->toBe('L3');
    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]['direction'])->toBe('forward');
    expect((int) $report['rows'][0]['fund_id'])->toBe((int) $fundId);
    // 防 L156 RemoveNot (反转 if (! empty($forward))) + ConcatRemove*
    expect($report['message'])->toContain('L3 状态-事件配对破');
    expect($report['message'])->toContain('缺失对应 transaction');
    expect($report['message'])->toBe(
        'L3 状态-事件配对破：1 条 fund 缺失对应 transaction'
    );

    // 清理
    DB::table('funds')->where('id', $fundId)->delete();
});

test('L3 反例（反向）：fund 被违规删除留下孤儿 transaction，invariant 报违反', function () {
    // 不用 withBalance（它会再造一条 fund + tx）—— 直接 0 余额起步，只造一条孤儿场景
    $user = User::factory()->create();
    expect((string) $user->balance)->toBe('0.00');

    // 正常创建 fund + tx（钩子改 user.balance 0 → 50）
    $fund = DB::transaction(fn () => Fund::create([
        'user_id' => $user->id,
        'amount' => '50.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'pay_sn' => 'PAYSN_L3_REV',
        'status' => 1,
    ]));

    // 绕过 Fund::deleting 钩子，直接 SQL 删除 fund 行 — 模拟"违规删除已入账 fund"
    DB::table('funds')->where('id', $fund->id)->delete();

    $report = $this->invariants->statePairing();

    expect($report)->not->toBeNull();
    expect($report['layer'])->toBe('L3');
    expect(collect($report['rows'])->where('direction', 'reverse')->count())->toBe(1);
    // 防 L161 RemoveNot (反转 if (! empty($reverse))) + ConcatRemove*
    expect($report['message'])->toContain('L3 状态-事件配对破');
    expect($report['message'])->toContain('缺失对应已完成');
    expect($report['message'])->toBe(
        'L3 状态-事件配对破：1 条资金 transaction '
        .'缺失对应已完成/已退 fund（孤儿或状态未落地）'
    );

    $reverseRow = collect($report['rows'])->firstWhere('direction', 'reverse');
    expect((int) $reverseRow['transaction_id'])->toBe($fund->id);
    expect($reverseRow['type'])->toBe('addfunds');

    // 清理：删孤儿 tx 同时回退 user.balance — fund 已删但钩子已经加过 balance
    DB::table('transactions')->where('user_id', $user->id)->delete();
    DB::table('users')->where('id', $user->id)->update(['balance' => '0.00']);
});

test('L3 反例（反向）：transaction 已写但 fund 仍是处理中，invariant 报违反', function () {
    $user = User::factory()->create();
    $fundId = SnowFlake::generateParticle();
    $txId = SnowFlake::generateParticle();

    // 模拟异常路径：流水已写，但 fund.status 没从 0 落到 1/2。
    DB::table('funds')->insert([
        'id' => $fundId,
        'user_id' => $user->id,
        'amount' => '50.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'pay_sn' => null,
        'ip' => '127.0.0.1',
        'remark' => '',
        'status' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('transactions')->insert([
        'id' => $txId,
        'user_id' => $user->id,
        'type' => 'addfunds',
        'transaction_id' => $fundId,
        'amount' => '50.00',
        'balance_before' => '0.00',
        'balance_after' => '50.00',
        'created_at' => now(),
    ]);

    $report = $this->invariants->statePairing();

    expect($report)->not->toBeNull();
    expect($report['layer'])->toBe('L3');
    expect($report['message'])->toContain('L3 状态-事件配对破');
    expect($report['message'])->toContain('缺失对应已完成');

    $reverseRow = collect($report['rows'])->firstWhere('direction', 'reverse');
    expect($reverseRow)->not->toBeNull();
    expect((int) $reverseRow['transaction_id'])->toBe((int) $fundId);

    // 清理
    DB::table('transactions')->where('id', $txId)->delete();
    DB::table('funds')->where('id', $fundId)->delete();
});

// =============== L4 金额配对 ===============

test('L4 正例：fund.amount 与 transaction.amount 绝对值一致，invariant 通过', function () {
    $user = User::factory()->withBalance('100.00')->create();

    DB::transaction(function () use ($user) {
        Fund::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'type' => 'addfunds',
            'pay_method' => 'alipay',
            'pay_sn' => 'PAYSN_L4_OK',
            'status' => 1,
        ]);
    });

    expect($this->invariants->amountPairing())->toBeNull();
});

test('L4 反例：篡改 transaction.amount 制造金额错配，invariant 报违反', function () {
    $user = User::factory()->withBalance('100.00')->create();

    /** @var Fund $fund */
    $fund = DB::transaction(function () use ($user) {
        return Fund::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'type' => 'addfunds',
            'pay_method' => 'alipay',
            'pay_sn' => 'PAYSN_L4_BAD',
            'status' => 1,
        ]);
    });

    // 绕过 Transaction::updating（被禁），直接 SQL 改金额制造撕裂
    /** @var Transaction $tx */
    $tx = Transaction::where('user_id', $user->id)
        ->where('type', 'addfunds')
        ->where('transaction_id', $fund->id)
        ->firstOrFail();

    DB::table('transactions')->where('id', $tx->id)->update(['amount' => '999.99']);

    $report = $this->invariants->amountPairing();

    expect($report)->not->toBeNull();
    expect($report['layer'])->toBe('L4');
    expect($report['rows'])->toHaveCount(1);
    expect((int) $report['rows'][0]['fund_id'])->toBe($fund->id);
    expect($report['message'])->toContain('L4 金额配对破');
    expect($report['message'])->toContain('与对应 transaction');
    expect($report['message'])->toBe(
        'L4 金额配对破：1 条 fund 与对应 transaction 金额或符号不匹配'
    );

    // 清理
    DB::table('transactions')->where('id', $tx->id)->update(['amount' => '50.00']);
});

test('L4 符号反例：deduct 的 transaction 被错写成正数（应为负），invariant 报违反', function () {
    // 关键守门：ABS-only 比较会让此用例漏过；按 type 算期望符号才能拦
    $user = User::factory()->withBalance('1000.00')->create();

    // 走 Eloquent 路径正常 deduct，钩子写入 tx.amount=-50.00（正确）
    $fund = DB::transaction(fn () => Fund::create([
        'user_id' => $user->id,
        'amount' => '50.00',
        'type' => 'deduct',
        'pay_method' => 'manual',
        'pay_sn' => 'PAYSN_L4_SIGN',
        'status' => 1,
    ]));

    /** @var Transaction $tx */
    $tx = Transaction::where('user_id', $user->id)
        ->where('type', 'deduct')
        ->where('transaction_id', $fund->id)
        ->firstOrFail();

    expect((string) $tx->amount)->toBe('-50.00');  // 钩子默认负数

    // 绕过钩子直接改成正数 — 绝对值仍 50.00，但符号反了
    DB::table('transactions')->where('id', $tx->id)->update(['amount' => '50.00']);

    $report = $this->invariants->amountPairing();

    expect($report)->not->toBeNull();
    expect($report['layer'])->toBe('L4');
    expect($report['rows'])->toHaveCount(1);
    expect((int) $report['rows'][0]['fund_id'])->toBe($fund->id);
    expect($report['rows'][0]['type'])->toBe('deduct');
    expect($report['message'])->toContain('L4 金额配对破');
    expect($report['message'])->toContain('与对应 transaction');

    // 清理
    DB::table('transactions')->where('id', $tx->id)->update(['amount' => '-50.00']);
});

// =============== all() 聚合 ===============

test('all() 在违反时返回 non-empty 数组（防 AlwaysReturnEmptyArray）', function () {
    $user = User::factory()->withBalance('200.00')->create();

    // 制造 L1 违反
    DB::table('users')->where('id', $user->id)->update([
        'balance' => DB::raw('balance + 999'),
    ]);

    $violations = $this->invariants->all();

    expect($violations)->not->toBeEmpty();
    expect($violations)->toHaveCount(1);
    expect($violations[0]['layer'])->toBe('L1');

    // 清理
    DB::table('users')->where('id', $user->id)->update(['balance' => '200.00']);
});

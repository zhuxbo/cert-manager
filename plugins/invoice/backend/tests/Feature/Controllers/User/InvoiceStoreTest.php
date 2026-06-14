<?php

use App\Models\Fund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\Invoice\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    // 当年非赠送充值 100 元 => 可开票额度 100
    Fund::factory()->completed()->create([
        'user_id' => $this->user->id,
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'amount' => 100,
    ]);

    $this->payload = [
        'amount' => 60,
        'organization' => '测试科技有限公司',
        'taxation' => '91110000MA01234567',
        'email' => 'invoice@example.com',
    ];
});

test('it creates invoice within quota', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/invoice', $this->payload)
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Invoice::where('user_id', $this->user->id)->count())->toBe(1);
});

test('it rejects creating invoice over quota', function () {
    $payload = $this->payload;
    $payload['amount'] = 120; // 超过 100 额度

    $this->actingAsUser($this->user)
        ->postJson('/api/invoice', $payload)
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(Invoice::where('user_id', $this->user->id)->count())->toBe(0);
});

/**
 * 额度即将用尽：已开 80（占用），再开 30 超额，必须在锁内拒绝。
 * 串行语义下第二次开票应读到第一次已占用的额度并被拒，
 * 验证 store 的额度校验+创建是原子的（事务+锁内校验）。
 */
test('it rejects second invoice when remaining quota exhausted', function () {
    // 已存在一张 80 元处理中发票，占用额度（status=0 计入已开票）
    Invoice::create([
        'user_id' => $this->user->id,
        'amount' => 80,
        'organization' => '已开票公司',
        'taxation' => '91110000MA00000000',
        'email' => 'a@b.c',
        'status' => 0,
    ]);

    // 剩余额度 = 100 - 80 = 20，再开 30 应被拒
    $payload = $this->payload;
    $payload['amount'] = 30;

    $this->actingAsUser($this->user)
        ->postJson('/api/invoice', $payload)
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 仍只有最初那一张，新的未被创建
    expect(Invoice::where('user_id', $this->user->id)->count())->toBe(1);
});

/**
 * store 的额度校验必须在对 user 行加排他锁（for update）之后进行，关闭锁外裸 check-then-act 的并发窗口。
 * 注意：RefreshDatabase 把整个测试包在事务里，DB::transactionLevel() 恒 > 0，无法据此判断 store 是否自己开事务；
 * 故改为断言开票期间确实对 users 表发出了 `for update` 锁查询（与 easy 入口同款行锁），这能真正区分修复前后。
 */
test('it locks the user row for update when checking quota', function () {
    $lockedUserRow = false;

    DB::listen(function ($query) use (&$lockedUserRow) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'users') && str_contains($sql, 'for update')) {
            $lockedUserRow = true;
        }
    });

    $this->actingAsUser($this->user)
        ->postJson('/api/invoice', $this->payload)
        ->assertOk();

    expect($lockedUserRow)->toBeTrue();
});

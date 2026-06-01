<?php

use App\Models\Admin;
use App\Models\Transaction;
use App\Models\User;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    // 不用 withBalance：避免它产生一条基线 addfunds 流水干扰计数；
    // order 类型流水由 creating 钩子自行扣减 balance，L1 仍恒等。
    $this->user = User::factory()->create();
});

/**
 * 创建一条 order 类型流水（type=order 不受 L3/L4 fund 配对约束，
 * creating 钩子会同步扣减 user.balance 保证 L1 账目恒等）。
 */
function makeOrderTransaction(User $user, int $transactionId): Transaction
{
    return Transaction::factory()->order()->create([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
    ]);
}

test('管理员可以获取交易记录列表', function () {
    makeOrderTransaction($this->user, 1001);
    makeOrderTransaction($this->user, 1002);
    makeOrderTransaction($this->user, 1003);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
    expect($response->json('data.total'))->toBe(3);
    expect($response->json('data.items'))->toHaveCount(3);
});

test('transaction_id 按全值等值匹配命中', function () {
    makeOrderTransaction($this->user, 88888888);
    makeOrderTransaction($this->user, 99999999);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction?transaction_id=88888888');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect((string) $response->json('data.items.0.transaction_id'))->toBe('88888888');
});

test('transaction_id 子串不再命中（已从前置通配 LIKE 改为等值）', function () {
    // 8888 是 88888888 的子串：旧的 LIKE %8888% 会命中，等值匹配应当 0 命中
    makeOrderTransaction($this->user, 88888888);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction?transaction_id=8888');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(0);
});

test('quickSearch 按全值数字 transaction_id 等值命中', function () {
    makeOrderTransaction($this->user, 77777777);
    makeOrderTransaction($this->user, 66666666);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction?quickSearch=77777777');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect((string) $response->json('data.items.0.transaction_id'))->toBe('77777777');
});

test('quickSearch 数字子串不命中 transaction_id（等值，非子串）', function () {
    makeOrderTransaction($this->user, 77777777);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction?quickSearch=7777');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(0);
});

test('quickSearch 仍对 remark 做子串匹配', function () {
    makeOrderTransaction($this->user, 5001)->forceFill(['remark' => 'special invoice'])->saveQuietly();
    makeOrderTransaction($this->user, 5002)->forceFill(['remark' => 'normal'])->saveQuietly();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction?quickSearch=special');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect((string) $response->json('data.items.0.transaction_id'))->toBe('5001');
});

test('quickSearch 仍对用户名做子串匹配', function () {
    $alice = User::factory()->create(['username' => 'alice_finance']);
    makeOrderTransaction($alice, 6001);
    makeOrderTransaction($this->user, 6002);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/transaction?quickSearch=alice');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect((string) $response->json('data.items.0.transaction_id'))->toBe('6001');
});

test('quickSearch 的 OR 分组与 type 过滤正确 AND 组合（无 OR 优先级泄漏）', function () {
    // 两条都满足 quickSearch=alice（同用户名），但只有一条是 acme_order
    $alice = User::factory()->create(['username' => 'alice_pay']);
    Transaction::factory()->order()->create(['user_id' => $alice->id, 'transaction_id' => 7001]);
    Transaction::create([
        'user_id' => $alice->id,
        'type' => 'acme_order',
        'transaction_id' => 7002,
        'amount' => '-50.00',
    ]);

    // 若 OR 未被括号正确包裹，type 过滤会被 OR 旁路而泄漏 order 那条
    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/transaction?quickSearch=alice&type=acme_order');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect((string) $response->json('data.items.0.transaction_id'))->toBe('7002');
    expect($response->json('data.items.0.type'))->toBe('acme_order');
});

test('未认证用户无法访问交易记录', function () {
    $response = $this->getJson('/api/admin/transaction');

    $response->assertUnauthorized();
});

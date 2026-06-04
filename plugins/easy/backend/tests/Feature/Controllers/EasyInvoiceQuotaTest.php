<?php

use App\Models\Fund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Easy\Models\Agiso;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeAgisoUser(string $email = 'u@example.com'): array
{
    $user = User::factory()->create(['email' => $email]);
    // Agiso 无 factory,沿用 EasyControllerDeployTest 的直接 create 风格
    $agiso = Agiso::create([
        'tid' => 'tid-1',
        'pay_method' => 'taobao',
        'product_code' => 'test',
        'period' => 1,
        'price' => '0.00',
        'amount' => '0.00',
        'count' => 1,
        'user_id' => $user->id,
        'recharged' => 1,
    ]);

    return [$user, $agiso];
}

it('rejects when tid+email mismatched', function () {
    [$user, $agiso] = makeAgisoUser();

    $this->postJson('/api/easy/invoice/quota', ['tid' => 'tid-1', 'email' => 'wrong@e.com'])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

it('returns quota when tid+email matched', function () {
    [$user, $agiso] = makeAgisoUser();
    Fund::create([
        'user_id' => $user->id, 'amount' => 200, 'type' => 'addfunds',
        'pay_method' => 'alipay', 'pay_sn' => 's1', 'status' => 1,
    ]);

    $res = $this->postJson('/api/easy/invoice/quota', ['tid' => 'tid-1', 'email' => 'u@example.com'])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($res->json('data.recharge'))->toBe('200.00');
    expect($res->json('data.invoiced'))->toBe('0.00');
    expect($res->json('data.quota'))->toBe('200.00');
});

it('accepts any tid of the same user in current year', function () {
    $user = User::factory()->create(['email' => 'u@example.com']);
    foreach (['tid-A', 'tid-B', 'tid-C'] as $tid) {
        Agiso::create([
            'tid' => $tid, 'pay_method' => 'taobao', 'product_code' => 'test',
            'period' => 1, 'price' => '0.00', 'amount' => '0.00', 'count' => 1,
            'user_id' => $user->id, 'recharged' => 1,
        ]);
    }

    // 同一用户的任意当年 tid 都应通过
    foreach (['tid-A', 'tid-B', 'tid-C'] as $tid) {
        $this->postJson('/api/easy/invoice/quota', ['tid' => $tid, 'email' => 'u@example.com'])
            ->assertOk()
            ->assertJson(['code' => 1]);
    }
});

it('rejects tid from previous year', function () {
    $user = User::factory()->create(['email' => 'u@example.com']);
    $old = Agiso::create([
        'tid' => 'old-tid', 'pay_method' => 'taobao', 'product_code' => 'test',
        'period' => 1, 'price' => '0.00', 'amount' => '0.00', 'count' => 1,
        'user_id' => $user->id, 'recharged' => 1,
    ]);
    // 手动改 created_at 到去年(避开 Agiso 钩子,直接 DB 更新)
    DB::table('agisos')->where('id', $old->id)->update([
        'created_at' => date('Y-m-d H:i:s', strtotime('-1 year')),
    ]);

    $this->postJson('/api/easy/invoice/quota', ['tid' => 'old-tid', 'email' => 'u@example.com'])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

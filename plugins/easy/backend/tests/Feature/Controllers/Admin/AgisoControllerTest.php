<?php

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Easy\Models\Agiso;
use Tests\Traits\ActsAsAdmin;

uses(Tests\TestCase::class, ActsAsAdmin::class, RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

function createAgiso(array $overrides = []): Agiso
{
    return Agiso::create(array_merge([
        'pay_method' => 'alipay',
        'tid' => (string) random_int(1000000000, 9999999999),
        'status' => 'TRADE_SUCCESS',
        'product_code' => 'test-product',
        'period' => 12,
        'price' => '100.00',
        'count' => 1,
        'amount' => '100.00',
        'recharged' => 0,
    ], $overrides));
}

test('批量删除未充值记录成功', function () {
    $one = createAgiso();
    $two = createAgiso();

    $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/agiso/batch', ['ids' => [$one->id, $two->id]])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Agiso::whereIn('id', [$one->id, $two->id])->count())->toBe(0);
});

test('批量删除含已充值记录时整批拒绝', function () {
    $pending = createAgiso();
    $recharged = createAgiso(['recharged' => 1]);

    $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/agiso/batch', ['ids' => [$pending->id, $recharged->id]])
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 整批拒绝：未充值记录也必须保留，不能删一半
    expect(Agiso::whereIn('id', [$pending->id, $recharged->id])->count())->toBe(2);
});

test('批量删除记录不存在返回错误', function () {
    $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/agiso/batch', ['ids' => [999999]])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('批量删除 ids 为空返回校验错误', function () {
    $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/agiso/batch', ['ids' => []])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('批量删除未认证被拦截', function () {
    $this->deleteJson('/api/admin/agiso/batch', ['ids' => [1]])
        ->assertUnauthorized();
});

test('旧集合根路径批量删除已下线（batch 不挂集合根）', function () {
    $one = createAgiso();

    $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/agiso', ['ids' => [$one->id]])
        ->assertStatus(405);

    expect(Agiso::whereKey($one->id)->exists())->toBeTrue();
});

test('单条删除仍走 {id} 且已充值不可删', function () {
    $recharged = createAgiso(['recharged' => 1]);

    $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/agiso/$recharged->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(Agiso::whereKey($recharged->id)->exists())->toBeTrue();
});

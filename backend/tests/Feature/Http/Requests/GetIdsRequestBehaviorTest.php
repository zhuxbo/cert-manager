<?php

/**
 * GetIdsRequest 两族行为回归测试（DRY 重构 #49）
 *
 * BaseRequest::idsRules() 抽出共享的 ids 批量校验规则后，两族语义必须保持不变：
 *   - exists 族（如 User）：'ids.*' => 'integer|exists:users,id'，任一 id 不存在 → 整个请求校验失败，不做任何操作；
 *   - filter 族（如 Acme）：'ids.*' => 'integer' + passedValidation() 用 whereIn 静默剔除不存在的 id 后继续。
 *
 * 这是本次重构唯一的风险点：严禁把 exists 族退化成 filter 族（反之亦然）。
 */

use App\Models\Acme;
use App\Models\Admin;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\ActsAsUser;
use Tests\Traits\MocksExternalApis;

uses(ActsAsAdmin::class);
uses(ActsAsUser::class);
uses(MocksExternalApis::class);
uses(RefreshDatabase::class);

// ==================== exists 族：User\GetIdsRequest ====================

test('exists 族：含不存在的 id 时整个请求被拒绝，已存在的数据不受影响', function () {
    $admin = Admin::factory()->create();
    $user = User::factory()->create();
    $missingId = $user->id + 99999;

    $response = $this->actingAsAdmin($admin)->deleteJson('/api/admin/user/batch', [
        'ids' => [$user->id, $missingId],
    ]);

    // exists 规则命中：整体校验失败（不是 code:1）
    $response->assertJson(['code' => 0]);

    // 关键：exists 族是"全有才放行"，校验失败时不做任何删除——已存在的用户必须仍在
    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

test('exists 族：全部 id 存在时正常通过并执行批量操作', function () {
    $admin = Admin::factory()->create();
    $users = User::factory()->count(2)->create();
    $ids = $users->pluck('id')->toArray();

    $response = $this->actingAsAdmin($admin)->deleteJson('/api/admin/user/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(User::whereIn('id', $ids)->count())->toBe(0);
});

// ==================== filter 族：Acme\GetIdsRequest ====================

test('filter 族：[存在id, 不存在id] 静默剔除不存在的，只处理存在的、不报错', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['product_type' => Product::TYPE_ACME]);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $missingId = $acme->id + 99999;

    $response = $this->actingAsUser($user)
        ->getJson("/api/acme/batch?ids=$acme->id,$missingId")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 不存在的 id 被静默剔除，仅返回存在的那一条
    $items = $response->json('data.items');
    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($acme->id);
});

test('filter 族：全部 id 不存在时被静默剔除为空集，控制器再报"不存在"', function () {
    $user = User::factory()->create();

    // passedValidation 把 ids 过滤为空数组，控制器查不到数据返回 code:0
    // （证明 ids.* 仅校验整数、不走 exists——否则会在校验阶段就 422 而非进入控制器空集分支）
    $this->actingAsUser($user)
        ->getJson('/api/acme/batch?ids=999999,888888')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

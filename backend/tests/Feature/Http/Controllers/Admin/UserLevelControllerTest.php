<?php

use App\Models\Admin;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->userLevelLockHolder = null;
});

afterEach(function () {
    if ($this->userLevelLockHolder instanceof Connection) {
        try {
            $this->userLevelLockHolder->selectOne(
                'SELECT RELEASE_LOCK(?) AS released',
                [userLevelMutationLockKey()]
            );
        } catch (Throwable) {
            // 测试清理只做 best effort。
        }

        DB::purge('user_level_lock_holder');
    }
});

function userLevelMutationLockKey(): string
{
    $token = getenv('TEST_TOKEN');

    return 'ssl-manager:product-price:mutation:'.($token === false || $token === '' ? 'single' : $token);
}

function holdUserLevelMutationLock(object $test): void
{
    $default = config('database.default');
    config(['database.connections.user_level_lock_holder' => config("database.connections.$default")]);
    DB::purge('user_level_lock_holder');

    $test->userLevelLockHolder = DB::connection('user_level_lock_holder');
    $result = $test->userLevelLockHolder->selectOne(
        'SELECT GET_LOCK(?, 0) AS acquired',
        [userLevelMutationLockKey()]
    );

    expect((int) ($result->acquired ?? 0))->toBe(1);
}

// ==================== destroy 删除保护与关联清理 ====================

test('destroy 拒绝删除被用户 level_code 引用的级别', function () {
    $level = UserLevel::factory()->create(['code' => 'gold', 'name' => '黄金会员']);
    User::factory()->create(['level_code' => 'gold']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('黄金会员');
    expect($resp->json('msg'))->toContain('用户');
    expect(UserLevel::find($level->id))->not->toBeNull(); // 未被删除
});

test('destroy 删除级别时解除用户 custom_level_code 绑定', function () {
    $level = UserLevel::factory()->create(['code' => 'vip', 'name' => 'VIP会员']);
    // level_code 用默认 standard，定制级别指向 vip
    $user = User::factory()->create(['level_code' => 'standard', 'custom_level_code' => 'vip']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 1]);
    expect(UserLevel::find($level->id))->toBeNull();
    expect($user->fresh()->custom_level_code)->toBeNull();
});

test('destroy 删除级别时同步清理产品价格', function () {
    $level = UserLevel::factory()->create(['code' => 'biz', 'name' => '企业版']);
    $price = ProductPrice::factory()->create(['level_code' => 'biz']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 1]);
    expect(UserLevel::find($level->id))->toBeNull();
    expect(ProductPrice::find($price->id))->toBeNull();
});

test('destroy 允许删除无任何引用的级别', function () {
    $level = UserLevel::factory()->create(['code' => 'unused', 'name' => '未使用级别']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 1]);
    expect(UserLevel::find($level->id))->toBeNull();
});

test('destroy 允许删除系统预设级别（custom=0）只要无引用', function () {
    $level = UserLevel::factory()->create(['code' => 'preset', 'name' => '预设级别', 'custom' => 0]);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 1]);
    expect(UserLevel::find($level->id))->toBeNull();
});

test('数据库外键阻止基础绑定级别删除并兜底清理可解除引用', function () {
    $baseLevel = UserLevel::factory()->create(['code' => 'fk-base', 'name' => '外键基础级别']);
    $customLevel = UserLevel::factory()->create(['code' => 'fk-custom', 'name' => '外键定制级别']);
    $baseUser = User::factory()->create(['level_code' => $baseLevel->code]);
    $customUser = User::factory()->create(['custom_level_code' => $customLevel->code]);
    $price = ProductPrice::factory()->create(['level_code' => $customLevel->code]);

    expect(fn () => DB::table('user_levels')->where('id', $baseLevel->id)->delete())
        ->toThrow(QueryException::class);
    expect($baseLevel->fresh())->not->toBeNull()
        ->and($baseUser->fresh()->level_code)->toBe($baseLevel->code);

    DB::table('user_levels')->where('id', $customLevel->id)->delete();

    expect($customUser->fresh()->custom_level_code)->toBeNull()
        ->and(ProductPrice::find($price->id))->toBeNull();
});

// ==================== batchDestroy 整体拒绝 ====================

test('batchDestroy 任一级别被引用则整批拒绝且无一删除', function () {
    $used = UserLevel::factory()->create(['code' => 'used', 'name' => '在用级别']);
    $free = UserLevel::factory()->create(['code' => 'free', 'name' => '空闲级别']);
    User::factory()->create(['level_code' => 'used']);
    $customUser = User::factory()->create(['custom_level_code' => 'free']);
    $price = ProductPrice::factory()->create(['level_code' => 'free']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user-level/batch', [
        'ids' => [$used->id, $free->id],
    ]);

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('在用级别');
    // 整批拒绝：两个都还在
    expect(UserLevel::find($used->id))->not->toBeNull();
    expect(UserLevel::find($free->id))->not->toBeNull();
    expect($customUser->fresh()->custom_level_code)->toBe('free');
    expect(ProductPrice::find($price->id))->not->toBeNull();
});

test('batchDestroy 无基础绑定时成功删除并清理可解除引用', function () {
    $a = UserLevel::factory()->create(['code' => 'a1', 'name' => '级别A']);
    $b = UserLevel::factory()->create(['code' => 'b1', 'name' => '级别B']);
    $customUser = User::factory()->create(['custom_level_code' => 'a1']);
    $prices = [
        ProductPrice::factory()->create(['level_code' => 'a1']),
        ProductPrice::factory()->create(['level_code' => 'b1']),
    ];

    $resp = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user-level/batch', [
        'ids' => [$a->id, $b->id],
    ]);

    $resp->assertOk()->assertJson(['code' => 1]);
    expect(UserLevel::find($a->id))->toBeNull();
    expect(UserLevel::find($b->id))->toBeNull();
    expect($customUser->fresh()->custom_level_code)->toBeNull();
    expect(ProductPrice::whereKey(collect($prices)->pluck('id')->all())->count())->toBe(0);
});

// ==================== site.sourceLevel 注册来源映射引用（删除保护缺口） ====================

// site.sourceLevel 是「注册来源 → level_code」映射，注册流程（AuthController::register /
// registerWithMobile、easy 插件）据此给新用户赋 level_code。删除被它引用的级别会令
// 后续该来源的新注册用户 level_code 悬空 → getMinPrice 取不到价 → 0 元免费签证书。
test('destroy 拒绝删除被 site.sourceLevel 注册来源映射引用的级别', function () {
    Cache::flush();
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'sourceLevel'],
        ['type' => 'array', 'value' => ['promo' => 'vip2']]
    );
    // 仅被 sourceLevel 映射引用，无任何 user / product_price 引用
    $level = UserLevel::factory()->create(['code' => 'vip2', 'name' => 'VIP2会员']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('VIP2会员');
    expect($resp->json('msg'))->toContain('注册来源');
    expect(UserLevel::find($level->id))->not->toBeNull(); // 未被删除
});

test('batchDestroy 拒绝删除被 site.sourceLevel 引用的级别且整批不删', function () {
    Cache::flush();
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'sourceLevel'],
        ['type' => 'array', 'value' => ['promo' => 'vip2']]
    );
    $referenced = UserLevel::factory()->create(['code' => 'vip2', 'name' => 'VIP2会员']);
    $free = UserLevel::factory()->create(['code' => 'free2', 'name' => '空闲级别2']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user-level/batch', [
        'ids' => [$referenced->id, $free->id],
    ]);

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('VIP2会员');
    expect(UserLevel::find($referenced->id))->not->toBeNull();
    expect(UserLevel::find($free->id))->not->toBeNull();
});

// ==================== update 编码引用保护 ====================

test('update 拒绝修改被产品价格引用的级别编码', function () {
    $level = UserLevel::factory()->create(['code' => 'price-ref', 'name' => '价格引用级别']);
    ProductPrice::factory()->create(['level_code' => $level->code]);

    $resp = $this->actingAsAdmin($this->admin)->putJson("/api/admin/user-level/{$level->id}", [
        'code' => 'renamed-ref',
        'name' => $level->name,
        'custom' => $level->custom,
        'cost_rate' => '1.0000',
        'weight' => 100,
    ]);

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('产品价格');
    expect($level->fresh()->code)->toBe('price-ref');
});

test('update 未修改编码时允许更新被引用级别的其他字段', function () {
    $level = UserLevel::factory()->create(['code' => 'same-code', 'name' => '保持编码级别']);
    ProductPrice::factory()->create(['level_code' => $level->code]);

    $resp = $this->actingAsAdmin($this->admin)->putJson("/api/admin/user-level/{$level->id}", [
        'code' => $level->code,
        'name' => '保持编码新名称',
        'custom' => $level->custom,
        'cost_rate' => '1.2500',
        'weight' => 100,
    ]);

    $resp->assertOk()->assertJson(['code' => 1]);
    expect($level->fresh())
        ->name->toBe('保持编码新名称')
        ->cost_rate->toBe('1.2500');
});

// ==================== cost_rate 精确倍率契约 ====================

test('store 接受 JSON number 倍率并以四位小数字符串输出', function () {
    $resp = $this->actingAsAdmin($this->admin)->postJson('/api/admin/user-level', [
        'code' => 'number-rate',
        'name' => '数字倍率级别',
        'custom' => 1,
        'cost_rate' => 1.2345,
        'weight' => 100,
    ]);

    $resp->assertOk()->assertJson(['code' => 1]);
    $level = UserLevel::where('code', 'number-rate')->firstOrFail();
    expect($level->cost_rate)->toBe('1.2345');

    $this->actingAsAdmin($this->admin)->getJson("/api/admin/user-level/{$level->id}")
        ->assertOk()
        ->assertJsonPath('data.cost_rate', '1.2345');
});

test('update 接受字符串倍率并以四位小数字符串保存', function () {
    $level = UserLevel::factory()->create(['code' => 'string-rate', 'name' => '字符串倍率级别']);

    $resp = $this->actingAsAdmin($this->admin)->putJson("/api/admin/user-level/{$level->id}", [
        'code' => $level->code,
        'name' => $level->name,
        'custom' => 1,
        'cost_rate' => '99.9999',
        'weight' => 100,
    ]);

    $resp->assertOk()->assertJson(['code' => 1]);
    expect($level->fresh()->cost_rate)->toBe('99.9999');
});

test('cost_rate 拒绝超过四位小数或超出 1 至 99.9999 范围', function ($rate) {
    $this->actingAsAdmin($this->admin)->postJson('/api/admin/user-level', [
        'code' => 'invalid-rate',
        'name' => '非法倍率级别',
        'custom' => 1,
        'cost_rate' => $rate,
        'weight' => 100,
    ])->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonValidationErrors('cost_rate');
})->with([
    '五位小数' => '1.23456',
    '小于一' => '0.9999',
    '达到一百' => '100',
]);

test('会员级别 update destroy batchDestroy 与价格写入共用同一命名锁', function () {
    $updateLevel = UserLevel::factory()->create(['code' => 'lock-update', 'name' => '锁更新级别']);
    $destroyLevel = UserLevel::factory()->create(['code' => 'lock-destroy', 'name' => '锁删除级别']);
    $batchLevel = UserLevel::factory()->create(['code' => 'lock-batch', 'name' => '锁批删级别']);
    holdUserLevelMutationLock($this);

    $responses = [
        $this->actingAsAdmin($this->admin)->putJson("/api/admin/user-level/{$updateLevel->id}", [
            'code' => $updateLevel->code,
            'name' => $updateLevel->name,
            'custom' => 1,
            'cost_rate' => '1.0000',
            'weight' => 100,
        ]),
        $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$destroyLevel->id}"),
        $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user-level/batch', [
            'ids' => [$batchLevel->id],
        ]),
    ];

    foreach ($responses as $response) {
        $response->assertStatus(503)->assertJson([
            'code' => 0,
            'msg' => '产品价格正在变更，请稍后重试',
        ]);
    }
});

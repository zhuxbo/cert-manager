<?php

use App\Models\Admin;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

// ==================== destroy 删除保护（被引用禁删） ====================

test('destroy 拒绝删除被用户 level_code 引用的级别', function () {
    $level = UserLevel::factory()->create(['code' => 'gold', 'name' => '黄金会员']);
    User::factory()->create(['level_code' => 'gold']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('黄金会员');
    expect($resp->json('msg'))->toContain('用户');
    expect(UserLevel::find($level->id))->not->toBeNull(); // 未被删除
});

test('destroy 拒绝删除被用户 custom_level_code 引用的级别', function () {
    $level = UserLevel::factory()->create(['code' => 'vip', 'name' => 'VIP会员']);
    // level_code 用默认 standard，定制级别指向 vip
    User::factory()->create(['level_code' => 'standard', 'custom_level_code' => 'vip']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 0]);
    expect(UserLevel::find($level->id))->not->toBeNull();
});

test('destroy 拒绝删除被产品价格引用的级别', function () {
    $level = UserLevel::factory()->create(['code' => 'biz', 'name' => '企业版']);
    ProductPrice::factory()->create(['level_code' => 'biz']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user-level/{$level->id}");

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('产品价格');
    expect(UserLevel::find($level->id))->not->toBeNull();
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

// ==================== batchDestroy 整体拒绝 ====================

test('batchDestroy 任一级别被引用则整批拒绝且无一删除', function () {
    $used = UserLevel::factory()->create(['code' => 'used', 'name' => '在用级别']);
    $free = UserLevel::factory()->create(['code' => 'free', 'name' => '空闲级别']);
    User::factory()->create(['level_code' => 'used']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user-level/batch', [
        'ids' => [$used->id, $free->id],
    ]);

    $resp->assertOk()->assertJson(['code' => 0]);
    expect($resp->json('msg'))->toContain('在用级别');
    // 整批拒绝：两个都还在
    expect(UserLevel::find($used->id))->not->toBeNull();
    expect(UserLevel::find($free->id))->not->toBeNull();
});

test('batchDestroy 全部无引用则成功删除', function () {
    $a = UserLevel::factory()->create(['code' => 'a1', 'name' => '级别A']);
    $b = UserLevel::factory()->create(['code' => 'b1', 'name' => '级别B']);

    $resp = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user-level/batch', [
        'ids' => [$a->id, $b->id],
    ]);

    $resp->assertOk()->assertJson(['code' => 1]);
    expect(UserLevel::find($a->id))->toBeNull();
    expect(UserLevel::find($b->id))->toBeNull();
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

<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 收敛：运维告警 admin 目标解析单一源 Admin::resolveAlertTarget()。
// 原多份逐字拷贝（TaskJob/FundAuditCommand/SystemAlert）。解析规则演进漏改任一份都会投错地址。
// 关键：返回 ['admin'=>?Admin,'email'=>?string]，让各调用方保留自己的空值判定。
uses(TestCase::class, RefreshDatabase::class);

function setSiteAdminEmailForResolve(?string $email): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    if ($email === null) {
        Setting::where('group_id', $group->id)->where('key', 'adminEmail')->delete();
    } else {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => 'adminEmail'],
            ['type' => 'string', 'value' => $email, 'weight' => 0]
        );
    }
    Setting::clearGroupCache($group->id);
}

test('adminEmail 命中 Admin 记录 → admin=命中行, email=adminEmail', function () {
    setSiteAdminEmailForResolve('boss@corp.example');
    $matched = Admin::factory()->create(['email' => 'boss@corp.example']);
    Admin::factory()->create(['email' => 'other@corp.example']);

    $t = Admin::resolveAlertTarget();

    expect($t['admin']->id)->toBe($matched->id)
        ->and($t['email'])->toBe('boss@corp.example');
});

test('adminEmail 为别名（不命中）但有 Admin → admin=first, email=别名（别名优先投递）', function () {
    setSiteAdminEmailForResolve('ops-alias@corp.example');
    $first = Admin::factory()->create(['email' => null]);

    $t = Admin::resolveAlertTarget();

    // 别名非任何登录邮箱：admin 回落 Admin::first()（供 NotificationCenter 取 id），email 用别名
    expect($t['admin']->id)->toBe($first->id)
        ->and($t['email'])->toBe('ops-alias@corp.example');
});

test('adminEmail 为别名且无任何 Admin 记录 → admin=null, email=别名', function () {
    setSiteAdminEmailForResolve('ops-alias@corp.example');
    // 无 Admin 记录

    $t = Admin::resolveAlertTarget();

    expect($t['admin'])->toBeNull()
        ->and($t['email'])->toBe('ops-alias@corp.example');
});

test('adminEmail 未配置 → 跳过空邮箱 Admin，选择首个邮箱非空的 Admin', function () {
    setSiteAdminEmailForResolve(null);
    Admin::factory()->create(['email' => null]);
    $admin = Admin::factory()->create(['email' => 'only-admin@corp.example']);

    $t = Admin::resolveAlertTarget();

    expect($t['admin']->id)->toBe($admin->id)
        ->and($t['email'])->toBe('only-admin@corp.example');
});

test('adminEmail 未配置 + 无 Admin → admin=null, email=null（四份统一不发）', function () {
    setSiteAdminEmailForResolve(null);

    $t = Admin::resolveAlertTarget();

    expect($t['admin'])->toBeNull()
        ->and($t['email'])->toBeNull();
});

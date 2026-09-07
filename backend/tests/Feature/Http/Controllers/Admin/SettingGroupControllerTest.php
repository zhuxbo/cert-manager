<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class, RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    Cache::flush();
});

function protectedDelegationGroup(): SettingGroup
{
    $group = SettingGroup::create([
        'name' => 'delegation',
        'title' => 'CNAME委托',
        'weight' => 11,
    ]);
    Setting::create([
        'group_id' => $group->id,
        'key' => 'delegationDomain',
        'type' => 'string',
        'value' => 'proxy.example.com',
    ]);

    return $group;
}

test('核心 delegation 设置组不可改名且缓存仍指向原组', function () {
    $group = protectedDelegationGroup();
    expect(Setting::getValue('delegation', 'delegationDomain'))->toBe('proxy.example.com');

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/setting-group/{$group->id}", [
            'name' => 'renamed-delegation',
            'title' => '已改名',
            'weight' => 11,
        ])
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => '核心 delegation 设置组不能改名']);

    expect($group->fresh()->name)->toBe('delegation')
        ->and(Setting::getValue('delegation', 'delegationDomain'))->toBe('proxy.example.com');
});

test('核心 delegation 设置组不可单删且缓存和设置项均保留', function () {
    $group = protectedDelegationGroup();
    expect(Setting::getValue('delegation', 'delegationDomain'))->toBe('proxy.example.com');

    $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/setting-group/{$group->id}")
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => '核心 delegation 设置组不能删除']);

    expect($group->fresh())->not->toBeNull()
        ->and($group->settings()->count())->toBe(1)
        ->and(Setting::getValue('delegation', 'delegationDomain'))->toBe('proxy.example.com');
});

test('批删包含核心 delegation 设置组时整批失败且其他组也保留', function () {
    $delegation = protectedDelegationGroup();
    $ordinary = SettingGroup::factory()->create(['name' => 'ordinary-group']);

    $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/setting-group/batch', [
            'ids' => [$ordinary->id, $delegation->id],
        ])
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => '核心 delegation 设置组不能删除']);

    expect($delegation->fresh())->not->toBeNull()
        ->and($ordinary->fresh())->not->toBeNull();
});

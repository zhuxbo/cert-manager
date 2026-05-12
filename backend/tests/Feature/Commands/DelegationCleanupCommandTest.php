<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\ProxyDNS;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->proxyDNS = Mockery::mock(ProxyDNS::class);
    $this->app->instance(ProxyDNS::class, $this->proxyDNS);
});

test('签名为 delegation:cleanup', function () {
    // 代理域名未配置时直接返回
    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('代理域名未设置')
        ->assertSuccessful();
});

test('代理域名未配置时终止执行', function () {
    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('代理域名未设置')
        ->assertSuccessful();
});

test('没有需要清理的记录时正常退出', function () {
    // 清除设置缓存
    Cache::flush();

    // 设置系统配置
    $group = SettingGroup::factory()->create(['name' => 'site']);
    Setting::factory()->create([
        'group_id' => $group->id,
        'key' => 'delegation',
        'type' => 'array',
        'value' => ['proxyZone' => 'proxy.example.com'],
    ]);

    $this->proxyDNS->shouldReceive('getAllTxtRecords')
        ->with('proxy.example.com')
        ->andReturn([]);

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('没有需要清理的记录')
        ->assertSuccessful();
});

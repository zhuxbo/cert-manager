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

/** 造 site.delegation.proxyZone 设置。 */
function cleanupSetProxyZone(string $zone = 'proxy.example.com'): void
{
    Cache::flush();
    $group = SettingGroup::factory()->create(['name' => 'site']);
    Setting::factory()->create([
        'group_id' => $group->id,
        'key' => 'delegation',
        'type' => 'array',
        'value' => ['proxyZone' => $zone],
    ]);
}

test('仅删委托格式记录：保留非委托 TXT（_dmarc/apex/DKIM），清 32-hex 孤儿', function () {
    cleanupSetProxyZone();

    $hex32 = str_repeat('a', 32); // 32 位 hex 委托 label 孤儿
    $records = [
        ['id' => 1, 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none;'],
        ['id' => 2, 'name' => '@', 'value' => 'v=spf1 include:_spf.example.com ~all'],
        ['id' => 3, 'name' => 'default._domainkey', 'value' => 'v=DKIM1; k=rsa; p=MIGf...'],
        ['id' => 4, 'name' => $hex32, 'value' => 'orphan-token'],
    ];

    $this->proxyDNS->shouldReceive('getAllTxtRecords')
        ->with('proxy.example.com')
        ->andReturn($records);

    // 仅 32-hex 孤儿（id=4）进删除集，非委托 TXT 全部保留
    $this->proxyDNS->shouldReceive('batchDeleteRecords')
        ->once()
        ->with(Mockery::on(fn ($ids) => $ids === [4]));

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('64-hex 孤儿委托记录仍被清理（历史存量形态，兼容判据）', function () {
    cleanupSetProxyZone();

    $hex64 = str_repeat('b', 64); // 64 位 hex（前身仓历史 full-sha256 形态）
    $records = [
        ['id' => 10, 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none;'],
        ['id' => 11, 'name' => $hex64, 'value' => 'orphan-token-64'],
    ];

    $this->proxyDNS->shouldReceive('getAllTxtRecords')
        ->with('proxy.example.com')
        ->andReturn($records);

    // 64-hex 孤儿（id=11）被兼容判据命中删除，_dmarc 保留
    $this->proxyDNS->shouldReceive('batchDeleteRecords')
        ->once()
        ->with(Mockery::on(fn ($ids) => $ids === [11]));

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

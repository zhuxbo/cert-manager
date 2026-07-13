<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

afterEach(function () {
    // swap throwing cache 后恢复 array cache，避免 RefreshDatabase 收尾（含 Cache 操作）被打断。
    restoreArrayCache();
});

// cache 后端故障（redis 宕机）时 settings 读取必须回落 DB 直读——否则 HealthProbeCommand 发信链
// （site.adminEmail + Email 构造读 mail 配置）与 health 阈值全部随 cache 死。
test('getByGroupName 在 cache 后端故障时回落 DB 直读', function () {
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );

    // 模拟 redis 全故障：Cache::remember/get 全抛
    Cache::swap(throwingCacheRepository());

    $values = Setting::getByGroupName('site');

    expect($values['adminEmail'] ?? null)->toBe('ops@corp.example');
});

test('getValue / get_system_setting 在 cache 后端故障时回落 DB 直读', function () {
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );

    Cache::swap(throwingCacheRepository());

    expect(Setting::getValue('site', 'adminEmail'))->toBe('ops@corp.example')
        ->and(get_system_setting('site', 'adminEmail'))->toBe('ops@corp.example');
});

// 加重项对端：mail 组配置（Email 构造读 get_system_setting('mail')）同样须在 cache 故障时可读，
// 否则告警邮件构造 configured=false，最后防线发不出信。
test('mail 组配置在 cache 后端故障时回落 DB 直读（Email 构造依赖）', function () {
    $group = SettingGroup::firstOrCreate(['name' => 'mail'], ['title' => '邮件', 'weight' => 2]);
    $mailConfig = [
        'server' => 'smtp.example.com',
        'user' => 'u',
        'password' => 'p',
        'port' => '465',
        'senderMail' => 's@example.com',
        'senderName' => 'S',
    ];
    foreach ($mailConfig as $k => $v) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $k],
            ['type' => 'string', 'value' => $v]
        );
    }

    Cache::swap(throwingCacheRepository());

    $mail = get_system_setting('mail');

    expect($mail['server'] ?? null)->toBe('smtp.example.com')
        ->and($mail['senderMail'] ?? null)->toBe('s@example.com');
});

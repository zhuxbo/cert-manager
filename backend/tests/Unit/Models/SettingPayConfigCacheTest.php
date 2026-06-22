<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

// PaymentConfigTrait 把支付配置（含已注册的微信公钥 wechat_public_cert_path）缓存进
// pay_config_{type}（365 天）。保存 wechat/alipay 设置时若不同步清掉该缓存，缓存里的旧公钥/
// 证书会与 live 设置不一致——wechat 公钥轮换后 getPayConfig 命中旧缓存只注册旧公钥，而
// wechatSerial 实时读 live 发新 serial 头，微信遂以新公钥签回调，本地却验不了 → 回调验签失败。
test('保存 wechat 支付设置时清除 pay_config_wechat 缓存', function () {
    $group = SettingGroup::firstOrCreate(['name' => 'wechat'], ['title' => 'WeChat', 'weight' => 0]);
    Cache::put('pay_config_wechat', ['wechat_public_cert_path' => ['PUB_KEY_ID_OLD' => '/path']], now()->addDays(365));
    expect(Cache::has('pay_config_wechat'))->toBeTrue();

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'publicKeyId'],
        ['type' => 'string', 'value' => 'PUB_KEY_ID_NEW']
    );

    expect(Cache::has('pay_config_wechat'))->toBeFalse();
});

test('保存 alipay 支付设置时清除 pay_config_alipay 缓存', function () {
    $group = SettingGroup::firstOrCreate(['name' => 'alipay'], ['title' => 'Alipay', 'weight' => 0]);
    Cache::put('pay_config_alipay', ['appId' => 'old'], now()->addDays(365));
    expect(Cache::has('pay_config_alipay'))->toBeTrue();

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'appId'],
        ['type' => 'string', 'value' => 'new_app_id']
    );

    expect(Cache::has('pay_config_alipay'))->toBeFalse();
});

// 边界：非支付组的设置变更不得误清支付配置缓存（约束修复不能写成无条件 forget）。
test('保存非支付组设置不影响 pay_config 缓存', function () {
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Cache::put('pay_config_wechat', ['x' => 'y'], now()->addDays(365));

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'url'],
        ['type' => 'string', 'value' => 'https://example.com']
    );

    expect(Cache::has('pay_config_wechat'))->toBeTrue();
});

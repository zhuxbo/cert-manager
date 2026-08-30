<?php

use App\Models\CnameDelegation;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DelegationConfigService;
use App\Services\Delegation\DelegationDomainRetirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->service = new DelegationDomainRetirementService(app(DelegationConfigService::class));
});

function retirementGroup(): SettingGroup
{
    return SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '域名委托', 'weight' => 1],
    );
}

function retirementCreateDomainSetting(string $domain = 'proxy.example.com'): Setting
{
    $config = app(DelegationConfigService::class);
    $domain = $config->normalizeDomain($domain);

    return Setting::create([
        'group_id' => retirementGroup()->id,
        'key' => $config->keyForDomain($domain),
        'type' => 'array',
        'value' => [
            'domain' => $domain,
            'provider' => 'cloudflare',
            'zoneId' => 'test-zone',
            'apiToken' => 'test-token',
        ],
        'weight' => 1,
    ]);
}

function retirementSetDefaultDomain(string $domain): Setting
{
    return Setting::create([
        'group_id' => retirementGroup()->id,
        'key' => 'defaultDomain',
        'type' => 'string',
        'value' => $domain,
        'weight' => 2,
    ]);
}

test('defaultDomain 设置不能删除', function () {
    $setting = retirementSetDefaultDomain('proxy.example.com');

    expect(fn () => $this->service->retire($setting))
        ->toThrow(DomainException::class, '默认委托域设置不能删除');
    expect($setting->fresh())->not->toBeNull();
});

test('当前默认委托域配置不能删除', function () {
    $setting = retirementCreateDomainSetting('Proxy.Example.COM.');
    retirementSetDefaultDomain('proxy.example.com');

    expect(fn () => $this->service->retire($setting))
        ->toThrow(DomainException::class, '当前默认委托域不能删除');
    expect($setting->fresh())->not->toBeNull();
});

test('只按 proxy_domain 计数阻止删除并提示委托记录数量', function () {
    $setting = retirementCreateDomainSetting();
    CnameDelegation::factory()->count(3)->create(['proxy_domain' => 'proxy.example.com']);

    expect(fn () => $this->service->preflight($setting))
        ->toThrow(DomainException::class, '仍有 3 条委托记录使用该委托域');
    expect($setting->fresh())->not->toBeNull();
});

test('没有 proxy_domain 引用时直接删除设置且不删除其他记录', function () {
    $setting = retirementCreateDomainSetting();
    $unrelated = CnameDelegation::factory()->create(['proxy_domain' => 'other.example.com']);

    $this->service->retire($setting);

    expect($setting->fresh())->toBeNull()
        ->and($unrelated->fresh())->not->toBeNull();
});

test('批量删除先对全部设置做检查', function () {
    $free = retirementCreateDomainSetting('free.example.com');
    $blocked = retirementCreateDomainSetting('blocked.example.com');
    CnameDelegation::factory()->create(['proxy_domain' => 'blocked.example.com']);

    expect(fn () => $this->service->retireMany([$free, $blocked]))
        ->toThrow(DomainException::class, '仍有 1 条委托记录使用该委托域');
    expect($free->fresh())->not->toBeNull()
        ->and($blocked->fresh())->not->toBeNull();
});

test('Seeder provider 示例可作为普通设置删除', function () {
    $setting = Setting::create([
        'group_id' => retirementGroup()->id,
        'key' => 'tencentExample',
        'type' => 'array',
        'value' => [
            'domain' => '',
            'provider' => 'tencent',
            'secretId' => '',
            'secretKey' => '',
        ],
    ]);

    $this->service->retire($setting);

    expect($setting->fresh())->toBeNull();
});

test('已填域名但凭据不完整的草稿可直接删除', function () {
    $setting = retirementCreateDomainSetting('draft.example.com');
    $setting->update([
        'value' => [
            'domain' => 'draft.example.com',
            'provider' => 'cloudflare',
            'zoneId' => '',
            'apiToken' => '',
        ],
    ]);

    $this->service->retire($setting);

    expect($setting->fresh())->toBeNull()
        ->and(app(DelegationConfigService::class)->all())->not->toHaveKey('draft.example.com');
});

test('delegation 组畸形配置删除时失败关闭', function (string $key, string $type, mixed $value) {
    $setting = Setting::create([
        'group_id' => retirementGroup()->id,
        'key' => $key,
        'type' => $type,
        'value' => $value,
    ]);

    expect(fn () => $this->service->retire($setting))
        ->toThrow(DomainException::class, '委托域配置无效');
    expect($setting->fresh())->not->toBeNull();
})->with([
    'wrong type' => ['wrongTypeExampleCom', 'string', 'wrong-type.example.com'],
    '缺 domain' => ['missingDomain', 'array', ['provider' => 'cloudflare']],
    'key domain 不匹配' => ['wrongKey', 'array', [
        'domain' => 'mismatch.example.com',
        'provider' => 'cloudflare',
        'zoneId' => 'zone-id',
        'apiToken' => 'api-token',
    ]],
]);

test('未启用 provider 示例可改为域名派生 key 并补全配置', function () {
    $setting = Setting::create([
        'group_id' => retirementGroup()->id,
        'key' => 'cloudflareExample',
        'type' => 'array',
        'value' => [
            'domain' => '',
            'provider' => 'cloudflare',
            'zoneId' => '',
            'apiToken' => '',
        ],
    ]);
    $attributes = [
        'group_id' => retirementGroup()->id,
        'key' => 'proxyExampleCom',
        'type' => 'array',
        'value' => [
            'domain' => 'proxy.example.com',
            'provider' => 'cloudflare',
            'zoneId' => 'zone-id',
            'apiToken' => 'api-token',
        ],
    ];

    $this->service->assertUpdatePreservesIdentity($setting, $attributes);
    $setting->update($attributes);

    expect(app(DelegationConfigService::class)->all())->toHaveKey('proxy.example.com');
});

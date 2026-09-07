<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DelegationConfigService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class)->group('database');

function delegationMigrationLegacySetting(array $value): void
{
    $site = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'description' => null, 'weight' => 1],
    );

    Setting::create([
        'group_id' => $site->id,
        'key' => 'delegation',
        'type' => 'array',
        'options' => null,
        'is_multiple' => false,
        'value' => $value,
        'description' => 'CNAME委托',
        'weight' => 9,
    ]);
}

function runDelegationSettingSeeder(): void
{
    app(SettingSeeder::class)->run();
}

test('代理域迁移在已有列缺少索引时补建索引', function () {
    Schema::table('cname_delegations', function (Blueprint $table) {
        $table->dropIndex('cname_delegations_proxy_domain_index');
    });

    expect(Schema::hasColumn('cname_delegations', 'proxy_domain'))->toBeTrue()
        ->and(Schema::hasIndex('cname_delegations', 'cname_delegations_proxy_domain_index'))->toBeFalse();

    $migration = require database_path('migrations/2026_08_27_000001_add_proxy_domain_to_cname_delegations.php');
    $migration->up();

    expect(Schema::hasIndex('cname_delegations', 'cname_delegations_proxy_domain_index'))->toBeTrue();
});

test('迁移非空旧委托设置并回填空代理域后删除旧设置', function () {
    delegationMigrationLegacySetting([
        'proxyZone' => 'legacy.example.com',
        'secretId' => 'legacy-id',
        'secretKey' => 'legacy-key',
    ]);
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user);
    $delegation->forceFill(['proxy_domain' => null])->save();

    runDelegationSettingSeeder();

    expect(Setting::getValue('delegation', 'delegationDomain'))->toBe('legacy.example.com')
        ->and(Setting::getValue('delegation', 'tencent'))->toBe([
            'domain' => 'legacy.example.com',
            'provider' => 'tencent',
            'secretId' => 'legacy-id',
            'secretKey' => 'legacy-key',
        ])
        ->and($delegation->fresh()->proxy_domain)->toBe('legacy.example.com')
        ->and(Setting::getValue('site', 'delegation'))->toBeNull()
        ->and(Setting::getValue('delegation', 'cloudflare'))->toBeArray()
        ->and(Setting::getValue('delegation', 'aliyun'))->toBeArray()
        ->and(Setting::whereHas('group', fn ($query) => $query->where('name', 'delegation'))
            ->where('key', 'tencent')
            ->value('description'))->toBe('腾讯云委托配置');
});

test('空旧委托设置只创建空默认域且不创建 provider 配置', function () {
    delegationMigrationLegacySetting([
        'proxyZone' => '',
        'secretId' => '',
        'secretKey' => '',
    ]);

    runDelegationSettingSeeder();

    expect(Setting::getValue('delegation', 'delegationDomain'))->toBe('')
        ->and(Setting::getValue('delegation', ''))->toBeNull()
        ->and(Setting::getValue('site', 'delegation'))->toBeNull();
});

test('认证不完整的旧腾讯云设置迁移为草稿并排除运行配置', function () {
    delegationMigrationLegacySetting([
        'proxyZone' => 'legacy.example.com',
        'secretId' => 'legacy-id',
        'secretKey' => '',
    ]);
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user);
    $delegation->forceFill(['proxy_domain' => null])->save();

    runDelegationSettingSeeder();

    $service = app(DelegationConfigService::class);

    expect(Setting::getValue('delegation', 'delegationDomain'))->toBe('legacy.example.com')
        ->and(Setting::getValue('delegation', 'tencent'))->toBe([
            'domain' => 'legacy.example.com',
            'provider' => 'tencent',
            'secretId' => 'legacy-id',
            'secretKey' => '',
        ])
        ->and($delegation->fresh()->proxy_domain)->toBe('legacy.example.com')
        ->and(Setting::getValue('site', 'delegation'))->toBeNull()
        ->and($service->all())->toBe([])
        ->and($service->invalidSettings())->toBe([
            'tencent' => 'provider 或凭据无效',
        ]);
});

test('只回填 proxy domain 为空的委托记录', function () {
    delegationMigrationLegacySetting([
        'proxyZone' => 'legacy.example.com',
        'secretId' => 'legacy-id',
        'secretKey' => 'legacy-key',
    ]);
    $user = $this->createTestUser();
    $missing = $this->createTestDelegation($user, ['zone' => 'missing.example.com']);
    $missing->forceFill(['proxy_domain' => null])->save();
    $bound = $this->createTestDelegation($user, ['zone' => 'bound.example.com']);
    $bound->forceFill(['proxy_domain' => 'bound.proxy.example.com'])->save();

    runDelegationSettingSeeder();

    expect($missing->fresh()->proxy_domain)->toBe('legacy.example.com')
        ->and($bound->fresh()->proxy_domain)->toBe('bound.proxy.example.com');
});

test('迁移重复执行保持幂等', function () {
    delegationMigrationLegacySetting([
        'proxyZone' => 'legacy.example.com',
        'secretId' => 'legacy-id',
        'secretKey' => 'legacy-key',
    ]);

    runDelegationSettingSeeder();
    runDelegationSettingSeeder();

    $group = SettingGroup::where('name', 'delegation')->firstOrFail();

    expect(Setting::where('group_id', $group->id)->where('key', 'delegationDomain')->count())->toBe(1)
        ->and(Setting::where('group_id', $group->id)->where('key', 'tencent')->count())->toBe(1)
        ->and(Setting::getValue('site', 'delegation'))->toBeNull();
});

test('defaultDomain 原地迁移保留值排序并刷新缓存且重跑幂等', function () {
    $group = SettingGroup::factory()->create(['name' => 'delegation']);
    $legacy = Setting::create([
        'group_id' => $group->id,
        'key' => 'defaultDomain',
        'type' => 'string',
        'value' => 'proxy.example.com',
        'description' => '自定义默认域',
        'weight' => 42,
    ]);
    expect(Setting::getValue('delegation', 'defaultDomain'))->toBe('proxy.example.com');
    runDelegationSettingSeeder();
    runDelegationSettingSeeder();

    expect($legacy->fresh()->key)->toBe('delegationDomain')
        ->and($legacy->fresh()->value)->toBe('proxy.example.com')
        ->and($legacy->fresh()->weight)->toBe(42)
        ->and($legacy->fresh()->description)->toBe('自定义默认域')
        ->and(Setting::getValue('delegation', 'defaultDomain'))->toBeNull()
        ->and(app(DelegationConfigService::class)->defaultDomain())->toBe('proxy.example.com')
        ->and($group->settings()->where('key', 'delegationDomain')->count())->toBe(1);
});

test('默认域新旧键并存时只回填空新值并移除旧键', function (?string $value, string $expected) {
    $group = SettingGroup::factory()->create(['name' => 'delegation']);
    $legacy = Setting::create([
        'group_id' => $group->id,
        'key' => 'defaultDomain',
        'type' => 'string',
        'value' => 'old.example.com',
    ]);
    $target = Setting::create([
        'group_id' => $group->id,
        'key' => 'delegationDomain',
        'type' => 'string',
        'value' => $value,
        'weight' => 24,
    ]);
    Setting::getValue('delegation', 'delegationDomain');
    runDelegationSettingSeeder();
    runDelegationSettingSeeder();

    expect($legacy->fresh())->toBeNull()
        ->and($target->fresh()->value)->toBe($expected)
        ->and($target->fresh()->weight)->toBe(24)
        ->and(app(DelegationConfigService::class)->defaultDomain())->toBe($expected)
        ->and(Setting::getValue('delegation', 'defaultDomain'))->toBeNull()
        ->and($group->settings()->where('key', 'delegationDomain')->count())->toBe(1);
})->with([
    ['', 'old.example.com'],
    ['   ', 'old.example.com'],
    [null, 'old.example.com'],
    ['new.example.com', 'new.example.com'],
]);

test('名为 defaultDomain 的 provider 不被当作旧默认项迁移', function (bool $hasDefault) {
    $group = SettingGroup::factory()->create(['name' => 'delegation']);
    $config = [
        'domain' => 'proxy.example.com',
        'provider' => 'cloudflare',
        'zoneId' => 'zone-id',
        'apiToken' => 'test-token',
    ];
    $provider = Setting::create([
        'group_id' => $group->id,
        'key' => 'defaultDomain',
        'type' => 'array',
        'value' => $config,
    ]);
    if ($hasDefault) {
        Setting::create([
            'group_id' => $group->id,
            'key' => 'delegationDomain',
            'type' => 'string',
            'value' => 'proxy.example.com',
        ]);
    }

    runDelegationSettingSeeder();
    runDelegationSettingSeeder();

    expect($provider->fresh()->key)->toBe('defaultDomain')
        ->and($provider->fresh()->value)->toBe($config)
        ->and(app(DelegationConfigService::class)->get('proxy.example.com'))->toBe($config)
        ->and(app(DelegationConfigService::class)->defaultDomain())->toBe($hasDefault ? 'proxy.example.com' : '');
})->with([true, false]);

test('旧域配置占用新默认键时保留配置并迁到空闲键且重跑幂等', function (bool $hasLegacy, bool $occupied) {
    $group = SettingGroup::factory()->create(['name' => 'delegation']);
    $config = [
        'domain' => 'delegation.domain',
        'provider' => 'cloudflare',
        'zoneId' => 'zone-id',
        'apiToken' => 'test-token',
    ];
    $provider = Setting::create([
        'group_id' => $group->id,
        'key' => 'delegationDomain',
        'type' => 'array',
        'value' => $config,
        'weight' => 42,
        'description' => '原有域配置',
    ]);
    if ($hasLegacy) {
        Setting::create([
            'group_id' => $group->id,
            'key' => 'defaultDomain',
            'type' => 'string',
            'value' => 'delegation.domain',
        ]);
    }
    $existing = $occupied ? Setting::create([
        'group_id' => $group->id,
        'key' => 'delegationProvider'.$provider->id,
        'type' => 'array',
        'value' => ['domain' => '', 'provider' => 'tencent'],
    ]) : null;

    runDelegationSettingSeeder();
    $migratedKey = $provider->fresh()->key;
    runDelegationSettingSeeder();

    expect($provider->fresh()->key)->toBe($migratedKey)->not->toBe('delegationDomain')
        ->and($provider->fresh()->value)->toBe($config)
        ->and($provider->fresh()->weight)->toBe(42)
        ->and($provider->fresh()->description)->toBe('原有域配置')
        ->and(app(DelegationConfigService::class)->get('delegation.domain'))->toBe($config)
        ->and(app(DelegationConfigService::class)->defaultDomain())->toBe($hasLegacy ? 'delegation.domain' : '')
        ->and($group->settings()->where('key', 'delegationDomain')->value('type'))->toBe('string');
    if ($existing) {
        expect($existing->fresh()->key)->toBe($existing->key)
            ->and($existing->fresh()->value)->toBe($existing->value);
    }
})->with([[true, false], [false, false], [true, true], [false, true]]);

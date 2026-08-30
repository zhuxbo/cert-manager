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

    expect(Setting::getValue('delegation', 'defaultDomain'))->toBe('legacy.example.com')
        ->and(Setting::getValue('delegation', 'legacyExampleCom'))->toBe([
            'domain' => 'legacy.example.com',
            'provider' => 'tencent',
            'secretId' => 'legacy-id',
            'secretKey' => 'legacy-key',
        ])
        ->and($delegation->fresh()->proxy_domain)->toBe('legacy.example.com')
        ->and(Setting::getValue('site', 'delegation'))->toBeNull()
        ->and(Setting::getValue('delegation', 'tencentExample'))->toBeNull()
        ->and(Setting::getValue('delegation', 'cloudflareExample'))->toBeArray()
        ->and(Setting::getValue('delegation', 'aliyunExample'))->toBeArray()
        ->and(Setting::whereHas('group', fn ($query) => $query->where('name', 'delegation'))
            ->where('key', 'legacyExampleCom')
            ->value('description'))->toBe('腾讯云委托配置');
});

test('空旧委托设置只创建空默认域且不创建 provider 配置', function () {
    delegationMigrationLegacySetting([
        'proxyZone' => '',
        'secretId' => '',
        'secretKey' => '',
    ]);

    runDelegationSettingSeeder();

    expect(Setting::getValue('delegation', 'defaultDomain'))->toBe('')
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

    expect(Setting::getValue('delegation', 'defaultDomain'))->toBe('legacy.example.com')
        ->and(Setting::getValue('delegation', 'legacyExampleCom'))->toBe([
            'domain' => 'legacy.example.com',
            'provider' => 'tencent',
            'secretId' => 'legacy-id',
            'secretKey' => '',
        ])
        ->and($delegation->fresh()->proxy_domain)->toBe('legacy.example.com')
        ->and(Setting::getValue('site', 'delegation'))->toBeNull()
        ->and($service->all())->toBe([])
        ->and($service->invalidSettings())->toBe([
            'legacyExampleCom' => 'provider 或凭据无效',
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

    expect(Setting::where('group_id', $group->id)->where('key', 'defaultDomain')->count())->toBe(1)
        ->and(Setting::where('group_id', $group->id)->where('key', 'legacyExampleCom')->count())->toBe(1)
        ->and(Setting::getValue('site', 'delegation'))->toBeNull();
});

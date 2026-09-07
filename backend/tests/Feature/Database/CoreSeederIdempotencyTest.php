<?php

use App\Models\Admin;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\UserLevel;
use App\Services\Delegation\DelegationConfigService;
use Database\Seeders\AdminSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\Hash;

// 通用 Seeder 幂等测试用例：
// 1) 空库可创建基础数据；2) 已存在记录时不覆盖；3) 重复执行结果稳定。
dataset('core_seeders', [
    'AdminSeeder' => [
        AdminSeeder::class,
        function (): void {
            expect(Admin::where('username', 'admin')->count())->toBe(1);
        },
        function (): void {
            Admin::create([
                'username' => 'admin',
                'password' => 'existing-admin-password',
            ]);
        },
        function (): void {
            $admin = Admin::where('username', 'admin')->first();
            expect($admin)->not->toBeNull();
            expect(Admin::where('username', 'admin')->count())->toBe(1);
            expect(Hash::check('existing-admin-password', (string) $admin->password))->toBeTrue();
        },
        function (): array {
            $admin = Admin::where('username', 'admin')->first();

            return [
                'count' => Admin::where('username', 'admin')->count(),
                'password' => (string) ($admin?->password ?? ''),
            ];
        },
    ],
    'UserLevelSeeder' => [
        UserLevelSeeder::class,
        function (): void {
            $codes = UserLevel::query()->orderBy('code')->pluck('code')->all();
            expect($codes)->toBe(['crown', 'gold', 'partner', 'platinum', 'standard']);
        },
        function (): void {
            UserLevel::create([
                'code' => 'platinum',
                'name' => '预置铂金会员',
                'custom' => 0,
                'cost_rate' => 1.88,
                'weight' => 99,
            ]);
        },
        function (): void {
            $platinum = UserLevel::where('code', 'platinum')->first();
            expect($platinum)->not->toBeNull();
            expect(UserLevel::where('code', 'platinum')->count())->toBe(1);
            expect((string) $platinum->name)->toBe('预置铂金会员');
            expect((int) $platinum->weight)->toBe(99);
        },
        function (): array {
            return UserLevel::query()
                ->orderBy('code')
                ->get()
                ->map(fn (UserLevel $level): array => [
                    'code' => (string) $level->code,
                    'name' => (string) $level->name,
                    'custom' => (int) $level->custom,
                    'cost_rate' => $level->getRawOriginal('cost_rate'),
                    'weight' => (int) $level->weight,
                ])
                ->values()
                ->all();
        },
    ],
    'SettingSeeder' => [
        SettingSeeder::class,
        function (): void {
            $siteGroup = SettingGroup::where('name', 'site')->first();
            expect($siteGroup)->not->toBeNull();

            expect(Setting::where('group_id', $siteGroup->id)->where('key', 'delegation')->exists())->toBeFalse();
            $delegationGroup = SettingGroup::where('name', 'delegation')->first();
            expect($delegationGroup)->not->toBeNull();
            expect($delegationGroup?->title)->toBe('域名委托')
                ->and((int) $delegationGroup?->weight)->toBe(3)
                ->and((int) SettingGroup::where('name', 'ca')->value('weight'))->toBe(2)
                ->and((int) SettingGroup::where('name', 'callback')->value('weight'))->toBe(4)
                ->and(Setting::getValue('delegation', 'delegationDomain'))->toBe('')
                ->and(Setting::getValue('delegation', 'tencent'))->toBe([
                    'domain' => '',
                    'provider' => 'tencent',
                    'secretId' => '',
                    'secretKey' => '',
                ])
                ->and(Setting::getValue('delegation', 'cloudflare'))->toBe([
                    'domain' => '',
                    'provider' => 'cloudflare',
                    'zoneId' => '',
                    'apiToken' => '',
                ])
                ->and(Setting::getValue('delegation', 'aliyun'))->toBe([
                    'domain' => '',
                    'provider' => 'aliyun',
                    'accessKeyId' => '',
                    'accessKeySecret' => '',
                ])
                ->and($delegationGroup?->settings()->where('key', 'tencent')->value('description'))->toBe('腾讯云委托配置')
                ->and($delegationGroup?->settings()->where('key', 'cloudflare')->value('description'))->toBe('Cloudflare 委托配置')
                ->and($delegationGroup?->settings()->where('key', 'aliyun')->value('description'))->toBe('阿里云委托配置')
                ->and(app(DelegationConfigService::class)->all())->toBe([])
                ->and(app(DelegationConfigService::class)->invalidSettings())->toBe([]);
            $dnsTools = Setting::where('group_id', $siteGroup->id)->where('key', 'dnsTools')->first();
            expect($dnsTools)->toBeNull();
            $autoRefundOnSync = Setting::where('group_id', $siteGroup->id)->where('key', 'autoRefundOnSync')->first();
            expect($autoRefundOnSync)->toBeNull();
            $expandedLogo = Setting::where('group_id', $siteGroup->id)->where('key', 'logoExpanded')->first();
            expect($expandedLogo?->type)->toBe('image')
                ->and($expandedLogo?->value)->toBe('');
            $favicon = Setting::where('group_id', $siteGroup->id)->where('key', 'favicon')->first();
            expect($favicon?->type)->toBe('image')
                ->and($favicon?->value)->toBe('')
                ->and($favicon?->weight)->toBe(3);

            $brandGroup = SettingGroup::where('name', 'brand')->first();
            expect($brandGroup?->description)->toBeNull();
            expect(Setting::getValue('brand', 'all'))->toBe([
                'cnssl' => 'Cnssl',
                'certum' => 'Certum',
                'gogetssl' => 'GoGetSSL',
                'positive' => 'Positive',
                'keeptrust' => '环安信',
                'rapid' => 'Rapid',
                'geotrust' => 'GeoTrust',
                'digicert' => 'DigiCert',
                'ssltrus' => '锐安信',
            ]);
            expect(Setting::getValue('brand', 'admin'))->toBe([
                'cnssl', 'certum', 'gogetssl', 'positive', 'keeptrust',
                'rapid', 'geotrust', 'digicert', 'ssltrus',
            ]);
            expect(Setting::getValue('brand', 'user'))->toBe([
                'cnssl', 'certum', 'gogetssl', 'positive', 'keeptrust',
                'ssltrus', 'rapid', 'geotrust', 'digicert',
            ]);
            expect($brandGroup?->settings()->where('key', 'all')->value('description'))->toBe('全部品牌');
            expect($brandGroup?->settings()->where('key', 'admin')->value('description'))->toBe('管理端品牌选项');
            expect($brandGroup?->settings()->where('key', 'user')->value('description'))->toBe('用户端品牌选项');
            expect((int) $brandGroup?->settings()->where('key', 'admin')->value('weight'))->toBe(1);
            expect((int) $brandGroup?->settings()->where('key', 'user')->value('weight'))->toBe(2);
            expect((int) $brandGroup?->settings()->where('key', 'all')->value('weight'))->toBe(3);

            $callbackGroup = SettingGroup::where('name', 'callback')->first();
            $defaultCallback = $callbackGroup?->settings()->where('key', 'default')->first();
            expect($defaultCallback?->value)->toMatchArray(['sources' => 'default']);
        },
        function (): void {
            $siteGroup = SettingGroup::firstOrCreate(
                ['name' => 'site'],
                ['title' => '站点设置', 'description' => null, 'weight' => 1]
            );

            Setting::create([
                'group_id' => $siteGroup->id,
                'key' => 'delegation',
                'type' => 'array',
                'options' => null,
                'is_multiple' => 0,
                'value' => ['proxyZone' => 'legacy.zone', 'secretId' => 'legacy-id', 'secretKey' => 'legacy-key'],
                'description' => '自定义委托',
                'weight' => 99,
            ]);
            Setting::create([
                'group_id' => $siteGroup->id,
                'key' => 'dnsTools',
                'type' => 'array',
                'value' => ['custom' => 'https://dns.example.com'],
                'description' => '自定义 DNS 工具',
                'weight' => 6,
            ]);
            Setting::create([
                'group_id' => $siteGroup->id,
                'key' => 'autoRefundOnSync',
                'type' => 'boolean',
                'value' => true,
                'description' => '隐藏设置',
                'weight' => 99,
            ]);

            $callbackGroup = SettingGroup::firstOrCreate(
                ['name' => 'callback'],
                ['title' => '回调设置', 'description' => null, 'weight' => 3]
            );
            Setting::create([
                'group_id' => $callbackGroup->id,
                'key' => 'default',
                'type' => 'array',
                'value' => [
                    'sources' => '',
                    'token' => '',
                    'id_field' => 'id',
                    'allowed_ips' => '',
                ],
                'description' => '自定义默认回调',
                'weight' => 1,
            ]);
        },
        function (): void {
            $siteGroup = SettingGroup::where('name', 'site')->first();
            expect($siteGroup)->not->toBeNull();

            expect(Setting::where('group_id', $siteGroup->id)->where('key', 'delegation')->exists())->toBeFalse();
            $delegationGroup = SettingGroup::where('name', 'delegation')->first();
            expect($delegationGroup)->not->toBeNull();
            expect($delegationGroup?->title)->toBe('域名委托')
                ->and((int) $delegationGroup?->weight)->toBe(3)
                ->and((int) SettingGroup::where('name', 'callback')->value('weight'))->toBe(4)
                ->and(Setting::getValue('delegation', 'delegationDomain'))->toBe('legacy.zone');
            expect(Setting::getValue('delegation', 'tencent'))->toBe([
                'domain' => 'legacy.zone',
                'provider' => 'tencent',
                'secretId' => 'legacy-id',
                'secretKey' => 'legacy-key',
            ]);
            $dnsTools = Setting::where('group_id', $siteGroup->id)->where('key', 'dnsTools')->first();
            expect($dnsTools?->value)->toBe(['custom' => 'https://dns.example.com']);
            $autoRefundOnSync = Setting::where('group_id', $siteGroup->id)->where('key', 'autoRefundOnSync')->first();
            expect($autoRefundOnSync?->value)->toBeTrue();

            $callbackGroup = SettingGroup::where('name', 'callback')->first();
            $defaultCallback = $callbackGroup?->settings()->where('key', 'default')->first();
            expect($defaultCallback?->value)->toBe([
                'sources' => '',
                'token' => '',
                'id_field' => 'id',
                'allowed_ips' => '',
            ]);
        },
        function (): array {
            $siteGroup = SettingGroup::where('name', 'site')->first();
            $delegationGroup = SettingGroup::where('name', 'delegation')->first();

            return [
                'groups_count' => SettingGroup::count(),
                'settings_count' => Setting::count(),
                'site_legacy_delegation_count' => $siteGroup
                    ? Setting::where('group_id', $siteGroup->id)->where('key', 'delegation')->count()
                    : 0,
                'delegation_default_domain' => Setting::getValue('delegation', 'delegationDomain'),
                'delegation_legacy_config' => $delegationGroup
                    ? Setting::getValue('delegation', 'tencent')
                    : null,
                'delegation_examples' => $delegationGroup
                    ? $delegationGroup->settings()
                        ->whereIn('key', ['tencent', 'cloudflare', 'aliyun'])
                        ->orderBy('key')
                        ->pluck('value', 'key')
                        ->all()
                    : [],
            ];
        },
    ],
    'NotificationTemplateSeeder' => [
        NotificationTemplateSeeder::class,
        function (): void {
            expect(NotificationTemplate::where('code', 'cert_issued')->first())->not->toBeNull();
            expect(NotificationTemplate::where('code', 'cert_expire')->first())->not->toBeNull();
            expect(NotificationTemplate::where('code', 'auto_renew_failed')->first())->not->toBeNull();
            expect(NotificationTemplate::where('code', 'balance_forecast')->first())->not->toBeNull();
            expect(NotificationTemplate::where('code', 'delegation_invalid')->first())->toBeNull();
        },
        function (): void {
            // 用户自定义已存在的模板（修改内容），seeder 再跑不应覆盖
            $existing = NotificationTemplate::where('code', 'cert_issued')->first();
            if ($existing) {
                $existing->update([
                    'name' => '自定义签发通知',
                    'content' => 'custom-content',
                    'variables' => ['order_id'],
                    'example' => 'custom-example',
                ]);
            } else {
                NotificationTemplate::create([
                    'code' => 'cert_issued',
                    'name' => '自定义签发通知',
                    'content' => 'custom-content',
                    'variables' => ['order_id'],
                    'example' => 'custom-example',
                    'status' => 1,
                ]);
            }
        },
        function (): void {
            $template = NotificationTemplate::where('code', 'cert_issued')->first();
            expect($template)->not->toBeNull();
            expect(NotificationTemplate::where('code', 'cert_issued')->count())->toBe(1);
            expect((string) $template->name)->toBe('自定义签发通知');
            expect((string) $template->content)->toBe('custom-content');
        },
        function (): array {
            return NotificationTemplate::query()
                ->get()
                ->map(fn (NotificationTemplate $template): array => [
                    'code' => (string) $template->code,
                    'name' => (string) $template->name,
                    'status' => (int) $template->status,
                    'content_hash' => md5((string) $template->content),
                    'variables_hash' => md5(json_encode($template->variables ?? [])),
                    'example_hash' => md5((string) ($template->example ?? '')),
                ])
                ->sortBy(fn (array $item): string => $item['code'])
                ->values()
                ->all();
        },
    ],
]);

test('核心 Seeder 在空数据时可创建基础数据', function (
    string $seederClass,
    Closure $assertCreated,
    Closure $_prepareExisting,
    Closure $_assertNotOverwritten,
    Closure $_snapshot,
): void {
    $this->seed($seederClass);

    $assertCreated();
})->with('core_seeders');

test('核心 Seeder 幂等：仅新增缺失项，不覆盖已有值，重复执行无额外变更', function (
    string $seederClass,
    Closure $_assertCreated,
    Closure $prepareExisting,
    Closure $assertNotOverwritten,
    Closure $snapshot,
): void {
    $prepareExisting();

    $this->seed($seederClass);
    $assertNotOverwritten();
    $afterFirst = $snapshot();

    $this->seed($seederClass);
    $assertNotOverwritten();
    $afterSecond = $snapshot();

    expect($afterSecond)->toBe($afterFirst);
})->with('core_seeders');

test('SettingSeeder 幂等补齐 provider 示例且不覆盖已有示例', function () {
    $group = SettingGroup::create([
        'name' => 'delegation',
        'title' => '域名委托',
        'description' => null,
        'weight' => 3,
    ]);
    $customTencent = [
        'domain' => '',
        'provider' => 'tencent',
        'secretId' => 'keep-existing-id',
        'secretKey' => '',
    ];
    Setting::create([
        'group_id' => $group->id,
        'key' => 'tencent',
        'type' => 'array',
        'value' => $customTencent,
        'description' => '已有腾讯示例',
        'weight' => 20,
    ]);

    $this->seed(SettingSeeder::class);
    $this->seed(SettingSeeder::class);

    $group->refresh();
    expect($group->title)->toBe('域名委托')
        ->and((int) $group->weight)->toBe(3)
        ->and(Setting::getValue('delegation', 'tencent'))->toBe($customTencent)
        ->and(Setting::getValue('delegation', 'cloudflare'))->toBe([
            'domain' => '',
            'provider' => 'cloudflare',
            'zoneId' => '',
            'apiToken' => '',
        ])
        ->and(Setting::getValue('delegation', 'aliyun'))->toBe([
            'domain' => '',
            'provider' => 'aliyun',
            'accessKeyId' => '',
            'accessKeySecret' => '',
        ])
        ->and($group->settings()->where('key', 'tencent')->count())->toBe(1)
        ->and($group->settings()->where('key', 'cloudflare')->count())->toBe(1)
        ->and($group->settings()->where('key', 'aliyun')->count())->toBe(1)
        ->and(app(DelegationConfigService::class)->all())->toBe([])
        ->and(app(DelegationConfigService::class)->invalidSettings())->toBe([]);
});

test('SettingSeeder 对已有域配置的 provider 不再添加示例', function () {
    $group = SettingGroup::create([
        'name' => 'delegation',
        'title' => '域名委托',
        'description' => null,
        'weight' => 3,
    ]);
    Setting::create([
        'group_id' => $group->id,
        'key' => 'draftExampleCom',
        'type' => 'array',
        'value' => [
            'domain' => 'draft.example.com',
            'provider' => 'cloudflare',
            'zoneId' => '',
            'apiToken' => '',
        ],
        'description' => '待补凭据的 Cloudflare 配置',
        'weight' => 2,
    ]);

    $this->seed(SettingSeeder::class);
    $this->seed(SettingSeeder::class);

    expect(Setting::getValue('delegation', 'tencent'))->toBeArray()
        ->and(Setting::getValue('delegation', 'cloudflare'))->toBeNull()
        ->and(Setting::getValue('delegation', 'aliyun'))->toBeArray()
        ->and($group->settings()->where('key', 'cloudflare')->exists())->toBeFalse();
});

test('SettingSeeder 按现有 ca 权重插入 delegation 且重跑不覆盖人工排序', function () {
    foreach ([
        ['name' => 'site', 'title' => '站点设置', 'weight' => 5],
        ['name' => 'ca', 'title' => '证书接口', 'weight' => 20],
        ['name' => 'callback', 'title' => '回调设置', 'weight' => 21],
        ['name' => 'mail', 'title' => '邮件设置', 'weight' => 40],
        ['name' => 'sms', 'title' => '短信设置', 'weight' => 50],
        ['name' => 'alipay', 'title' => '支付宝设置', 'weight' => 60],
        ['name' => 'wechat', 'title' => '微信支付设置', 'weight' => 70],
        ['name' => 'bankAccount', 'title' => '银行账户设置', 'weight' => 80],
        ['name' => 'enterprise', 'title' => '工商信息查询', 'weight' => 90],
        ['name' => 'brand', 'title' => '品牌设置', 'weight' => 100],
    ] as $group) {
        SettingGroup::create($group);
    }

    $this->seed(SettingSeeder::class);

    expect((int) SettingGroup::where('name', 'ca')->value('weight'))->toBe(20)
        ->and((int) SettingGroup::where('name', 'delegation')->value('weight'))->toBe(21)
        ->and((int) SettingGroup::where('name', 'callback')->value('weight'))->toBe(22)
        ->and((int) SettingGroup::where('name', 'mail')->value('weight'))->toBe(41);

    SettingGroup::where('name', 'delegation')->update(['weight' => 777]);
    SettingGroup::where('name', 'callback')->update(['weight' => 333]);

    $this->seed(SettingSeeder::class);

    expect((int) SettingGroup::where('name', 'delegation')->value('weight'))->toBe(777)
        ->and((int) SettingGroup::where('name', 'callback')->value('weight'))->toBe(333)
        ->and((int) SettingGroup::where('name', 'mail')->value('weight'))->toBe(41);
});

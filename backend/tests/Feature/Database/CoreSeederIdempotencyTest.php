<?php

use App\Models\Admin;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\UserLevel;
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

            $delegation = Setting::where('group_id', $siteGroup->id)
                ->where('key', 'delegation')
                ->first();

            expect($delegation)->not->toBeNull();
            expect($delegation->value)->toBe([
                'proxyZone' => '',
                'secretId' => '',
                'secretKey' => '',
            ]);
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
                'value' => ['proxyZone' => 'custom.zone', 'secretId' => 'id123', 'secretKey' => 'key456'],
                'description' => '自定义委托',
                'weight' => 99,
            ]);
        },
        function (): void {
            $siteGroup = SettingGroup::where('name', 'site')->first();
            expect($siteGroup)->not->toBeNull();

            $delegation = Setting::where('group_id', $siteGroup->id)
                ->where('key', 'delegation')
                ->first();

            expect($delegation)->not->toBeNull();
            expect(Setting::where('group_id', $siteGroup->id)->where('key', 'delegation')->count())->toBe(1);
            expect($delegation->value)->toBe(['proxyZone' => 'custom.zone', 'secretId' => 'id123', 'secretKey' => 'key456']);
            expect((string) $delegation->description)->toBe('自定义委托');
            expect((int) $delegation->weight)->toBe(99);
        },
        function (): array {
            $siteGroup = SettingGroup::where('name', 'site')->first();
            $delegation = null;
            $delegationCount = 0;
            if ($siteGroup) {
                $delegationCount = Setting::where('group_id', $siteGroup->id)
                    ->where('key', 'delegation')
                    ->count();

                $delegation = Setting::where('group_id', $siteGroup->id)
                    ->where('key', 'delegation')
                    ->first();
            }

            return [
                'groups_count' => SettingGroup::count(),
                'settings_count' => Setting::count(),
                'delegation_count' => $delegationCount,
                'delegation_value' => $delegation?->value,
                'delegation_description' => $delegation?->description,
                'delegation_weight' => $delegation?->weight,
            ];
        },
    ],
    'NotificationTemplateSeeder' => [
        NotificationTemplateSeeder::class,
        function (): void {
            expect(findTemplateByCodeAndChannels('cert_issued', ['sms']))->not->toBeNull();
            expect(findTemplateByCodeAndChannels('cert_issued', ['mail']))->not->toBeNull();
        },
        function (): void {
            NotificationTemplate::create([
                'code' => 'cert_issued',
                'name' => '自定义签发通知',
                'content' => 'custom-sms-content',
                'variables' => ['order_id', 'mobile'],
                'example' => 'custom-example',
                'channels' => ['sms'],
                'status' => 1,
            ]);
        },
        function (): void {
            $template = findTemplateByCodeAndChannels('cert_issued', ['sms']);
            expect($template)->not->toBeNull();
            expect(countTemplateByCodeAndChannels('cert_issued', ['sms']))->toBe(1);
            expect((string) $template->name)->toBe('自定义签发通知');
            expect((string) $template->content)->toBe('custom-sms-content');
        },
        function (): array {
            // 仅比较关键字段快照，避免模板大文本直接比对。
            return NotificationTemplate::query()
                ->get()
                ->map(fn (NotificationTemplate $template): array => [
                    'code' => (string) $template->code,
                    'channels' => normalizeChannels((array) ($template->channels ?? [])),
                    'name' => (string) $template->name,
                    'status' => (int) $template->status,
                    'content_hash' => md5((string) $template->content),
                    'variables_hash' => md5(json_encode($template->variables ?? [])),
                    'example_hash' => md5((string) ($template->example ?? '')),
                ])
                ->sortBy(fn (array $item): string => $item['code'].'|'.implode(',', $item['channels']))
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

function normalizeChannels(array $channels): array
{
    sort($channels);

    return array_values($channels);
}

function findTemplateByCodeAndChannels(string $code, array $channels): ?NotificationTemplate
{
    $normalizedChannels = normalizeChannels($channels);

    return NotificationTemplate::where('code', $code)
        ->get()
        ->first(function (NotificationTemplate $template) use ($normalizedChannels): bool {
            return normalizeChannels((array) ($template->channels ?? [])) === $normalizedChannels;
        });
}

function countTemplateByCodeAndChannels(string $code, array $channels): int
{
    $normalizedChannels = normalizeChannels($channels);

    return NotificationTemplate::where('code', $code)
        ->get()
        ->filter(function (NotificationTemplate $template) use ($normalizedChannels): bool {
            return normalizeChannels((array) ($template->channels ?? [])) === $normalizedChannels;
        })
        ->count();
}

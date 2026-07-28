<?php

use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * 平台设置的存量导入路径：升级链路把旧 platform-config.json 暂存到
 * storage/app/legacy-platform-config/ 后，SettingSeeder 先导入 Beian/Title/Brands，再补齐其余默认项。
 */
function rerunPlatformSettingSeeder(): void
{
    (new SettingSeeder)->run();
}

function platformSettingsSiteGroupId(): int
{
    $id = DB::table('setting_groups')->where('name', 'site')->value('id');
    if ($id === null) {
        $id = DB::table('setting_groups')->insertGetId([
            'name' => 'site', 'title' => '站点设置', 'weight' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

function resetPlatformSettingsRows(int $siteId): void
{
    DB::table('settings')->where('group_id', $siteId)->whereIn('key', ['beian', 'logo', 'logoExpanded', 'qrcode'])->delete();
    DB::table('settings')->updateOrInsert(
        ['group_id' => $siteId, 'key' => 'name'],
        ['type' => 'string', 'value' => null, 'weight' => 2, 'created_at' => now(), 'updated_at' => now()],
    );
    $brandId = DB::table('setting_groups')->where('name', 'brand')->value('id');
    if ($brandId !== null) {
        DB::table('settings')->where('group_id', $brandId)->delete();
    }
}

beforeEach(fn () => File::deleteDirectory(storage_path('app/legacy-platform-config')));
afterEach(fn () => File::deleteDirectory(storage_path('app/legacy-platform-config')));

test('存量暂存存在时 Seeder 导入 Beian/Title/Brands', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);

    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), json_encode([
        'Title' => '某某证书平台',
        'Beian' => '粤ICP备2020123456号',
        'Brands' => ['SSLTRUS', 'certum', 'unknownbrand'],
    ], JSON_UNESCAPED_UNICODE));
    File::put(storage_path('app/legacy-platform-config/admin.json'), json_encode([
        'Brands' => ['DIGICERT', 'certum', 'digicert'],
    ]));

    rerunPlatformSettingSeeder();

    $siteRows = DB::table('settings')->where('group_id', $siteId)->pluck('value', 'key');
    expect($siteRows['beian'])->toBe('粤ICP备2020123456号')
        ->and($siteRows['name'])->toBe('某某证书平台');

    $brandId = DB::table('setting_groups')->where('name', 'brand')->value('id');
    $brandRows = DB::table('settings')->where('group_id', $brandId)->pluck('value', 'key');
    expect(json_decode($brandRows['all'], true))->toBe([
        'digicert' => 'DigiCert',
        'certum' => 'Certum',
    ])->and(json_decode($brandRows['admin'], true))->toBe(['digicert', 'certum'])
        ->and(json_decode($brandRows['user'], true))->toBe(['ssltrus', 'certum', 'unknownbrand']);
});

test('无暂存时 Seeder 落安全默认：beian 空串而非占位备案号', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);

    rerunPlatformSettingSeeder();

    $siteRows = DB::table('settings')->where('group_id', $siteId)->pluck('value', 'key');
    expect($siteRows['beian'])->toBe('')
        ->and($siteRows['name'])->toBe('SSL')
        ->and($siteRows['logoExpanded'])->toBe('');

    $brandId = DB::table('setting_groups')->where('name', 'brand')->value('id');
    $allBrands = json_decode(DB::table('settings')->where('group_id', $brandId)->where('key', 'all')->value('value'), true);
    $adminBrands = json_decode(DB::table('settings')->where('group_id', $brandId)->where('key', 'admin')->value('value'), true);
    $userBrands = json_decode(DB::table('settings')->where('group_id', $brandId)->where('key', 'user')->value('value'), true);
    expect(array_keys($allBrands))->toBe($adminBrands)
        ->and($adminBrands)->toHaveCount(9)
        ->and($userBrands)->toHaveCount(9);
});

test('旧 Seeder 默认站点名允许存量 Title 接管', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);
    DB::table('settings')->where('group_id', $siteId)->where('key', 'name')->update(['value' => 'SSL']);

    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), json_encode([
        'Title' => '存量自定义站点名',
    ], JSON_UNESCAPED_UNICODE));

    rerunPlatformSettingSeeder();

    expect(DB::table('settings')->where('group_id', $siteId)->where('key', 'name')->value('value'))
        ->toBe('存量自定义站点名');
});

test('已有设置值不被 Seeder 重跑覆盖（幂等）', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);
    rerunPlatformSettingSeeder();

    DB::table('settings')->where('group_id', $siteId)->where('key', 'beian')->update(['value' => '运营商已改']);
    DB::table('settings')->where('group_id', $siteId)->where('key', 'name')->update(['value' => '后台自定义站点名']);
    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), json_encode([
        'Beian' => '旧值不应覆盖',
        'Title' => '旧站点名不应覆盖',
    ], JSON_UNESCAPED_UNICODE));

    rerunPlatformSettingSeeder();

    expect(DB::table('settings')->where('group_id', $siteId)->where('key', 'beian')->value('value'))
        ->toBe('运营商已改')
        ->and(DB::table('settings')->where('group_id', $siteId)->where('key', 'name')->value('value'))
        ->toBe('后台自定义站点名');
});

test('平台设置迁移不创建或整理设置数据', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);

    $migration = require database_path('migrations/2026_07_20_000001_add_platform_settings.php');
    $migration->up();

    expect(DB::table('settings')->where('group_id', $siteId)->where('key', 'beian')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('group_id', $siteId)->where('key', 'logoExpanded')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('group_id', $siteId)->where('key', 'name')->value('value'))->toBeNull();
});

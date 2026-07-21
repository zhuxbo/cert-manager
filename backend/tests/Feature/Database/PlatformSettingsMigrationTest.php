<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * 平台设置迁移的存量导入路径：升级链路把旧 platform-config.json 暂存到
 * storage/app/legacy-platform-config/ 后，迁移应导入 Beian/Title/Brands 而非落默认值。
 * 迁移 enum DDL 带幂等守卫（已含 image 时跳过），因此可在事务内安全重跑 up()。
 */
function rerunPlatformSettingsMigration(): void
{
    $migration = require database_path('migrations/2026_07_20_000001_add_platform_settings.php');
    $migration->up();
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
    DB::table('settings')->where('group_id', $siteId)->whereIn('key', ['beian', 'logo', 'qrcode'])->delete();
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

test('存量暂存存在时迁移导入 Beian/Title/Brands', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);

    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), json_encode([
        'Title' => '某某证书平台',
        'Beian' => '粤ICP备2020123456号',
        'Brands' => ['certum', 'SSLTRUS', 'unknownbrand'],
    ], JSON_UNESCAPED_UNICODE));
    File::put(storage_path('app/legacy-platform-config/admin.json'), json_encode([
        'Brands' => ['digicert'],
    ]));

    rerunPlatformSettingsMigration();

    $siteRows = DB::table('settings')->where('group_id', $siteId)->pluck('value', 'key');
    expect($siteRows['beian'])->toBe('粤ICP备2020123456号')
        ->and($siteRows['name'])->toBe('某某证书平台');

    $brandId = DB::table('setting_groups')->where('name', 'brand')->value('id');
    $brandRows = DB::table('settings')->where('group_id', $brandId)->pluck('value', 'key');
    expect(json_decode($brandRows['user'], true))->toBe([
        'certum' => 'Certum',
        'ssltrus' => '锐安信',
        'unknownbrand' => 'unknownbrand',
    ])->and(json_decode($brandRows['admin'], true))->toBe(['digicert' => 'DigiCert']);
});

test('无暂存时迁移落安全默认：beian 空串而非占位备案号', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);

    rerunPlatformSettingsMigration();

    $siteRows = DB::table('settings')->where('group_id', $siteId)->pluck('value', 'key');
    expect($siteRows['beian'])->toBe('')
        ->and($siteRows['name'])->toBe('SSL');

    $brandId = DB::table('setting_groups')->where('name', 'brand')->value('id');
    $userBrands = json_decode(DB::table('settings')->where('group_id', $brandId)->where('key', 'user')->value('value'), true);
    expect(array_keys($userBrands))->toContain('certum', 'digicert')
        ->and($userBrands)->toHaveCount(9);
});

test('已有设置值不被迁移重跑覆盖（幂等）', function () {
    $siteId = platformSettingsSiteGroupId();
    resetPlatformSettingsRows($siteId);
    rerunPlatformSettingsMigration();

    DB::table('settings')->where('group_id', $siteId)->where('key', 'beian')->update(['value' => '运营商已改']);
    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), json_encode(['Beian' => '旧值不应覆盖']));

    rerunPlatformSettingsMigration();

    expect(DB::table('settings')->where('group_id', $siteId)->where('key', 'beian')->value('value'))
        ->toBe('运营商已改');
});

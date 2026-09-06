<?php

use App\Auth\JwtBlacklistStorage;
use App\Models\Admin;
use App\Models\AdminRefreshToken;
use App\Models\User;
use App\Models\UserRefreshToken;
use App\Services\Upgrade\RuntimeSessionCutover;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tymon\JWTAuth\Providers\Storage\Illuminate as LegacyBlacklistStorage;

uses(RefreshDatabase::class)->group('database');

test('待切库时保留旧黑名单且缓存清理不让已吊销 token 复活', function () {
    DB::table('migrations')->where('migration', RuntimeSessionCutover::MIGRATION)->delete();
    $legacy = new LegacyBlacklistStorage(Cache::store());
    $legacy->forever('legacy-revoked-token', 'blacklisted');
    $storage = new JwtBlacklistStorage(app());

    expect($storage->get('legacy-revoked-token'))->toBe('blacklisted');
    Artisan::call('cache:clear');
    expect($legacy->get('legacy-revoked-token'))->toBe('blacklisted')
        ->and($storage->get('legacy-revoked-token'))->toBe('blacklisted');

    expect(Artisan::call('cache:clear-all', ['--quick' => true, '--without-composer' => true]))->toBe(1)
        ->and($legacy->get('legacy-revoked-token'))->toBe('blacklisted');
});

test('失败升级及其他进程不能触发会话吊销', function () {
    DB::table('migrations')->where('migration', RuntimeSessionCutover::MIGRATION)->delete();
    $admin = Admin::factory()->create(['token_version' => 8]);
    $status = new UpgradeStatusManager;
    $status->start('test');
    try {
        $status->fail('test failure');
        RuntimeSessionCutover::finishCompletedUpgrade(getmypid());
        $status->complete('old', 'new');
        RuntimeSessionCutover::finishCompletedUpgrade(getmypid() + 1);
        expect($admin->refresh()->token_version)->toBe(8)
            ->and(RuntimeSessionCutover::isPending())->toBeTrue();
    } finally {
        $status->clear();
    }
});

test('升级中跳过迁移且成功收尾保留会话', function () {
    DB::table('migrations')->where('migration', RuntimeSessionCutover::MIGRATION)->delete();
    $admin = Admin::factory()->create(['token_version' => 8]);
    AdminRefreshToken::createToken($admin->id);
    $status = new UpgradeStatusManager;
    $status->start('test');
    UpgradeFreezeLock::freeze(ownerSource: 'web');

    try {
        Artisan::call('migrate', ['--path' => RuntimeSessionCutover::MIGRATION_PATH, '--force' => true]);
        expect($admin->refresh()->token_version)->toBe(8)
            ->and(AdminRefreshToken::count())->toBe(1)
            ->and(RuntimeSessionCutover::isPending())->toBeTrue();

        RuntimeSessionCutover::finishCompletedUpgrade(getmypid());
        expect($admin->refresh()->token_version)->toBe(8);

        UpgradeFreezeLock::unfreeze();
        $status->complete('old', 'new');
        // 模拟旧升级器：不调用新增收尾函数，只走 Artisan 原有的应用终止回调。
        app()->terminate();
        expect($admin->refresh()->token_version)->toBe(8)
            ->and(AdminRefreshToken::count())->toBe(1)
            ->and(RuntimeSessionCutover::isPending())->toBeFalse();

        RuntimeSessionCutover::finishCompletedUpgrade(getmypid());
        expect($admin->refresh()->token_version)->toBe(8);
    } finally {
        UpgradeFreezeLock::unfreeze();
        $status->clear();
    }
});

test('已切库实例的普通升级保留会话', function () {
    $admin = Admin::factory()->create(['token_version' => 8]);
    AdminRefreshToken::createToken($admin->id);
    $status = new UpgradeStatusManager;
    $status->start('test');
    try {
        $status->complete('old', 'new');
        RuntimeSessionCutover::finishCompletedUpgrade(getmypid());
        expect($admin->refresh()->token_version)->toBe(8)
            ->and(AdminRefreshToken::count())->toBe(1);
    } finally {
        $status->clear();
    }
});

test('运行态缓存切库迁移保留所有有效会话', function () {
    $user = User::factory()->create([
        'token_version' => 5,
        'logout_at' => null,
    ]);
    $admin = Admin::factory()->create([
        'token_version' => 8,
        'logout_at' => null,
    ]);
    UserRefreshToken::createToken($user->id);
    AdminRefreshToken::createToken($admin->id);

    $migration = require database_path('migrations/2026_09_04_000001_invalidate_sessions_for_runtime_cache_cutover.php');
    $migration->up();

    $user->refresh();
    $admin->refresh();

    expect((int) $user->token_version)->toBe(5)
        ->and((int) $admin->token_version)->toBe(8)
        ->and($user->logout_at)->toBeNull()
        ->and($admin->logout_at)->toBeNull()
        ->and(UserRefreshToken::query()->count())->toBe(1)
        ->and(AdminRefreshToken::query()->count())->toBe(1);
});

test('文件黑名单搬迁保留到期时间且清旧缓存后仍有效', function () {
    $root = storage_path('framework/testing/jwt-cutover-'.uniqid());
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => $root.'/old',
        'cache.stores.runtime' => ['driver' => 'file', 'path' => $root.'/runtime'],
    ]);
    Cache::forgetDriver(['file', 'runtime']);
    try {
        $legacy = new LegacyBlacklistStorage(Cache::store());
        $legacy->add('expiring', ['valid_until' => 123], 120);
        $legacy->forever('permanent', 'forever');
        Cache::store()->put('unrelated', ['business' => true], 120);
        $migration = require base_path(RuntimeSessionCutover::MIGRATION_PATH);
        $migration->up();
        $migration->up();
        Cache::store()->flush();
        $storage = new JwtBlacklistStorage(app());
        expect($storage->get('expiring'))->toBe(['valid_until' => 123])
            ->and($storage->get('permanent'))->toBe('forever')
            ->and($storage->get('unrelated'))->toBeNull();
        $this->travel(121)->seconds();
        expect($storage->get('expiring'))->toBeNull()
            ->and($storage->get('permanent'))->toBe('forever');
    } finally {
        $this->travelBack();
        File::deleteDirectory($root);
        Cache::forgetDriver(['file', 'runtime']);
    }
});

test('Redis黑名单搬迁保留TTL和永久记录且不覆盖新库记录', function () {
    $prefix = 'jwt-cutover-test-'.uniqid().':';
    config([
        'cache.default' => 'redis',
        'cache.stores.redis.prefix' => $prefix.'old:',
        'cache.stores.runtime' => ['driver' => 'redis', 'connection' => 'default', 'prefix' => $prefix.'new:'],
    ]);
    Cache::forgetDriver(['redis', 'runtime']);
    $old = Cache::store()->tags('tymon.jwt');
    $new = Cache::store('runtime')->tags('tymon.jwt');
    try {
        $old->put('expiring', ['valid_until' => 123], 120);
        $old->forever('permanent', 'forever');
        $old->put('already-new', ['valid_until' => 100], 120);
        $new->forever('already-new', 'forever');
        $migration = require base_path(RuntimeSessionCutover::MIGRATION_PATH);
        $migration->up();
        $migration->up();
        $old->flush();
        $storage = new JwtBlacklistStorage(app());
        expect($storage->get('expiring'))->toBe(['valid_until' => 123])
            ->and($storage->get('permanent'))->toBe('forever')
            ->and($storage->get('already-new'))->toBe('forever');
        $store = Cache::store('runtime')->getStore();
        $key = $store->getPrefix().$new->taggedItemKey('expiring');
        expect($store->connection()->ttl($key))->toBeGreaterThan(110)->toBeLessThanOrEqual(121);
    } finally {
        $old->flush();
        $new->flush();
        Cache::forgetDriver(['redis', 'runtime']);
    }
});

test('复制失败不记迁移完成且保留旧黑名单供重试', function () {
    DB::table('migrations')->where('migration', RuntimeSessionCutover::MIGRATION)->delete();
    $root = storage_path('framework/testing/jwt-failure-'.uniqid());
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => $root,
        'cache.stores.runtime' => ['driver' => 'null'],
    ]);
    Cache::forgetDriver(['file', 'runtime']);
    try {
        $legacy = new LegacyBlacklistStorage(Cache::store());
        $legacy->forever('revoked', 'forever');
        expect(fn () => Artisan::call('migrate', ['--path' => RuntimeSessionCutover::MIGRATION_PATH, '--force' => true]))
            ->toThrow(RuntimeException::class, '复制文件 JWT 黑名单失败');
        expect(RuntimeSessionCutover::isPending())->toBeTrue()
            ->and($legacy->get('revoked'))->toBe('forever');
        config(['cache.stores.runtime' => ['driver' => 'array']]);
        Cache::forgetDriver('runtime');
        Artisan::call('migrate', ['--path' => RuntimeSessionCutover::MIGRATION_PATH, '--force' => true]);
        expect(RuntimeSessionCutover::isPending())->toBeFalse()
            ->and((new JwtBlacklistStorage(app()))->get('revoked'))->toBe('forever');
    } finally {
        File::deleteDirectory($root);
        Cache::forgetDriver(['file', 'runtime']);
    }
});

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

test('升级中跳过会话迁移且成功收尾只吊销一次', function () {
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
        expect($admin->refresh()->token_version)->toBe(9)
            ->and(AdminRefreshToken::count())->toBe(0)
            ->and(RuntimeSessionCutover::isPending())->toBeFalse();

        RuntimeSessionCutover::finishCompletedUpgrade(getmypid());
        expect($admin->refresh()->token_version)->toBe(9);
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

test('运行态缓存切库迁移会立即吊销所有存量会话', function () {
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

    expect((int) $user->token_version)->toBe(6)
        ->and((int) $admin->token_version)->toBe(9)
        ->and($user->logout_at)->not->toBeNull()
        ->and($admin->logout_at)->not->toBeNull()
        ->and($user->logout_at->isBefore(now()->subSeconds((int) config('jwt.blacklist_grace_period'))))->toBeTrue()
        ->and($admin->logout_at->isBefore(now()->subSeconds((int) config('jwt.blacklist_grace_period'))))->toBeTrue()
        ->and(UserRefreshToken::query()->count())->toBe(0)
        ->and(AdminRefreshToken::query()->count())->toBe(0);
});

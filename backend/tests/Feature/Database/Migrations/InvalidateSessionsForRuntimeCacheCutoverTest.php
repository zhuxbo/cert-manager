<?php

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('database');

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

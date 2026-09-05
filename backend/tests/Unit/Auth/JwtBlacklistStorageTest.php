<?php

declare(strict_types=1);

use App\Auth\JwtBlacklistStorage;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tymon\JWTAuth\Contracts\Providers\Storage;

uses(TestCase::class);

test('Redis URL 不能覆盖关键状态与应用缓存的独立 DB', function () {
    expect(config('database.redis.default'))->not->toHaveKey('url')
        ->and(config('database.redis.cache'))->not->toHaveKey('url');
});

test('JWT 黑名单不受普通缓存清理影响', function () {
    $storage = app(Storage::class);
    expect($storage)->toBeInstanceOf(JwtBlacklistStorage::class);

    $storage->forever('revoked-token', 'blacklisted');
    Cache::forever('disposable-cache-sentinel', 'stale');

    Cache::flush();

    expect(Cache::get('disposable-cache-sentinel'))->toBeNull()
        ->and($storage->get('revoked-token'))->toBe('blacklisted');
});

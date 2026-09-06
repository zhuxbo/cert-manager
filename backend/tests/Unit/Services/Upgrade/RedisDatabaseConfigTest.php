<?php

use App\Services\Upgrade\RedisDatabaseConfig;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->originalEnvironmentPath = app()->environmentPath();
    $this->originalEnvironmentFile = app()->environmentFile();
    $this->redisUpgradeDirectory = sys_get_temp_dir().'/redis-upgrade-'.bin2hex(random_bytes(8));
    File::makeDirectory($this->redisUpgradeDirectory);
    app()->useEnvironmentPath($this->redisUpgradeDirectory)->loadEnvironmentFrom('.env');
    config([
        'cache.default' => 'redis',
        'queue.default' => 'database',
        'database.redis.default' => ['database' => '0'],
        'database.redis.cache' => ['database' => '1'],
    ]);
});

afterEach(function () {
    app()->useEnvironmentPath($this->originalEnvironmentPath)->loadEnvironmentFrom($this->originalEnvironmentFile);
    File::deleteDirectory($this->redisUpgradeDirectory);
});

test('固定旧配置编号且保留其他内容与文件权限，重复执行不追加', function (string $existing, string $runtime, string $cache) {
    $content = "APP_NAME=original_manager\r\n# 原配置\r\nCACHE_DRIVER=redis\r\n".$existing;
    $path = app()->environmentFilePath();
    File::put($path, $content);
    chmod($path, 0600);
    config(['database.redis.default.database' => $runtime, 'database.redis.cache.database' => $cache]);

    RedisDatabaseConfig::preserve();
    $saved = File::get($path);
    $parsed = Dotenv::parse($saved);
    expect($saved)->toStartWith($content)
        ->and($parsed['REDIS_DB'])->toBe((string) (int) $runtime)
        ->and($parsed['REDIS_CACHE_DB'])->toBe((string) (int) $cache)
        ->and($parsed['APP_NAME'])->toBe('original_manager')
        ->and(fileperms($path) & 0777)->toBe(0600);

    RedisDatabaseConfig::preserve();
    expect(File::get($path))->toBe($saved);
})->with([
    '旧默认两个均缺失' => ['', '0', '1'],
    '仅缓存库显式配置' => ['REDIS_CACHE_DB=5', '0', '5'],
    '仅运行库显式配置' => ['REDIS_DB=3', '3', '1'],
    '已分配自定义库' => ["REDIS_DB=7\r\nREDIS_CACHE_DB=8\r\n", '7', '8'],
    '配置缓存优先于后来修改的 env' => ["REDIS_DB=1\r\nREDIS_CACHE_DB=2\r\n", '3', '4'],
    '带引号及重复键' => ["export 'REDIS_DB' = '03' # runtime\r\nREDIS_CACHE_DB=5\r\nREDIS_CACHE_DB=4", '03', '04'],
]);

test('相同或无效编号和 URL 在写入前拒绝', function (mixed $runtime, mixed $cache, string $extra, string $message) {
    $path = app()->environmentFilePath();
    $content = "APP_NAME=original_manager\n".$extra;
    File::put($path, $content);
    config(['database.redis.default.database' => $runtime, 'database.redis.cache.database' => $cache]);

    expect(fn () => RedisDatabaseConfig::preserve())->toThrow(RuntimeException::class, $message);
    expect(File::get($path))->toBe($content);
})->with([
    '原本同库' => ['0', '00', '', '相同'],
    '缺少有效配置' => [null, '1', '', '无法确定'],
    '负数' => ['-1', '1', '', '无法确定'],
    '超出整数范围' => ['99999999999999999999999999999', '1', '', '无法确定'],
    'URL 配置' => ['0', '1', "REDIS_URL=redis://localhost/5\n", 'REDIS_URL'],
]);

test('仅 Redis 队列启用也固定编号', function () {
    File::put(app()->environmentFilePath(), "APP_NAME=original_manager\n");
    config(['cache.default' => 'file', 'queue.default' => 'redis']);
    RedisDatabaseConfig::preserve();
    expect(Dotenv::parse(File::get(app()->environmentFilePath())))->toMatchArray(['REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1']);
});

test('未启用 Redis 不改 env', function () {
    config(['cache.default' => 'file', 'queue.default' => 'database']);
    RedisDatabaseConfig::preserve();
    expect(File::exists(app()->environmentFilePath()))->toBeFalse();
});

test('首次旧后台进程通过迁移保存仍在内存中的原编号', function () {
    File::put(app()->environmentFilePath(), "APP_NAME=original_manager\nREDIS_CACHE_DB=1\n");
    config(['cache.stores.runtime' => null]);

    $migration = require database_path('migrations/2026_09_04_000001_invalidate_sessions_for_runtime_cache_cutover.php');
    $migration->shouldRun();

    expect(Dotenv::parse(File::get(app()->environmentFilePath())))->toMatchArray(['REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1']);
});

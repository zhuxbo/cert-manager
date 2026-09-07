<?php

use App\Services\Upgrade\RedisDatabaseConfig;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
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

test('缺失编号沿用旧配置并去重写回，保留其他内容与文件权限', function (string $existing, string $runtime, string $cache) {
    $content = "APP_NAME=original_manager\r\n# 原配置\r\nCACHE_DRIVER=redis\r\n".$existing;
    $path = app()->environmentFilePath();
    File::put($path, $content);
    chmod($path, 0600);
    config(['database.redis.default.database' => $runtime, 'database.redis.cache.database' => $cache]);

    RedisDatabaseConfig::preserve();
    $saved = File::get($path);
    $parsed = Dotenv::parse($saved);
    expect($saved)->toStartWith("APP_NAME=original_manager\r\n# 原配置\r\nCACHE_DRIVER=redis\r\n")
        ->and(substr_count($saved, 'REDIS_DB='))->toBe(1)
        ->and(substr_count($saved, 'REDIS_CACHE_DB='))->toBe(1)
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
    '带引号及重复键' => ["export 'REDIS_DB' = '03' # runtime\r\nREDIS_CACHE_DB=5\r\nREDIS_CACHE_DB=4", '03', '04'],
]);

test('原位更新缓存编号并合并历史追加项，不改其他多行配置', function () {
    $path = app()->environmentFilePath();
    $other = "# REDIS_CACHE_DB=9\nOTHER=\"first\nREDIS_CACHE_DB=8\nlast\"\n";
    File::put($path, "# 缓存库\nREDIS_CACHE_DB=1\n".$other."REDIS_DB=1\nREDIS_CACHE_DB=0\n");
    config(['database.redis.default.database' => 1, 'database.redis.cache.database' => 0]);

    RedisDatabaseConfig::preserve();

    expect(File::get($path))->toBe("# 缓存库\nREDIS_CACHE_DB=0\n".$other."REDIS_DB=1\n");
    RedisDatabaseConfig::preserve();
    expect(File::get($path))->toBe("# 缓存库\nREDIS_CACHE_DB=0\n".$other."REDIS_DB=1\n");
});

test('无效编号和 URL 在写入前拒绝', function (mixed $runtime, mixed $cache, string $extra, string $message) {
    $path = app()->environmentFilePath();
    $content = "APP_NAME=original_manager\n".$extra;
    File::put($path, $content);
    config(['database.redis.default.database' => $runtime, 'database.redis.cache.database' => $cache]);

    expect(fn () => RedisDatabaseConfig::preserve())->toThrow(RuntimeException::class, $message);
    expect(File::get($path))->toBe($content);
})->with([
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

test('env 的无效显式编号不能退回缓存值', function () {
    $content = "REDIS_DB=invalid\nREDIS_CACHE_DB=2\n";
    File::put(app()->environmentFilePath(), $content);
    expect(fn () => RedisDatabaseConfig::preserve())->toThrow(RuntimeException::class, 'REDIS_DB');
    expect(File::get(app()->environmentFilePath()))->toBe($content);
});

test('同库自动分配无法取得空闲编号时保留 env 原文', function (string $failure) {
    $content = "REDIS_DB=0\nREDIS_CACHE_DB=0\n";
    File::put(app()->environmentFilePath(), $content);
    $probe = Mockery::mock();
    Redis::shouldReceive('resolve')->once()->with('cache')->andReturn($probe);
    $probe->shouldReceive('client')->once()->andReturnSelf();
    $probe->shouldReceive('disconnect')->once();
    if ($failure === 'full') {
        $probe->shouldReceive('select')->times(15)->andReturn(true);
        $probe->shouldReceive('dbsize')->times(15)->andReturn(1);
    } elseif ($failure === 'select') {
        $probe->shouldReceive('select')->once()->andReturn(false);
    } else {
        $probe->shouldReceive('select')->once()->andReturn(true);
        $probe->shouldReceive('dbsize')->once()->andReturn(false);
    }
    expect(fn () => RedisDatabaseConfig::preserve(true, $this->redisUpgradeDirectory))->toThrow(RuntimeException::class);
    expect(File::get(app()->environmentFilePath()))->toBe($content);
    $lock = fopen($this->redisUpgradeDirectory.'/.ssl-manager-redis-db.lock', 'c');
    expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();
    fclose($lock);
})->with(['full', 'select', 'dbsize']);

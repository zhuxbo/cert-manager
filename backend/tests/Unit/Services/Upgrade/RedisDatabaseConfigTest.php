<?php

use App\Services\Upgrade\RedisDatabaseConfig;
use Dotenv\Dotenv;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

test('固定旧配置编号且保留其他内容与文件权限，重复执行不追加', function (string $existing, string $runtime, string $cache) {
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
    '配置缓存优先于后来修改的 env' => ["REDIS_DB=1\r\nREDIS_CACHE_DB=2\r\n", '3', '4'],
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

test('脚本迁移前允许保留同库，迁移后仅为当前 cache 分配空闲编号', function () {
    $path = app()->environmentFilePath();
    $content = "APP_NAME=original_manager\nREDIS_DB=0\nREDIS_CACHE_DB=0\n";
    File::put($path, $content);
    chmod($path, 0600);
    config(['database.redis.cache.database' => 0]);
    RedisDatabaseConfig::preserve(true);
    expect(File::get($path))->toBe($content);

    $otherPath = $this->redisUpgradeDirectory.'/other/backend/.env';
    File::ensureDirectoryExists(dirname($otherPath));
    $other = "APP_NAME=other_manager\nREDIS_HOST=localhost\nREDIS_DB=1\nREDIS_CACHE_DB=2\n";
    File::put($otherPath, $other);
    $probe = Mockery::mock();
    Redis::shouldReceive('resolve')->once()->with('cache')->andReturn($probe);
    $probe->shouldReceive('select')->once()->with(3)->ordered()->andReturn(true);
    $probe->shouldReceive('dbsize')->once()->ordered()->andReturn(1);
    $probe->shouldReceive('select')->once()->with(4)->ordered()->andReturn(true);
    $probe->shouldReceive('dbsize')->once()->ordered()->andReturn(0);
    $probe->shouldReceive('client')->once()->andReturnSelf();
    $probe->shouldReceive('close')->once();

    $oldCache = new Repository(new ArrayStore);
    $previousRestart = time() + 100;
    $oldCache->forever('illuminate:queue:restart', $previousRestart);
    Cache::shouldReceive('store')->once()->andReturn($oldCache);
    Artisan::shouldReceive('call')->once()->with('config:clear')->andReturnUsing(function () use ($path, $oldCache, $previousRestart) {
        expect(Dotenv::parse(File::get($path))['REDIS_CACHE_DB'])->toBe('4')
            ->and($oldCache->get('illuminate:queue:restart'))->toBe($previousRestart);

        return 0;
    });

    RedisDatabaseConfig::separateCacheDatabase($this->redisUpgradeDirectory);
    expect($oldCache->get('illuminate:queue:restart'))->toBe($previousRestart + 1);
    expect(Dotenv::parse(File::get($path)))->toMatchArray([
        'APP_NAME' => 'original_manager', 'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '4',
    ])->and(File::get($otherPath))->toBe($other)
        ->and(fileperms($path) & 0777)->toBe(0600);
    $saved = File::get($path);
    RedisDatabaseConfig::separateCacheDatabase($this->redisUpgradeDirectory);
    expect(File::get($path))->toBe($saved);
});

test('两个编号不同即保持原样，不扫描其他 Manager 或连接 Redis', function () {
    $content = "APP_NAME=original_manager\nREDIS_DB=0\nREDIS_CACHE_DB=1\n";
    File::put(app()->environmentFilePath(), $content);
    Redis::shouldReceive('resolve')->never();
    RedisDatabaseConfig::separateCacheDatabase('/nonexistent-sites-root');
    expect(File::get(app()->environmentFilePath()))->toBe($content);
});

test('缓存库无空闲编号或 Redis 检查失败时不修改编号', function (string $failure) {
    $path = app()->environmentFilePath();
    $content = "APP_NAME=original_manager\nREDIS_DB=0\nREDIS_CACHE_DB=0\n";
    File::put($path, $content);
    config(['database.redis.cache.database' => 0]);
    $probe = Mockery::mock();
    Redis::shouldReceive('resolve')->once()->with('cache')->andReturn($probe);
    $probe->shouldReceive('client')->once()->andReturnSelf();
    $probe->shouldReceive('close')->once();
    if ($failure === 'full') {
        $probe->shouldReceive('select')->times(15)->andReturn(true);
        $probe->shouldReceive('dbsize')->times(15)->andReturn(1);
    } elseif ($failure === 'select') {
        $probe->shouldReceive('select')->once()->andReturn(false);
    } else {
        $probe->shouldReceive('select')->once()->andReturn(true);
        $probe->shouldReceive('dbsize')->once()->andReturn(false);
    }
    expect(fn () => RedisDatabaseConfig::separateCacheDatabase($this->redisUpgradeDirectory))->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe($content);
    $lock = fopen($this->redisUpgradeDirectory.'/.ssl-manager-redis-db.lock', 'c');
    expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();
    fclose($lock);
})->with(['full', 'select', 'dbsize']);

test('分库清配置或通知失败恢复原编号，重试能重新分配并通知旧 worker', function (string $failure) {
    $path = app()->environmentFilePath();
    File::put($path, "APP_NAME=original_manager\nREDIS_DB=0\nREDIS_CACHE_DB=0\n");
    config(['database.redis.cache.database' => 0]);
    $probe = Mockery::mock();
    Redis::shouldReceive('resolve')->twice()->with('cache')->andReturn($probe);
    $probe->shouldReceive('select')->twice()->with(1)->andReturn(true);
    $probe->shouldReceive('dbsize')->twice()->andReturn(0);
    $probe->shouldReceive('client')->twice()->andReturnSelf();
    $probe->shouldReceive('close')->twice();
    $oldCache = Mockery::mock(Repository::class);
    Cache::shouldReceive('store')->twice()->andReturn($oldCache);
    $previousRestart = time() + 100;
    $oldCache->shouldReceive('get')->twice()->with('illuminate:queue:restart')->andReturn($previousRestart);
    if ($failure === 'clear') {
        Artisan::shouldReceive('call')->with('config:clear')->twice()->andReturn(17, 0);
        $oldCache->shouldReceive('forever')->once()->with('illuminate:queue:restart', $previousRestart + 1)->andReturn(true);
    } else {
        Artisan::shouldReceive('call')->with('config:clear')->twice()->andReturn(0);
        $oldCache->shouldReceive('forever')->twice()->with('illuminate:queue:restart', $previousRestart + 1)->andReturn(false, true);
    }

    expect(fn () => RedisDatabaseConfig::separateCacheDatabase($this->redisUpgradeDirectory))->toThrow(RuntimeException::class);
    expect(Dotenv::parse(File::get($path))['REDIS_CACHE_DB'])->toBe('0')
        ->and(config('database.redis.cache.database'))->toBe(0);
    RedisDatabaseConfig::separateCacheDatabase($this->redisUpgradeDirectory);
    expect(Dotenv::parse(File::get($path)))->toMatchArray(['REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1']);
})->with(['clear', 'notify']);

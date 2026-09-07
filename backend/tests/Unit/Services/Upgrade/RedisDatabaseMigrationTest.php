<?php

use App\Services\Upgrade\PackageExtractor;
use App\Services\Upgrade\RedisDatabaseConfig;
use Dotenv\Dotenv;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! getenv('REDIS_UPGRADE_TEST_PORT')) {
        $this->markTestSkipped('需显式提供隔离 Redis 实例 REDIS_UPGRADE_TEST_PORT');
    }
    $this->redis = new Redis;
    $this->redis->connect('redis', (int) getenv('REDIS_UPGRADE_TEST_PORT'));
    // 专用测试 Redis 进程，由外层 runner 创建与销毁，绝不连接默认端口。
    expect((int) getenv('REDIS_UPGRADE_TEST_PORT'))->not->toBe(6379);
    $this->redis->flushAll();
    $this->oldEnvironmentPath = app()->environmentPath();
    $this->oldEnvironmentFile = app()->environmentFile();
    $this->directory = sys_get_temp_dir().'/redis-migration-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->directory);
    app()->useEnvironmentPath($this->directory)->loadEnvironmentFrom('.env');
    config([
        'cache.default' => 'redis', 'queue.default' => 'database',
        'database.redis.client' => 'phpredis',
        'database.redis.options.prefix' => 'manager_prefix_',
        'database.redis.default' => ['host' => 'redis', 'port' => (int) getenv('REDIS_UPGRADE_TEST_PORT'), 'database' => 0],
        'database.redis.cache' => ['host' => 'redis', 'port' => (int) getenv('REDIS_UPGRADE_TEST_PORT'), 'database' => 1],
    ]);
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return [];
        }
    });
    Artisan::shouldReceive('call')->with('config:clear')->andReturn(0)->byDefault();
    app()->forgetInstance('redis');
    RedisFacade::clearResolvedInstance('redis');
    Cache::forgetDriver(array_keys(config('cache.stores')));
    $this->cache = Cache::store();
});

afterEach(function () {
    if (isset($this->redis)) {
        $this->redis->close();
        app()->useEnvironmentPath($this->oldEnvironmentPath)->loadEnvironmentFrom($this->oldEnvironmentFile);
        File::deleteDirectory($this->directory);
    }
});

test('env 优先并迁移两库的类型、二进制值和 TTL，写回去重且重复执行幂等', function () {
    $env = "APP_NAME=unchanged\nREDIS_DB=7\nREDIS_DB=3\nREDIS_CACHE_DB=8\nREDIS_CACHE_DB=4\n";
    File::put(app()->environmentFilePath(), $env);
    chmod(app()->environmentFilePath(), 0600);
    $this->redis->select(0);
    $this->redis->rPush('manager_prefix_queues:tasks', 'job1', 'job2');
    $this->redis->zAdd('manager_prefix_queues:tasks:delayed', 123, 'later');
    $this->redis->set('manager_prefix_runtime:lock', "\0binary\xff", ['px' => 60000]);
    $before = $this->redis->pttl('manager_prefix_runtime:lock');
    $this->redis->select(1);
    $this->redis->hSet('manager_prefix_cache', 'field', 'value');
    RedisDatabaseConfig::preserve(true, $this->directory);
    $saved = File::get(app()->environmentFilePath());
    expect(Dotenv::parse($saved))->toMatchArray(['REDIS_DB' => '3', 'REDIS_CACHE_DB' => '4', 'APP_NAME' => 'unchanged'])
        ->and(substr_count($saved, 'REDIS_DB='))->toBe(1)
        ->and(substr_count($saved, 'REDIS_CACHE_DB='))->toBe(1)
        ->and(fileperms(app()->environmentFilePath()) & 0777)->toBe(0600);
    $this->redis->select(3);
    expect($this->redis->lRange('manager_prefix_queues:tasks', 0, -1))->toBe(['job1', 'job2'])
        ->and($this->redis->zScore('manager_prefix_queues:tasks:delayed', 'later'))->toBe(123.0)
        ->and($this->redis->get('manager_prefix_runtime:lock'))->toBe("\0binary\xff")
        ->and($this->redis->pttl('manager_prefix_runtime:lock'))->toBeGreaterThan(0)->toBeLessThanOrEqual($before);
    $this->redis->select(4);
    expect($this->redis->hGet('manager_prefix_cache', 'field'))->toBe('value');
    RedisDatabaseConfig::preserve(true, $this->directory);
    expect(File::get(app()->environmentFilePath()))->toBe($saved);
});

test('env 同库保留显式 runtime 并避开占用编号自动迁移 cache', function () {
    File::put(app()->environmentFilePath(), "REDIS_DB=3\nREDIS_CACHE_DB=3\n");
    $other = $this->directory.'/other/backend/.env';
    File::ensureDirectoryExists(dirname($other));
    File::put($other, 'REDIS_HOST=redis'."\nREDIS_PORT=".getenv('REDIS_UPGRADE_TEST_PORT')."\nREDIS_DB=1\nREDIS_CACHE_DB=2\n");
    $this->redis->select(0);
    $this->redis->set('runtime', 'state');
    $this->redis->select(1);
    $this->redis->set('cache', 'value');
    RedisDatabaseConfig::preserve(true, $this->directory);
    expect(Dotenv::parse(File::get(app()->environmentFilePath())))->toMatchArray(['REDIS_DB' => '3', 'REDIS_CACHE_DB' => '4']);
    $this->redis->select(3);
    expect($this->redis->get('runtime'))->toBe('state');
    $this->redis->select(4);
    expect($this->redis->get('cache'))->toBe('value');
});

test('0/1 到 1/2 先快照两库，不把队列二次复制进缓存库', function () {
    File::put(app()->environmentFilePath(), "REDIS_DB=1\nREDIS_CACHE_DB=2\n");
    $this->redis->select(0);
    $this->redis->set('runtime', 'state');
    $this->redis->select(1);
    $this->redis->set('cache', 'value');
    RedisDatabaseConfig::preserve(true, $this->directory);
    $this->redis->select(1);
    expect($this->redis->get('runtime'))->toBe('state');
    $this->redis->select(2);
    expect($this->redis->get('cache'))->toBe('value')->and($this->redis->exists('runtime'))->toBe(0);
});

test('目标同名异值中止且不改 env 或源数据，无关目标键保留', function () {
    $env = "REDIS_DB=3\nREDIS_CACHE_DB=4\n";
    File::put(app()->environmentFilePath(), $env);
    $this->redis->select(0);
    $this->redis->set('runtime', 'state');
    $this->redis->select(1);
    $this->redis->set('cache', 'value');
    $this->redis->select(4);
    $this->redis->set('cache', 'conflict');
    expect(fn () => RedisDatabaseConfig::preserve(true, $this->directory))->toThrow(RuntimeException::class, '同名');
    expect(File::get(app()->environmentFilePath()))->toBe($env)->and(config('database.redis.default.database'))->toBe(0);
    $this->redis->select(3);
    expect($this->redis->dbSize())->toBe(0);
    $this->redis->select(0);
    expect($this->redis->get('runtime'))->toBe('state');
    $this->redis->select(4);
    expect($this->redis->get('cache'))->toBe('conflict');
});

test('配置发布失败后重试不会被旧 worker 重启信号阻断，TTL 不延长', function () {
    File::put(app()->environmentFilePath(), "REDIS_DB=3\nREDIS_CACHE_DB=4\n");
    $this->redis->select(0);
    $this->redis->rPush('manager_prefix_queues:tasks', 'job');
    $this->redis->select(1);
    $this->redis->set('expiring', 'value', ['px' => 60000]);
    Artisan::shouldReceive('call')->with('config:clear')->andReturn(17, 0);
    expect(fn () => RedisDatabaseConfig::preserve(true, $this->directory))->toThrow(RuntimeException::class, '配置缓存');
    expect(config('database.redis.default.database'))->toBe(0);
    $this->redis->select(4);
    $ttl = $this->redis->pttl('expiring');
    RedisDatabaseConfig::preserve(true, $this->directory);
    expect(config('database.redis.default.database'))->toBe(3);
    $this->redis->select(4);
    expect($this->redis->get('expiring'))->toBe('value')
        ->and($this->redis->pttl('expiring'))->toBeGreaterThan(0)->toBeLessThanOrEqual($ttl);
});

test('旧配置和 env 都同库时复制 cache 并保留显式 runtime', function () {
    config(['database.redis.cache.database' => 0]);
    app()->forgetInstance('redis');
    RedisFacade::clearResolvedInstance('redis');
    Cache::forgetDriver(array_keys(config('cache.stores')));
    File::put(app()->environmentFilePath(), "REDIS_DB=0\nREDIS_CACHE_DB=0\nREDIS_CACHE_DB=0\n");
    $this->redis->select(0);
    $this->redis->set('blacklist', 'revoked');
    RedisDatabaseConfig::preserve(true, $this->directory);
    $env = Dotenv::parse(File::get(app()->environmentFilePath()));
    expect($env['REDIS_DB'])->toBe('0')->and($env['REDIS_CACHE_DB'])->toBe('1');
    $this->redis->select(1);
    expect($this->redis->get('blacklist'))->toBe('revoked');
});

test('只改 env cache 时保留缺失的 runtime 编号', function () {
    File::put(app()->environmentFilePath(), "REDIS_CACHE_DB=4\n");
    $this->redis->select(1);
    $this->redis->set('cache', 'value');
    RedisDatabaseConfig::preserve(true, $this->directory);
    expect(Dotenv::parse(File::get(app()->environmentFilePath())))->toMatchArray(['REDIS_DB' => '0', 'REDIS_CACHE_DB' => '4']);
    $this->redis->select(4);
    expect($this->redis->get('cache'))->toBe('value');
});

test('后台实际应用包时先完成 Redis 迁移，冲突则不覆盖代码', function (bool $conflict) {
    $source = $this->directory.'/package';
    $install = $this->directory.'/install';
    File::ensureDirectoryExists($source.'/app');
    File::ensureDirectoryExists($install.'/app');
    File::put($source.'/app/Marker.php', 'new');
    File::put($install.'/app/Marker.php', 'old');
    File::put(app()->environmentFilePath(), "REDIS_DB=3\nREDIS_CACHE_DB=4\n");
    $this->redis->select(0);
    $this->redis->set('runtime', 'state');
    if ($conflict) {
        $this->redis->select(3);
        $this->redis->set('runtime', 'conflict');
    }
    $originalBase = base_path();
    app()->setBasePath($install);
    try {
        $extractor = new PackageExtractor;
        $method = (new ReflectionClass($extractor))->getMethod('applyBackendUpgrade');
        if ($conflict) {
            expect(fn () => $method->invoke($extractor, $source))->toThrow(RuntimeException::class, '同名');
            expect(File::get($install.'/app/Marker.php'))->toBe('old');
        } else {
            Artisan::shouldReceive('call')->with('config:clear')->andReturnUsing(function () use ($install) {
                expect(File::get($install.'/app/Marker.php'))->toBe('old');
                $this->redis->select(3);
                expect($this->redis->get('runtime'))->toBe('state');

                return 0;
            });
            $method->invoke($extractor, $source);
            expect(File::get($install.'/app/Marker.php'))->toBe('new');
        }
    } finally {
        app()->setBasePath($originalBase);
    }
})->with([false, true]);

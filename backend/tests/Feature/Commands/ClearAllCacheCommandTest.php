<?php

use App\Models\ErrorLog;
use App\Services\LogBuffer;
use App\Support\Opcache;
use Illuminate\Support\Facades\File;

// LogBuffer::$logs 是静态数组，RefreshDatabase 只回滚 DB、TestCase::tearDown 走 app->flush()
// 而非 app->terminate()，缓冲不会在用例间归零。不清的话，上面只 add 不 flush 的用例会把
// ErrorLog 记录漏进下面 flush 的用例，主断言由邻居记录顶着通过（--filter 单跑即翻）。
beforeEach(fn () => LogBuffer::clear());

/**
 * 可控 OPcache 替身：命令里走 app(Opcache::class)，绑实例即可接管。
 *
 * @param  array{status: string, reason?: string|null, message?: string|null, sapi?: string}  $result
 */
function bindFakeOpcache(array $result, bool $isCli = true): void
{
    $payload = $result + ['reason' => null, 'message' => null, 'sapi' => $isCli ? 'cli' : 'fpm-fcgi'];

    app()->instance(Opcache::class, new class($payload, $isCli) extends Opcache
    {
        public function __construct(private array $payload, private bool $cli) {}

        public function reset(): array
        {
            return $this->payload;
        }

        public function isCli(): bool
        {
            return $this->cli;
        }
    });
}

test('签名为 cache:clear-all', function () {
    $this->artisan('cache:clear-all --quick --without-composer')->assertSuccessful();
});

test('快速模式输出简洁信息', function () {
    $this->artisan('cache:clear-all --quick --without-composer')
        ->expectsOutputToContain('所有缓存清除完成')
        ->assertSuccessful();
});

test('正常模式输出详细信息', function () {
    $this->artisan('cache:clear-all --without-composer')
        ->expectsOutputToContain('开始清除')
        ->expectsOutputToContain('所有缓存清除完成')
        ->assertSuccessful();
});

test('返回成功退出码并清理缓存文件', function () {
    $bootstrapCacheFile = base_path('bootstrap/cache/pest-temp.php');
    $storageCacheFile = base_path('storage/framework/cache/data/pest-temp.cache');
    $storageViewFile = base_path('storage/framework/views/pest-temp.view.php');
    $storageSessionFile = base_path('storage/framework/sessions/pest-temp.session');

    File::ensureDirectoryExists(dirname($bootstrapCacheFile));
    File::ensureDirectoryExists(dirname($storageCacheFile));
    File::ensureDirectoryExists(dirname($storageViewFile));
    File::ensureDirectoryExists(dirname($storageSessionFile));

    File::put($bootstrapCacheFile, '<?php return true;');
    File::put($storageCacheFile, 'cache');
    File::put($storageViewFile, '<html></html>');
    File::put($storageSessionFile, 'session');

    expect(File::exists($bootstrapCacheFile))->toBeTrue();
    expect(File::exists($storageCacheFile))->toBeTrue();
    expect(File::exists($storageViewFile))->toBeTrue();
    expect(File::exists($storageSessionFile))->toBeTrue();

    $this->artisan('cache:clear-all --quick --without-composer')
        ->assertExitCode(0);

    expect(File::exists($bootstrapCacheFile))->toBeFalse();
    expect(File::exists($storageCacheFile))->toBeFalse();
    expect(File::exists($storageViewFile))->toBeFalse();
    expect(File::exists($storageSessionFile))->toBeFalse();
    expect(File::exists(base_path('bootstrap/cache/.gitignore')))->toBeTrue();
    expect(File::exists(base_path('storage/framework/views/.gitignore')))->toBeTrue();
});

// OPcache 段：命令行清的是自己的字节码缓存，够不到 PHP-FPM。
// 「成功」只在 FPM 里才是真成功，CLI 必须显式告知，否则是假成功信号。

test('CLI 下清除成功也提示 PHP-FPM 不受影响', function () {
    bindFakeOpcache(['status' => Opcache::OK], isCli: true);

    $this->artisan('cache:clear-all --quick --without-composer')
        ->expectsOutputToContain('PHP-FPM 的字节码缓存不受影响')
        ->assertSuccessful();
});

test('FPM 下清除成功报成功且不带 CLI 警告', function () {
    bindFakeOpcache(['status' => Opcache::OK], isCli: false);

    $this->artisan('cache:clear-all --without-composer')
        ->expectsOutputToContain('OPcache 字节码缓存清除成功')
        ->doesntExpectOutputToContain('PHP-FPM 的字节码缓存不受影响')
        ->assertSuccessful();
});

test('opcache_reset 返回 false 时报失败但不影响退出码', function () {
    bindFakeOpcache(['status' => Opcache::FAILED, 'reason' => 'reset_returned_false']);

    $this->artisan('cache:clear-all --quick --without-composer')
        ->expectsOutputToContain('OPcache 清除失败')
        ->assertSuccessful();
});

test('restrict_api 受限时告警且命令仍成功', function () {
    bindFakeOpcache([
        'status' => Opcache::SKIPPED,
        'reason' => 'api_restricted',
        'message' => 'Zend OPcache API is restricted',
    ]);

    $this->artisan('cache:clear-all --quick --without-composer')
        ->expectsOutputToContain('opcache.restrict_api')
        ->assertSuccessful();
});

test('扩展未加载时静默跳过，quick 模式不打扰', function () {
    bindFakeOpcache(['status' => Opcache::SKIPPED, 'reason' => 'extension_not_loaded']);

    $this->artisan('cache:clear-all --quick --without-composer')
        ->doesntExpectOutputToContain('OPcache')
        ->assertSuccessful();
});

test('详细模式说明跳过原因', function () {
    bindFakeOpcache(['status' => Opcache::SKIPPED, 'reason' => 'not_enabled', 'sapi' => 'cli']);

    $this->artisan('cache:clear-all --without-composer')
        ->expectsOutputToContain('未启用 OPcache')
        ->assertSuccessful();
});

// 后台按钮（SettingController::clearAllCache）丢弃命令输出并无条件返回成功，
// 失败只有落 error_logs 才对管理员/运维可见。

test('opcache 清理失败落 error_logs', function () {
    bindFakeOpcache(['status' => Opcache::FAILED, 'reason' => 'reset_returned_false']);

    $this->artisan('cache:clear-all --quick --without-composer')->assertSuccessful();
    LogBuffer::flush();

    $log = ErrorLog::where('exception', 'OpcacheResetFailed')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->message)->toContain('reset_returned_false');
});

test('restrict_api 受限同样落 error_logs', function () {
    bindFakeOpcache([
        'status' => Opcache::SKIPPED,
        'reason' => 'api_restricted',
        'message' => 'Zend OPcache API is restricted',
    ]);

    $this->artisan('cache:clear-all --quick --without-composer')->assertSuccessful();
    LogBuffer::flush();

    expect(ErrorLog::where('exception', 'OpcacheResetFailed')->count())->toBe(1);
});

test('正常跳过（未启用 / 扩展缺失）不落 error_logs', function () {
    bindFakeOpcache(['status' => Opcache::SKIPPED, 'reason' => 'not_enabled']);
    $before = ErrorLog::where('exception', 'OpcacheResetFailed')->count();

    $this->artisan('cache:clear-all --quick --without-composer')->assertSuccessful();
    LogBuffer::flush();

    expect(ErrorLog::where('exception', 'OpcacheResetFailed')->count())->toBe($before);
});

test('--without-opcache 完全不碰 OPcache', function () {
    bindFakeOpcache(['status' => Opcache::OK], isCli: true);

    $this->artisan('cache:clear-all --without-composer --without-opcache')
        ->expectsOutputToContain('已跳过 OPcache')
        ->doesntExpectOutputToContain('PHP-FPM 的字节码缓存不受影响')
        ->assertSuccessful();
});

test('--logs 使用 daily channel 配置的文件保留天数', function () {
    config(['logging.channels.daily.days' => 20]);
    $originalStoragePath = storage_path();
    $temporaryStoragePath = sys_get_temp_dir().'/ssl-manager-log-retention-'.uniqid();
    File::ensureDirectoryExists($temporaryStoragePath.'/logs');
    app()->useStoragePath($temporaryStoragePath);
    $old = storage_path('logs/pest-old-retention.log');
    $recent = storage_path('logs/pest-recent-retention.log');
    File::put($old, 'old');
    File::put($recent, 'recent');
    touch($old, now()->subDays(21)->timestamp);
    touch($recent, now()->subDays(19)->timestamp);

    try {
        $this->artisan('cache:clear-all --logs --without-composer --without-opcache')
            ->expectsOutputToContain('20天前的日志文件已清除')
            ->assertSuccessful();

        expect(File::exists($old))->toBeFalse()
            ->and(File::exists($recent))->toBeTrue();
    } finally {
        app()->useStoragePath($originalStoragePath);
        File::deleteDirectory($temporaryStoragePath);
    }
});

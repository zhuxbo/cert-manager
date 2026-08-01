<?php

use App\Models\ErrorLog;
use App\Services\LogBuffer;
use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;
use App\Support\Opcache;
use Illuminate\Support\Facades\Log;

// LogBuffer::$logs 是静态数组、不随 RefreshDatabase 归零，必须逐例清空
// （详见 ClearAllCacheCommandTest 同段注释）
beforeEach(fn () => LogBuffer::clear());

afterEach(function () {
    Mockery::close();
});

// clearCaches() 跑在插件安装/更新/卸载路径上，且是 FPM 进程内——少数能真清掉线上字节码的位置。
// 两条约束：① opcache 与 route/config 分开记账，受限时不能伪装成「清理缓存部分失败」；
// ② 分开 ≠ 不记，清不成必须留痕，否则 restrict_api 的机器上更新插件后字节码没换、全系统零痕迹。

function invokeClearCaches(Opcache $opcache): void
{
    app()->instance(Opcache::class, $opcache);

    $manager = new PluginManager(Mockery::mock(VersionManager::class));
    (new ReflectionClass($manager))->getMethod('clearCaches')->invoke($manager);
}

function fakePluginOpcache(string $status, ?string $reason): Opcache
{
    return new class($status, $reason) extends Opcache
    {
        public function __construct(private string $fakeStatus, private ?string $fakeReason) {}

        public function reset(): array
        {
            return ['status' => $this->fakeStatus, 'reason' => $this->fakeReason, 'message' => null, 'sapi' => 'fpm-fcgi'];
        }
    };
}

test('opcache 受限不会伪装成 route/config 清理失败，但会留痕', function () {
    Log::spy();

    invokeClearCaches(fakePluginOpcache(Opcache::SKIPPED, 'api_restricted'));
    LogBuffer::flush();

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('notice')
        ->withArgs(fn (string $message) => str_contains($message, '[Plugin] opcache: skipped'))
        ->once();
    expect(ErrorLog::where('exception', 'OpcacheResetFailed')->count())->toBe(1);
});

test('opcache_reset 返回 false 时留痕并落 error_logs', function () {
    Log::spy();

    invokeClearCaches(fakePluginOpcache(Opcache::FAILED, 'reset_returned_false'));
    LogBuffer::flush();

    Log::shouldHaveReceived('notice')
        ->withArgs(fn (string $message) => str_contains($message, '[Plugin] opcache: failed'))
        ->once();
    expect(ErrorLog::where('exception', 'OpcacheResetFailed')->count())->toBe(1);
});

test('opcache 正常清除时不记日志也不落库', function () {
    Log::spy();

    invokeClearCaches(fakePluginOpcache(Opcache::OK, null));
    LogBuffer::flush();

    Log::shouldNotHaveReceived('notice');
    expect(ErrorLog::count())->toBe(0);
});

test('当前 SAPI 未启用 opcache 属正常跳过，不落 error_logs', function () {
    Log::spy();

    invokeClearCaches(fakePluginOpcache(Opcache::SKIPPED, 'not_enabled'));
    LogBuffer::flush();

    expect(ErrorLog::count())->toBe(0);
});

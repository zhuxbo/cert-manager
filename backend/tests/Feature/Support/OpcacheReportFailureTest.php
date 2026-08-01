<?php

use App\Models\ErrorLog;
use App\Services\LogBuffer;
use App\Support\Opcache;
use Illuminate\Http\Request;

// reportFailure 的 HTTP 分支（method / scrubUrl / ip）在普通测试里够不到：PHPUnit 进程
// SAPI 恒为 cli，那三段只有生产 FPM 才第一次执行。这里用本类自己的 sapi() 缝隙把进程
// 伪装成 fpm-fcgi，再塞一个真实 Request，让后台按钮那条路径
// （HTTP 请求 → SettingController → Artisan::call）真的被断言覆盖。

beforeEach(fn () => LogBuffer::clear());

/** 伪装成 FPM 进程的 Opcache（只改 SAPI 判定，其余走真实现） */
function httpOpcache(): Opcache
{
    return new class extends Opcache
    {
        protected function sapi(): string
        {
            return 'fpm-fcgi';
        }
    };
}

function failedOpcacheResult(?string $detail = null): array
{
    return ['status' => Opcache::FAILED, 'reason' => 'reset_returned_false', 'message' => $detail, 'sapi' => 'fpm-fcgi'];
}

test('HTTP 上下文写入真实请求方法 / URL / IP', function () {
    app()->instance('request', Request::create(
        'https://ssl.example.com/api/admin/setting/clear-all-cache',
        'POST',
        server: ['REMOTE_ADDR' => '203.0.113.7']
    ));

    httpOpcache()->reportFailure(failedOpcacheResult(), 'artisan cache:clear-all');
    LogBuffer::flush();

    $log = ErrorLog::where('exception', 'OpcacheResetFailed')->sole();
    expect($log->method)->toBe('POST')
        ->and($log->url)->toBe('https://ssl.example.com/api/admin/setting/clear-all-cache')
        ->and($log->ip)->toBe('203.0.113.7')
        ->and($log->status_code)->toBe(500);
});

test('HTTP 上下文的 URL 经 scrubUrl 脱敏', function () {
    app()->instance('request', Request::create(
        'https://ssl.example.com/api/admin/setting/clear-all-cache?token=SECRET-VALUE&page=2',
        'POST'
    ));

    httpOpcache()->reportFailure(failedOpcacheResult(), 'artisan cache:clear-all');
    LogBuffer::flush();

    $url = ErrorLog::where('exception', 'OpcacheResetFailed')->sole()->url;
    // scrubUrl 用 http_build_query 重建，掩码星号会被百分号编码
    expect($url)->not->toContain('SECRET-VALUE')
        ->and(urldecode($url))->toContain('token=******')
        ->and(urldecode($url))->toContain('page=2');
});

test('命令行上下文写入 CLI 与来源标识，ip 为空', function () {
    // 测试进程本就是 CLI，真实现的 sapi() 返回 cli
    (new Opcache)->reportFailure(failedOpcacheResult(), 'artisan cache:clear-all');
    LogBuffer::flush();

    $log = ErrorLog::where('exception', 'OpcacheResetFailed')->sole();
    expect($log->method)->toBe('CLI')
        ->and($log->url)->toBe('artisan cache:clear-all')
        ->and($log->ip)->toBeNull();
});

test('message 带 status / reason / sapi 与原始 detail', function () {
    (new Opcache)->reportFailure(failedOpcacheResult('Zend OPcache API is restricted'), 'plugin lifecycle clearCaches');
    LogBuffer::flush();

    expect(ErrorLog::where('exception', 'OpcacheResetFailed')->sole()->message)
        ->toContain('status=failed')
        ->toContain('reason=reset_returned_false')
        ->toContain('sapi=fpm-fcgi')
        ->toContain('detail=Zend OPcache API is restricted');
});

test('超长 detail 截断到 1000 字符，不撑爆 message 列', function () {
    (new Opcache)->reportFailure(failedOpcacheResult(str_repeat('x', 2000)), 'artisan cache:clear-all');
    LogBuffer::flush();

    expect(mb_strlen(ErrorLog::where('exception', 'OpcacheResetFailed')->sole()->message))->toBe(1000);
});

test('正常跳过与成功都不落 error_logs', function () {
    $opcache = new Opcache;

    $opcache->reportFailure(['status' => Opcache::OK, 'reason' => null, 'message' => null, 'sapi' => 'cli'], 'x');
    $opcache->reportFailure(['status' => Opcache::SKIPPED, 'reason' => 'not_enabled', 'message' => null, 'sapi' => 'cli'], 'x');
    $opcache->reportFailure(['status' => Opcache::SKIPPED, 'reason' => 'extension_not_loaded', 'message' => null, 'sapi' => 'cli'], 'x');
    LogBuffer::flush();

    expect(ErrorLog::count())->toBe(0);
});

test('api_restricted 虽为 skipped 但必须落库', function () {
    (new Opcache)->reportFailure(
        ['status' => Opcache::SKIPPED, 'reason' => 'api_restricted', 'message' => 'restricted', 'sapi' => 'fpm-fcgi'],
        'artisan cache:clear-all'
    );
    LogBuffer::flush();

    expect(ErrorLog::where('exception', 'OpcacheResetFailed')->count())->toBe(1);
});

<?php

use Illuminate\Support\Facades\DB;

function insertCoreLog(string $table, array $attributes): int
{
    $base = match ($table) {
        'admin_logs' => ['admin_id' => null, 'module' => 'Order', 'action' => 'index', 'method' => 'GET', 'url' => '/api/admin/order', 'params' => null, 'response' => null, 'status_code' => 200, 'status' => 1, 'duration' => 0, 'ip' => null, 'user_agent' => null],
        'user_logs' => ['user_id' => null, 'module' => 'Order', 'action' => 'index', 'method' => 'GET', 'url' => '/api/order', 'params' => null, 'response' => null, 'status_code' => 200, 'status' => 1, 'duration' => 0, 'ip' => null, 'user_agent' => null],
        'api_logs' => ['user_id' => null, 'version' => 'v2', 'module' => 'Api', 'action' => 'get', 'method' => 'POST', 'url' => '/api/v2/get', 'params' => null, 'response' => null, 'status_code' => 200, 'status' => 1, 'duration' => 0, 'ip' => null, 'user_agent' => null],
        'callback_logs' => ['module' => 'TopUp', 'action' => 'alipayNotify', 'method' => 'POST', 'url' => '/callback/alipay', 'params' => '{}', 'response' => null, 'ip' => null, 'status' => 0],
        'ca_logs' => ['url' => 'https://ca.test/revalidate', 'api' => 'revalidate', 'params' => null, 'response' => null, 'status_code' => 200, 'status' => 1, 'duration' => 0],
        'error_logs' => ['module' => 'Order', 'action' => 'revalidate', 'method' => 'POST', 'url' => '/api/order/revalidate/1', 'exception' => 'RuntimeException', 'message' => 'failed', 'trace' => null, 'status_code' => 400, 'ip' => null],
    };

    return (int) DB::table($table)->insertGetId(array_merge($base, $attributes));
}

test('logs purge 按 7 天全量和 180 天动作审计窗口清理六张核心表', function () {
    $now = now();
    $ids = [];

    foreach (['admin_logs', 'user_logs', 'api_logs', 'callback_logs', 'ca_logs', 'error_logs'] as $table) {
        $ids[$table]['recent'] = insertCoreLog($table, ['created_at' => $now->copy()->subDays(6)]);
        $ids[$table]['diagnostic'] = insertCoreLog($table, ['created_at' => $now->copy()->subDays(8)]);
        $ids[$table]['expired'] = insertCoreLog($table, ['created_at' => $now->copy()->subDays(181)]);
    }

    $ids['admin_logs']['audit'] = insertCoreLog('admin_logs', ['action' => 'update', 'method' => 'PATCH', 'created_at' => $now->copy()->subDays(30)]);
    $ids['user_logs']['audit'] = insertCoreLog('user_logs', ['module' => 'Auth', 'action' => 'login', 'method' => 'POST', 'created_at' => $now->copy()->subDays(30)]);
    $ids['api_logs']['audit'] = insertCoreLog('api_logs', ['action' => 'new', 'url' => '/api/v2/new', 'created_at' => $now->copy()->subDays(30)]);
    $ids['callback_logs']['audit'] = insertCoreLog('callback_logs', ['status' => 1, 'created_at' => $now->copy()->subDays(30)]);
    $ids['ca_logs']['audit'] = insertCoreLog('ca_logs', ['api' => 'new', 'url' => 'https://ca.test/new', 'created_at' => $now->copy()->subDays(30)]);
    $ids['error_logs']['audit'] = insertCoreLog('error_logs', ['module' => 'Payment', 'action' => 'charge', 'created_at' => $now->copy()->subDays(30)]);

    $this->artisan('logs:purge')->assertSuccessful();

    foreach ($ids as $table => $tableIds) {
        expect(DB::table($table)->where('id', $tableIds['recent'])->exists())->toBeTrue("$table recent")
            ->and(DB::table($table)->where('id', $tableIds['diagnostic'])->exists())->toBeFalse("$table diagnostic")
            ->and(DB::table($table)->where('id', $tableIds['audit'])->exists())->toBeTrue("$table audit")
            ->and(DB::table($table)->where('id', $tableIds['expired'])->exists())->toBeFalse("$table expired");
    }
});

test('未知动作在 180 天内保守保留并输出告警', function () {
    $id = insertCoreLog('user_logs', [
        'module' => 'FutureSecurity',
        'action' => 'rotateCredential',
        'method' => 'POST',
        'created_at' => now()->subDays(30),
    ]);

    $this->artisan('logs:purge')
        ->expectsOutputToContain('unclassified')
        ->assertSuccessful();

    expect(DB::table('user_logs')->where('id', $id)->exists())->toBeTrue();
});

test('用户端只读请求在 7 天后由真实清理谓词删除', function (string $method) {
    $id = insertCoreLog('user_logs', [
        'module' => 'Dashboard',
        'action' => 'overview',
        'method' => $method,
        'created_at' => now()->subDays(8),
    ]);

    $this->artisan('logs:purge')->assertSuccessful();

    expect(DB::table('user_logs')->where('id', $id)->exists())->toBeFalse();
})->with(['GET', 'HEAD', 'OPTIONS']);

test('历史 V1 API 查询路径按诊断日志在 7 天后清理', function () {
    $product = insertCoreLog('api_logs', [
        'version' => 'v1',
        'action' => null,
        'url' => '/api/V1/product',
        'created_at' => now()->subDays(8),
    ]);
    $referId = insertCoreLog('api_logs', [
        'version' => 'v1',
        'action' => null,
        'url' => '/api/V1/getOidByReferId',
        'created_at' => now()->subDays(8),
    ]);

    $this->artisan('logs:purge')->assertSuccessful();

    expect(DB::table('api_logs')->where('id', $product)->exists())->toBeFalse()
        ->and(DB::table('api_logs')->where('id', $referId)->exists())->toBeFalse();
});

test('dry run 使用相同谓词但不删除', function () {
    $id = insertCoreLog('api_logs', ['action' => 'revalidate', 'created_at' => now()->subDays(8)]);

    $this->artisan('logs:purge --dry-run')->assertSuccessful();

    expect(DB::table('api_logs')->where('id', $id)->exists())->toBeTrue();
});

test('历史日志只按 URL 路径中的完整动作清理，不误命中域名或查询参数', function (string $url, bool $retained) {
    $ids = [];
    foreach (['api_logs', 'error_logs'] as $table) {
        $ids[$table] = insertCoreLog($table, [
            'action' => null, 'url' => $url, 'created_at' => now()->subDays(10),
        ]);
    }
    $this->artisan('logs:purge')->assertSuccessful();
    foreach ($ids as $table => $id) {
        expect(DB::table($table)->where('id', $id)->exists())->toBe($retained);
    }
})->with([
    '域名' => ['https://getcert.example.com/api/v2/new', true],
    '相似动作' => ['/api/v2/get-settings', true],
    '查询参数' => ['/api/v2/new?return=/get', true],
    '中间路径' => ['/api/get/new', true],
    '完整 URL 查询动作' => ['https://manager.example.com/api/v2/get?order_id=1', false],
    '相对路径' => ['/api/v2/get', false],
    '动作后数字参数' => ['/api/order/revalidate/1', false],
]);

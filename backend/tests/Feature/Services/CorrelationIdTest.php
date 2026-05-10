<?php

use App\Http\Middleware\LogOperation;
use App\Jobs\TaskJob;
use App\Models\AdminLog;
use App\Models\CaLog;
use App\Models\ErrorLog;
use App\Services\LogBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

uses()->group('database');

beforeEach(function () {
    LogBuffer::clear();
    app()->forgetInstance('correlation_id');
});

afterEach(function () {
    app()->forgetInstance('correlation_id');
});

test('HTTP 入口生成 correlation_id 并注入到 admin_logs', function () {
    $middleware = new LogOperation;
    $request = Request::create('/api/admin/orders/create', 'POST', ['domain' => 'example.com']);
    $request->setRouteResolver(fn () => null);

    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1, 'msg' => 'ok']);
    });

    LogBuffer::flush();

    $log = AdminLog::latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->correlation_id)->not->toBeNull();
    expect(strlen((string) $log->correlation_id))->toBeGreaterThan(20);
});

test('HTTP 入口同一 correlation_id 同时写入 admin_logs 和 ca_logs', function () {
    $middleware = new LogOperation;
    $request = Request::create('/api/admin/orders/create', 'POST', ['domain' => 'example.com']);
    $request->setRouteResolver(fn () => null);

    $middleware->handle($request, function () use (&$capturedCorrelationId) {
        // 模拟 Sdk 调用：在 LogOperation 设置 correlation_id 后，下游 LogBuffer::add
        // 应能从容器读取同一 correlation_id 注入 ca_logs
        LogBuffer::add(CaLog::class, [
            'url' => 'https://upstream.example.com',
            'api' => 'new',
            'params' => ['foo' => 'bar'],
            'response' => ['code' => 1],
            'status_code' => 200,
            'status' => 1,
        ]);

        $capturedCorrelationId = app('correlation_id');

        return new JsonResponse(['code' => 1]);
    });

    LogBuffer::flush();

    $adminLog = AdminLog::latest('id')->first();
    $caLog = CaLog::latest('id')->first();

    expect($adminLog)->not->toBeNull();
    expect($caLog)->not->toBeNull();
    expect($adminLog->correlation_id)->toBe($caLog->correlation_id);
    expect($adminLog->correlation_id)->toBe($capturedCorrelationId);
});

test('HTTP → Job → ca_logs 链路 correlation_id 一致（payload 序列化跨进程）', function () {
    // 模拟 HTTP 入口绑定 correlation_id 到容器
    $httpCorrelationId = '11111111-2222-3333-4444-555555555555';
    app()->instance('correlation_id', $httpCorrelationId);

    // 在请求生命周期内 dispatch Job：构造时应从容器读取并序列化到 payload
    $job = new TaskJob(['id' => 0]); // id=0 让 TaskJob 内部 lockForUpdate 直接 return

    // 反射读 protected $correlationId 验证已捕获
    $ref = new ReflectionProperty($job, 'correlationId');
    $captured = $ref->getValue($job);
    expect($captured)->toBe($httpCorrelationId);

    // 模拟 worker 进程：清掉容器单例，handle() 应能从 payload 反序列化重新注入
    app()->forgetInstance('correlation_id');
    $job->handle();

    expect(app('correlation_id'))->toBe($httpCorrelationId);
});

test('Console / 测试场景下未绑定 correlation_id 时不报错并跳过注入', function () {
    LogBuffer::add(CaLog::class, [
        'url' => 'https://upstream.example.com',
        'api' => 'get',
        'params' => [],
        'response' => null,
        'status_code' => 200,
        'status' => 1,
    ]);

    LogBuffer::flush();

    $caLog = CaLog::latest('id')->first();
    expect($caLog)->not->toBeNull();
    expect($caLog->correlation_id)->toBeNull(); // 容器未绑定 → null（向前兼容）
});

test('独立 dispatch Job（无 HTTP 上下文）会自动生成 UUID 作为 correlation_id', function () {
    app()->forgetInstance('correlation_id');

    // 直接 new TaskJob 模拟命令行 / 任务调度入口
    $job = new TaskJob(['id' => 0]);

    $ref = new ReflectionProperty($job, 'correlationId');
    $captured = $ref->getValue($job);

    expect($captured)->not->toBeNull();
    expect($captured)->toBeString();
    expect(strlen($captured))->toBeGreaterThan(20);

    // handle() 注入容器
    $job->handle();
    expect(app('correlation_id'))->toBe($captured);
});

test('ErrorLog 通过 LogBuffer 自动注入 correlation_id', function () {
    $cid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    app()->instance('correlation_id', $cid);

    LogBuffer::add(ErrorLog::class, [
        'method' => 'POST',
        'url' => 'http://example.com/foo',
        'exception' => 'TestException',
        'message' => 'boom',
        'trace' => null,
        'status_code' => 500,
        'ip' => '127.0.0.1',
    ]);

    LogBuffer::flush();

    $errorLog = ErrorLog::latest('id')->first();
    expect($errorLog)->not->toBeNull();
    expect($errorLog->correlation_id)->toBe($cid);
});

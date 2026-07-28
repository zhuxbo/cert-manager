<?php

use App\Http\Middleware\FlushLogs;
use App\Services\LogBuffer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    LogBuffer::clear();
});

afterEach(function () {
    LogBuffer::clear();
});

test('FlushLogs handle 方法透传请求', function () {
    $middleware = new FlushLogs;
    $request = Request::create('/test', 'GET');

    $response = $middleware->handle($request, function ($req) {
        return new Response('ok');
    });

    expect($response->getContent())->toBe('ok');
});

test('FlushLogs terminate 方法刷新缓冲区', function () {
    $middleware = new FlushLogs;
    $request = Request::create('/test', 'GET');
    $response = new Response('ok');

    LogBuffer::add('App\Models\ApiLog', ['test' => 'data']);
    expect(LogBuffer::count())->toBe(1);

    $middleware->terminate($request, $response);
    expect(LogBuffer::count())->toBe(0);
});

test('FlushLogs 缓冲区为空时 terminate 不报错', function () {
    $middleware = new FlushLogs;
    $request = Request::create('/test', 'GET');
    $response = new Response('ok');

    LogBuffer::clear();
    expect(LogBuffer::count())->toBe(0);

    // 空缓冲区时 terminate 应正常执行
    $middleware->terminate($request, $response);
    expect(LogBuffer::count())->toBe(0);
});

test('FlushLogs 多条日志批量刷新', function () {
    $middleware = new FlushLogs;
    $request = Request::create('/test', 'GET');
    $response = new Response('ok');

    LogBuffer::add('App\Models\ApiLog', ['test' => 'data1']);
    LogBuffer::add('App\Models\ApiLog', ['test' => 'data2']);
    LogBuffer::add('App\Models\AdminLog', ['test' => 'data3']);
    expect(LogBuffer::count())->toBe(3);

    $middleware->terminate($request, $response);
    expect(LogBuffer::count())->toBe(0);
});

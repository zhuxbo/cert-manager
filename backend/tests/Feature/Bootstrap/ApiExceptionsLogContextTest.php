<?php

use App\Bootstrap\ApiExceptions;
use App\Http\Controllers\User\OrderController;
use App\Models\ErrorLog;
use App\Services\LogBuffer;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Request;

beforeEach(fn () => LogBuffer::clear());

afterEach(function () {
    LogBuffer::clear();
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), true);
});

test('Web 异常记录当前 controller module 和 action', function () {
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);

    $request = HttpRequest::create('/api/order/revalidate/1', 'POST');
    $route = new Route(['POST'], 'api/order/revalidate/{id}', [
        'controller' => OrderController::class.'@revalidate',
    ]);
    $request->setRouteResolver(fn () => $route);
    Request::swap($request);

    (new ApiExceptions)->logException(new RuntimeException('temporary failure'));
    LogBuffer::flush();

    expect(ErrorLog::query()->latest('id')->first())
        ->module->toBe('Order')
        ->action->toBe('revalidate');
});

test('CLI 异常记录 Console module 和命令 action', function () {
    $_SERVER['argv'] = ['artisan', 'schedule:purge'];

    (new ApiExceptions)->logException(new RuntimeException('purge failure'));
    LogBuffer::flush();

    expect(ErrorLog::query()->latest('id')->first())
        ->module->toBe('Console')
        ->action->toBe('schedule:purge');
});

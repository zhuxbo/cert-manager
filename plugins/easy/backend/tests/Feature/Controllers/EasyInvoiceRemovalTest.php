<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('EasyInvoice 后端入口不再注册', function (string $method, string $path) {
    $this->call($method, $path)->assertNotFound();
})->with([
    ['GET', '/api/easy/invoice/ping'],
    ['POST', '/api/easy/invoice/quota'],
    ['POST', '/api/easy/invoice/apply'],
]);

test('Easy 证书操作路由仍保留', function (string $path, string $action) {
    $request = Request::create($path, 'POST');
    $route = Route::getRoutes()->match($request);

    expect($route->getActionName())->toEndWith("EasyController@$action");
})->with([
    ['/api/easy/check', 'check'],
    ['/api/easy/apply', 'apply'],
    ['/api/easy/revalidate', 'revalidate'],
    ['/api/easy/sync', 'sync'],
]);

test('Easy provider 不再加载 Invoice 依赖和路由', function () {
    $source = file_get_contents(base_path('../plugins/easy/backend/EasyServiceProvider.php'));

    expect($source)
        ->not->toContain('Plugins\\Invoice')
        ->not->toContain('routes/invoice.php')
        ->not->toContain('class_exists');
});

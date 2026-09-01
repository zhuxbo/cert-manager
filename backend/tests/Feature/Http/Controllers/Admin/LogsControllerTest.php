<?php

use App\Models\Admin;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\ErrorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取管理端日志列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以获取用户端日志列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/user');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以获取API日志列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/api');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以获取回调日志列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/callback');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以获取CA日志列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/ca');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以获取错误日志列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/error');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按URL筛选日志', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin?url=/api/admin');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以按状态筛选日志', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin?status=1');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以按IP筛选日志', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin?ip=127.0.0.1');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以按日期范围筛选日志', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin?created_at[]='.now()->subDay()->format('Y-m-d\TH:i:s.v\Z').'&created_at[]='.now()->format('Y-m-d\TH:i:s.v\Z'));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('日志列表未传时间范围时可以返回 7 天前的保留记录', function () {
    AdminLog::create([
        'admin_id' => $this->admin->id,
        'module' => 'Order',
        'action' => 'update',
        'method' => 'PATCH',
        'url' => '/api/admin/order/1',
        'status' => 1,
        'created_at' => now()->subDays(30),
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin');

    $response->assertOk();
    expect(collect($response->json('data.items'))->pluck('action'))->toContain('update');
});

test('API callback error 日志支持 module action 等值筛选并返回字段', function () {
    ApiLog::create(['module' => 'Api', 'action' => 'new', 'method' => 'POST', 'url' => '/api/v2/new']);
    ApiLog::create(['module' => 'Api', 'action' => 'get', 'method' => 'POST', 'url' => '/api/v2/get']);
    CallbackLog::create(['module' => 'TopUp', 'action' => 'alipayNotify', 'method' => 'POST', 'url' => '/callback/alipay', 'params' => [], 'status' => 1]);
    ErrorLog::create(['module' => 'Order', 'action' => 'revalidate', 'method' => 'POST', 'url' => '/api/order/revalidate', 'exception' => 'RuntimeException', 'message' => 'failed']);

    foreach ([
        ['/api/admin/logs/api?module=Api&action=new', 'Api', 'new'],
        ['/api/admin/logs/callback?module=TopUp&action=alipayNotify', 'TopUp', 'alipayNotify'],
        ['/api/admin/logs/error?module=Order&action=revalidate', 'Order', 'revalidate'],
    ] as [$url, $module, $action]) {
        $response = $this->actingAsAdmin($this->admin)->getJson($url)->assertOk();
        expect($response->json('data.total'))->toBe(1)
            ->and($response->json('data.items.0.module'))->toBe($module)
            ->and($response->json('data.items.0.action'))->toBe($action);
    }
});

test('管理员可以分页获取日志', function () {
    foreach (range(1, 15) as $index) {
        AdminLog::create([
            'admin_id' => $this->admin->id,
            'module' => 'dashboard',
            'action' => "list-$index",
            'method' => 'GET',
            'url' => "/api/admin/logs/admin?page=$index",
            'params' => ['currentPage' => $index],
            'response' => ['code' => 1],
            'status_code' => 200,
            'status' => 1,
            'duration' => '0.01',
            'ip' => '127.0.0.1',
            'user_agent' => 'pest',
            'created_at' => now()->subSeconds($index),
        ]);
    }

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/logs/admin?currentPage=1&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 1);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBeGreaterThanOrEqual(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问日志', function () {
    $response = $this->getJson('/api/admin/logs/admin');

    $response->assertUnauthorized();
});

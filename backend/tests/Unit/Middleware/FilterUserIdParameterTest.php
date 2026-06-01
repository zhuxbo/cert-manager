<?php

use App\Http\Middleware\FilterUserIdParameter;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用中间件跑一遍请求，返回 next 闭包拿到的 Request（即控制器看到的请求）。
 */
function runFilterUserId(Request $request): Request
{
    $captured = null;
    (new FilterUserIdParameter)->handle($request, function (Request $req) use (&$captured) {
        $captured = $req;

        return response('ok');
    });

    return $captured;
}

test('FilterUserIdParameter 剥离 GET query 中注入的 user_id', function () {
    $request = Request::create('/x?user_id=111&keep=1', 'GET');

    $passed = runFilterUserId($request);

    expect($passed->input('user_id'))->toBeNull();
    expect($passed->query('user_id'))->toBeNull();
    expect($passed->input('keep'))->toBe('1');
});

test('FilterUserIdParameter 剥离 POST 表单中注入的 user_id', function () {
    $request = Request::create('/x', 'POST', ['user_id' => 222, 'keep' => 1]);

    $passed = runFilterUserId($request);

    expect($passed->input('user_id'))->toBeNull();
    expect($passed->post('user_id'))->toBeNull();
    expect($passed->input('keep'))->toBe(1);
});

test('FilterUserIdParameter 剥离 application/json 请求体中注入的 user_id（核心修复）', function () {
    $request = Request::create(
        '/x',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['user_id' => 333, 'foo' => 'bar', 'nested' => ['a' => 1]])
    );

    // 修复前：input(user_id) 仍读得到 333
    $passed = runFilterUserId($request);

    expect($passed->input('user_id'))->toBeNull();
    expect($passed->json('user_id'))->toBeNull();
    // 其余字段不受影响
    expect($passed->input('foo'))->toBe('bar');
    expect($passed->input('nested.a'))->toBe(1);
    expect($passed->all())->not->toHaveKey('user_id');
});

test('FilterUserIdParameter 同时清 JSON body 与 URL query 注入的 user_id（边界）', function () {
    $request = Request::create(
        '/x?user_id=111',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['user_id' => 222, 'foo' => 'bar'])
    );

    $passed = runFilterUserId($request);

    expect($passed->input('user_id'))->toBeNull();
    expect($passed->query('user_id'))->toBeNull();
    expect($passed->input('foo'))->toBe('bar');
});

test('FilterUserIdParameter 透传：无 user_id 的正常 JSON 请求不受影响', function () {
    $request = Request::create(
        '/x',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['name' => 'amy', 'email' => 'a@b.com'])
    );

    $passed = runFilterUserId($request);

    expect($passed->input('name'))->toBe('amy');
    expect($passed->input('email'))->toBe('a@b.com');
    expect($passed->all())->toBe(['name' => 'amy', 'email' => 'a@b.com']);
});

test('FilterUserIdParameter 空 JSON body 不报错', function () {
    $request = Request::create(
        '/x',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        ''
    );

    $passed = runFilterUserId($request);

    expect($passed->input('user_id'))->toBeNull();
    expect($passed->all())->toBe([]);
});

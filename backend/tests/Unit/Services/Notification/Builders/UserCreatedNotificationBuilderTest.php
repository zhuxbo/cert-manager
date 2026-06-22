<?php

use App\Models\User;
use App\Services\Notification\Builders\UserCreatedNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('build 自动注入系统设置 site_url/site_name（context 未传），密码走 transient 不入 data', function () {
    $builder = new UserCreatedNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();

    // context 仅业务字段、不含 site_url/site_name —— 模拟测试发送（variables 已去掉 site_url）
    $intent = new NotificationIntent('user_created', 'user', 1, [
        'username' => 'alice',
        'password' => 'secret123',
        'email' => 'alice@test.com',
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['username'])->toBe('alice')
        ->and($payload->data['site_url'])->toBe(get_system_setting('site', 'url', '/'))
        ->and($payload->data['site_name'])->toBe(get_system_setting('site', 'name', 'SSL证书管理系统'))
        // 初始密码绝不入持久化 data，只在 transient 渲染期注入
        ->and($payload->data)->not->toHaveKey('password')
        ->and($payload->transient['password'])->toBe('secret123');
});

test('context 显式传 site_url 时以其为准（覆盖系统设置）', function () {
    $builder = new UserCreatedNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();

    $intent = new NotificationIntent('user_created', 'user', 1, [
        'username' => 'bob',
        'password' => 'p',
        'site_url' => 'https://override.test',
        'email' => 'bob@test.com',
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['site_url'])->toBe('https://override.test');
});

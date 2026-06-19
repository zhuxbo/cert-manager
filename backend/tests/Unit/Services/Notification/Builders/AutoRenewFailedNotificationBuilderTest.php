<?php

use App\Models\User;
use App\Services\Notification\Builders\AutoRenewFailedNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('build 透传 common_name/action/reason 并自动注入系统设置 site_url（调用方/测试发送无需传）', function () {
    $builder = new AutoRenewFailedNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();
    $user->email = 'u@test.com';

    // context 不含 site_url —— 模拟命令/测试发送只传业务字段
    $intent = new NotificationIntent('auto_renew_failed', 'user', 1, [
        'common_name' => 'example.com',
        'action' => 'renew',
        'reason' => '账户余额不足，请充值后手动续期',
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['common_name'])->toBe('example.com')
        ->and($payload->data['action'])->toBe('renew')
        ->and($payload->data['reason'])->toBe('账户余额不足，请充值后手动续期')
        // site_url 由 builder 从系统设置注入，与 get_system_setting 同源
        ->and($payload->data['site_url'])->toBe(get_system_setting('site', 'url', '/'));
});

test('context 显式传 site_url 时以其为准（覆盖系统设置）', function () {
    $builder = new AutoRenewFailedNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();
    $user->email = 'u@test.com';

    $intent = new NotificationIntent('auto_renew_failed', 'user', 1, [
        'common_name' => 'a.com',
        'action' => 'reissue',
        'reason' => 'r',
        'site_url' => 'https://override.test',
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['site_url'])->toBe('https://override.test');
});

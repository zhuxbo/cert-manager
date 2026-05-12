<?php

use App\Models\NotificationTemplate;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\Guards\BuilderChannelGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('Builder 已配置时允许', function () {
    Config::set('notification.builders', [
        'cert_expire.mail' => 'App\\Services\\Notification\\Builders\\CertExpireMailNotificationBuilder',
    ]);

    $notifiable = Mockery::mock(Model::class);
    $template = Mockery::mock(NotificationTemplate::class);
    $intent = new NotificationIntent('cert_expire', 'user', 1);

    $guard = new BuilderChannelGuard;
    $result = $guard->allow($notifiable, 'mail', $template, $intent);

    expect($result)->toBeTrue();
    expect($guard->reason())->toBeNull();
});

test('Builder 未配置（空字符串明确禁用）时拒绝', function () {
    Config::set('notification.builders', [
        'cert_expire.sms' => '',
    ]);

    $notifiable = Mockery::mock(Model::class);
    $template = Mockery::mock(NotificationTemplate::class);
    $intent = new NotificationIntent('cert_expire', 'user', 1);

    $guard = new BuilderChannelGuard;
    $result = $guard->allow($notifiable, 'sms', $template, $intent);

    expect($result)->toBeFalse();
    expect($guard->reason())->toBe('通知构建器已禁用: cert_expire.sms');
});

test('未在 builders 配置中找到对应 key 时允许', function () {
    Config::set('notification.builders', []);

    $notifiable = Mockery::mock(Model::class);
    $template = Mockery::mock(NotificationTemplate::class);
    $intent = new NotificationIntent('some_code', 'user', 1);

    $guard = new BuilderChannelGuard;
    $result = $guard->allow($notifiable, 'mail', $template, $intent);

    expect($result)->toBeTrue();
    expect($guard->reason())->toBeNull();
});

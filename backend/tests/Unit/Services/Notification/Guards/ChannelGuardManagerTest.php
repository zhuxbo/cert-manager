<?php

use App\Models\NotificationTemplate;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\Guards\ChannelGuardInterface;
use App\Services\Notification\Guards\ChannelGuardManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('守卫链全部通过时所有通道被允许', function () {
    $notifiable = Mockery::mock(Model::class);
    $intent = new NotificationIntent('test_code', 'user', 1);
    $template = Mockery::mock(NotificationTemplate::class);

    $guard = Mockery::mock(ChannelGuardInterface::class);
    $guard->shouldReceive('allow')->andReturn(true);

    // 注册 Guard 类到容器
    $guardClass = get_class($guard);
    app()->instance($guardClass, $guard);

    Config::set('notification.guards', [$guardClass]);

    $manager = new ChannelGuardManager;
    $result = $manager->filter($notifiable, $intent, ['mail' => $template, 'sms' => $template]);

    expect($result['allowed'])->toBe(['mail', 'sms']);
    expect($result['rejected'])->toBeEmpty();
});

test('任一守卫拒绝则该通道被过滤', function () {
    $notifiable = Mockery::mock(Model::class);
    $intent = new NotificationIntent('test_code', 'user', 1);
    $template = Mockery::mock(NotificationTemplate::class);

    // 第一个 guard 允许所有
    $guard1 = Mockery::mock(ChannelGuardInterface::class);
    $guard1->shouldReceive('allow')->andReturn(true);

    // 第二个 guard 拒绝 mail
    $guard2 = Mockery::mock(ChannelGuardInterface::class);
    $guard2->shouldReceive('allow')->with($notifiable, 'mail', $template, $intent)->andReturn(false);
    $guard2->shouldReceive('allow')->with($notifiable, 'sms', $template, $intent)->andReturn(true);
    $guard2->shouldReceive('reason')->andReturn('邮件通道被拒绝');

    $guard1Class = get_class($guard1);
    $guard2Class = get_class($guard2);
    app()->instance($guard1Class, $guard1);
    app()->instance($guard2Class, $guard2);

    Config::set('notification.guards', [$guard1Class, $guard2Class]);

    $manager = new ChannelGuardManager;
    $result = $manager->filter($notifiable, $intent, ['mail' => $template, 'sms' => $template]);

    expect($result['allowed'])->toBe(['sms']);
    expect($result['rejected'])->toHaveKey('mail');
    expect($result['rejected']['mail'])->toBe('邮件通道被拒绝');
});

test('没有配置守卫时所有通道都被允许', function () {
    $notifiable = Mockery::mock(Model::class);
    $intent = new NotificationIntent('test_code', 'user', 1);
    $template = Mockery::mock(NotificationTemplate::class);

    Config::set('notification.guards', []);

    $manager = new ChannelGuardManager;
    $result = $manager->filter($notifiable, $intent, ['mail' => $template]);

    expect($result['allowed'])->toBe(['mail']);
    expect($result['rejected'])->toBeEmpty();
});

test('非 ChannelGuardInterface 实例被跳过', function () {
    $notifiable = Mockery::mock(Model::class);
    $intent = new NotificationIntent('test_code', 'user', 1);
    $template = Mockery::mock(NotificationTemplate::class);

    // 注册一个不实现 ChannelGuardInterface 的类
    $fakeGuard = new stdClass;
    $fakeClass = get_class($fakeGuard);
    app()->instance($fakeClass, $fakeGuard);

    Config::set('notification.guards', [$fakeClass]);

    $manager = new ChannelGuardManager;
    $result = $manager->filter($notifiable, $intent, ['mail' => $template]);

    expect($result['allowed'])->toBe(['mail']);
    expect($result['rejected'])->toBeEmpty();
});

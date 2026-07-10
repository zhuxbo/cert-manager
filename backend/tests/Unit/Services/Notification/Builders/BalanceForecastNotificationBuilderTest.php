<?php

use App\Models\User;
use App\Services\Notification\Builders\BalanceForecastNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('build 透传金额与证书明细，并从系统设置注入 site_url', function () {
    $builder = new BalanceForecastNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();
    $user->email = 'u@test.com';

    $intent = new NotificationIntent('balance_forecast', 'user', 1, [
        'available' => '50.00',
        'required' => '200.00',
        'shortfall' => '150.00',
        'certificates' => [
            ['common_name' => 'a.com', 'expires_at' => '2026-08-01', 'amount' => '100.00'],
            ['common_name' => 'b.com', 'expires_at' => '2026-08-05', 'amount' => '100.00'],
        ],
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['available'])->toBe('50.00')
        ->and($payload->data['required'])->toBe('200.00')
        ->and($payload->data['shortfall'])->toBe('150.00')
        ->and($payload->data['certificates'])->toHaveCount(2)
        ->and($payload->data['certificates'][0]['common_name'])->toBe('a.com')
        // site_url 由 builder 从系统设置注入
        ->and($payload->data['site_url'])->toBe(get_system_setting('site', 'url', '/'))
        ->and($payload->data['email'])->toBe('u@test.com');
});

test('context 显式传 site_url 时以其为准（覆盖系统设置）', function () {
    $builder = new BalanceForecastNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();
    $user->email = 'u@test.com';

    $intent = new NotificationIntent('balance_forecast', 'user', 1, [
        'available' => '0.00', 'required' => '100.00', 'shortfall' => '100.00', 'certificates' => [],
        'site_url' => 'https://override.test',
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['site_url'])->toBe('https://override.test');
});

test('email 缺省时回落 notifiable 邮箱', function () {
    $builder = new BalanceForecastNotificationBuilder;
    $user = Mockery::mock(User::class)->makePartial();
    $user->email = 'fallback@test.com';

    $intent = new NotificationIntent('balance_forecast', 'user', 1, [
        'available' => '0.00', 'required' => '100.00', 'shortfall' => '100.00', 'certificates' => [],
    ]);

    $payload = $builder->build($intent, $user);

    expect($payload->data['email'])->toBe('fallback@test.com');
});

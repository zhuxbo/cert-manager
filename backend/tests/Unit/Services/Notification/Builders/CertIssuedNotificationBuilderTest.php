<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\CertIssuedNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('接收者非 User 时抛出异常', function () {
    $builder = new CertIssuedNotificationBuilder;
    $intent = new NotificationIntent('cert_issued', 'admin', 1, ['order_id' => 1]);

    $admin = Mockery::mock(Admin::class)->makePartial();

    $builder->build($intent, $admin);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('order_id 缺失时抛出异常', function () {
    $builder = new CertIssuedNotificationBuilder;
    $intent = new NotificationIntent('cert_issued', 'user', 1, []);

    $user = Mockery::mock(User::class)->makePartial();

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '订单ID不存在');

test('order_id 为 0 时抛出异常', function () {
    $builder = new CertIssuedNotificationBuilder;
    $intent = new NotificationIntent('cert_issued', 'user', 1, ['order_id' => 0]);

    $user = Mockery::mock(User::class)->makePartial();

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '订单ID不存在');

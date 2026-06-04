<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\TaskFailedNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('接收者非 Admin 时抛出异常', function () {
    $builder = new TaskFailedNotificationBuilder;
    $intent = new NotificationIntent('task_failed', 'user', 1, ['task_id' => 1]);

    $user = Mockery::mock(User::class)->makePartial();

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '通知接收者必须为管理员');

test('task_id 缺失时抛出异常', function () {
    $builder = new TaskFailedNotificationBuilder;
    $intent = new NotificationIntent('task_failed', 'admin', 1, []);

    $admin = Mockery::mock(Admin::class)->makePartial();

    $builder->build($intent, $admin);
})->throws(RuntimeException::class, '任务ID不存在');

test('task_id 为 0 时抛出异常', function () {
    $builder = new TaskFailedNotificationBuilder;
    $intent = new NotificationIntent('task_failed', 'admin', 1, ['task_id' => 0]);

    $admin = Mockery::mock(Admin::class)->makePartial();

    $builder->build($intent, $admin);
})->throws(RuntimeException::class, '任务ID不存在');

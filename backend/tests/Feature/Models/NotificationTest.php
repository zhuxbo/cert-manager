<?php

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

test('标记通知为已读', function () {
    $notification = Notification::factory()->create();

    expect($notification->read_at)->toBeNull();

    $notification->markAsRead();
    $notification->refresh();

    expect($notification->read_at)->not->toBeNull();
    expect($notification->read_at)->toBeInstanceOf(Carbon::class);
});

test('已读通知再次标记不会更新时间', function () {
    $notification = Notification::factory()->read()->create();
    $originalReadAt = $notification->read_at;

    // 等一小段时间
    $notification->markAsRead();
    $notification->refresh();

    expect($notification->read_at->timestamp)->toBe($originalReadAt->timestamp);
});

test('标记通知为已发送', function () {
    $notification = Notification::factory()->create();

    $notification->markAsSent();
    $notification->refresh();

    expect($notification->status)->toBe(Notification::STATUS_SENT);
    expect($notification->sent_at)->not->toBeNull();
});

test('标记通知为发送失败', function () {
    $notification = Notification::factory()->create();

    $notification->markAsFailed();
    $notification->refresh();

    expect($notification->status)->toBe(Notification::STATUS_FAILED);
});

test('已读范围查询只返回已读通知', function () {
    $user = User::factory()->create();

    Notification::factory()->read()->count(2)->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
    ]);
    Notification::factory()->unread()->count(3)->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
    ]);

    $readNotifications = Notification::read()->where('notifiable_id', $user->id)->get();
    expect($readNotifications)->toHaveCount(2);
});

test('未读范围查询只返回未读通知', function () {
    $user = User::factory()->create();

    Notification::factory()->read()->count(2)->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
    ]);
    Notification::factory()->unread()->count(3)->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
    ]);

    $unreadNotifications = Notification::unread()->where('notifiable_id', $user->id)->get();
    expect($unreadNotifications)->toHaveCount(3);
});

test('通知关联通知模板', function () {
    $template = NotificationTemplate::factory()->create();
    $notification = Notification::factory()->create([
        'template_id' => $template->id,
    ]);

    expect($notification->template)->toBeInstanceOf(NotificationTemplate::class);
    expect($notification->template->id)->toBe($template->id);
});

test('通知多态关联用户', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
    ]);

    expect($notification->notifiable)->toBeInstanceOf(User::class);
    expect($notification->notifiable->id)->toBe($user->id);
});

test('data 字段为 JSON cast', function () {
    $notification = Notification::factory()->create([
        'data' => ['title' => '测试', 'content' => '内容'],
    ]);
    $notification->refresh();

    expect($notification->data)->toBeArray();
    expect($notification->data['title'])->toBe('测试');
});

test('状态常量定义正确', function () {
    expect(Notification::STATUS_PENDING)->toBe('pending');
    expect(Notification::STATUS_SENDING)->toBe('sending');
    expect(Notification::STATUS_SENT)->toBe('sent');
    expect(Notification::STATUS_FAILED)->toBe('failed');
});

<?php

use App\Jobs\NotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\Channels\MailChannel;
use App\Services\Notification\NotificationRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
});

afterEach(function () {
    Mockery::close();
});

/**
 * 创建用户（NotificationJob 测试用）
 */
function createJobUser(array $overrides = []): User
{
    $email = $overrides['email'] ?? uniqid().'@example.com';
    unset($overrides['email']);

    return User::firstOrCreate(
        ['email' => $email],
        array_merge([
            'username' => $overrides['username'] ?? 'user_'.uniqid(),
            'password' => 'secret',
            'join_at' => now(),
        ], $overrides)
    );
}

/**
 * 创建通知模板
 */
function createJobTemplate(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::create(array_merge([
        'code' => 'test_'.uniqid(),
        'name' => 'Test Template',
        'content' => 'Hello {{ $username }}',
        'variables' => ['username'],
        'status' => 1,
    ], $overrides));
}

test('handles notification successfully', function () {
    $user = createJobUser(['mobile' => '138'.uniqid()]);
    $template = createJobTemplate();

    // Mock MailChannel to return success
    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')
            ->once()
            ->andReturn(['code' => 1, 'msg' => '发送成功']);
    });

    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'mail',
        ['username' => $user->username],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 验证通知已创建并标记为已发送
    $notification = Notification::where('notifiable_id', $user->id)->first();
    expect($notification)->not->toBeNull();
    expect($notification->status)->toBe(Notification::STATUS_SENT);
    expect($notification->sent_at)->not->toBeNull();

    // 验证发送结果
    expect($notification->data)->toHaveKey('result');
    expect($notification->data['result']['status'])->toBe(Notification::STATUS_SENT);
});

test('skips when template not found', function () {
    $user = createJobUser();

    $job = new NotificationJob(
        'user',
        $user->id,
        99999, // 不存在的模板ID
        'mail',
        [],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 验证没有为这个模板创建通知
    $this->assertDatabaseMissing('notifications', [
        'template_id' => 99999,
    ]);
});

test('skips when template disabled', function () {
    $user = createJobUser();
    $template = createJobTemplate(['status' => 0]); // 已禁用

    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'mail',
        ['username' => $user->username],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 验证没有为这个禁用的模板创建通知
    $this->assertDatabaseMissing('notifications', [
        'template_id' => $template->id,
    ]);
});

test('skips when notifiable not found', function () {
    $template = createJobTemplate();

    $job = new NotificationJob(
        'user',
        99999, // 不存在的用户ID
        $template->id,
        'mail',
        [],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 验证没有为这个不存在的用户创建通知（通过模板ID和不存在的用户ID组合判断）
    $notification = Notification::where('template_id', $template->id)
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', 99999)
        ->first();
    expect($notification)->toBeNull();
});

test('marks as failed when all channels fail', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // Mock MailChannel to return failure
    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')
            ->once()
            ->andReturn(['code' => 0, 'msg' => '发送失败']);
    });

    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'mail',
        ['username' => $user->username],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 验证通知标记为失败
    $notification = Notification::where('notifiable_id', $user->id)->first();
    expect($notification)->not->toBeNull();
    expect($notification->status)->toBe(Notification::STATUS_FAILED);
    expect($notification->sent_at)->toBeNull();

    // 验证发送结果
    expect($notification->data['result']['status'])->toBe(Notification::STATUS_FAILED);
    expect($notification->data['result']['message'])->toBe('发送失败');
});

test('handles channel exception gracefully', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // Mock MailChannel to throw exception
    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')
            ->once()
            ->andThrow(new Exception('Channel error'));
    });

    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'mail',
        ['username' => $user->username],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 验证通知标记为失败，并记录错误
    $notification = Notification::where('notifiable_id', $user->id)->first();
    expect($notification)->not->toBeNull();
    expect($notification->status)->toBe(Notification::STATUS_FAILED);
    expect($notification->data['result']['status'])->toBe(Notification::STATUS_FAILED);
    expect($notification->data['result']['message'])->toBe('发送失败，请稍后重试');
});

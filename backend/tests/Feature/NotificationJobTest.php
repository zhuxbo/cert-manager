<?php

use App\Jobs\NotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\Builders\UserCreatedNotificationBuilder;
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

test('user_created delivers password to mail render but never persists it to notifications.data', function () {
    $user = createJobUser();

    // user_created 模板正文含 {{ $password }}，与 seeder 一致
    $template = NotificationTemplate::firstOrCreate(
        ['code' => 'user_created'],
        [
            'name' => '用户创建通知',
            'content' => '您好，我们为您创建了账号，用户名 {{ $username }}，密码 {{ $password }}，登录地址 {{ $site_url }}',
            'variables' => ['username', 'password', 'site_url'],
            'status' => 1,
        ]
    );

    // 捕获通道在发送时实际看到的 data（应含密码，证明邮件能正常渲染凭据）
    $renderedData = null;
    $this->mock(MailChannel::class, function ($mock) use (&$renderedData) {
        $mock->shouldReceive('send')
            ->once()
            ->andReturnUsing(function ($notification) use (&$renderedData) {
                $renderedData = $notification->data;

                return ['code' => 1, 'msg' => '发送成功'];
            });
    });

    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'mail',
        [
            'username' => $user->username,
            'password' => 'PlainSecret123',
            'site_name' => 'S',
            'site_url' => 'https://example.com',
            'email' => $user->email,
        ],
        UserCreatedNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 渲染期：通道看得到明文密码（邮件正文能交付初始凭据）
    expect($renderedData['password'] ?? null)->toBe('PlainSecret123');

    // 持久化：notifications.data 不含明文密码，但保留非敏感字段
    $notification = Notification::where('notifiable_id', $user->id)
        ->where('template_id', $template->id)
        ->first();
    expect($notification)->not->toBeNull();
    expect($notification->status)->toBe(Notification::STATUS_SENT);
    expect($notification->data)->not->toHaveKey('password');
    expect($notification->data['username'])->toBe($user->username);

    // 入库 JSON 整体不出现明文密码（兜底防止藏在 _meta/result 等子结构）
    expect(json_encode($notification->data))->not->toContain('PlainSecret123');
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

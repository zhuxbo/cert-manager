<?php

use App\Jobs\NotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\Builders\UserCreatedNotificationBuilder;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\Channels\ChannelInterface;
use App\Services\Notification\Channels\MailChannel;
use App\Services\Notification\NotificationRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

test('NotificationJob 实现 ShouldBeEncrypted，序列化入队的 payload 不暴露明文', function () {
    expect(new NotificationJob('user', 1, 1, 'mail', [], DefaultNotificationBuilder::class))
        ->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('携密 user_created Job 推入 database 队列后 jobs 表 payload 不含明文密码', function () {
    $user = createJobUser();

    $template = NotificationTemplate::firstOrCreate(
        ['code' => 'user_created'],
        [
            'name' => '用户创建通知',
            'content' => '密码 {{ $password }}',
            'variables' => ['username', 'password'],
            'status' => 1,
        ]
    );

    // 临时切到 database 队列连接，强制真实序列化入 jobs 表（测试默认 sync 不入队）
    config(['queue.default' => 'database']);

    NotificationJob::dispatch(
        'user',
        $user->id,
        $template->id,
        'mail',
        [
            'username' => $user->username,
            'password' => 'PlainSecret123',
            'email' => $user->email,
        ],
        UserCreatedNotificationBuilder::class
    );

    $payload = DB::table('jobs')->value('payload');
    expect($payload)->not->toBeNull();

    // RED（未加密时）：payload 是明文 JSON，明文密码可见
    // GREEN（ShouldBeEncrypted）：Laravel 用 APP_KEY 加密 command，明文不出现
    expect($payload)->not->toContain('PlainSecret123');
});

test('非 mail 通道处理后临时 ZIP（cleanup_paths）被清理，不泄漏临时文件', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // 模拟 CertIssuedNotificationBuilder 在 build 时为某通道生成的临时目录
    $tempDir = storage_path('temp-certs/test_'.uniqid());
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/cert.zip', 'fake-zip-with-private-key');
    expect(is_dir($tempDir))->toBeTrue();

    // 注册一个「不消费附件、不清理」的非 mail 假通道（模拟插件注入的 IM 通道）
    app(ChannelManager::class)->register('feishu', new class implements ChannelInterface
    {
        public function send(Notification $notification): array
        {
            return ['code' => 1];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function shouldSend(Model $notifiable, string $code): bool
        {
            return true;
        }
    });

    // 通过 DefaultNotificationBuilder 直通 context，把 cleanup_paths 注入 payload._meta
    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'feishu',
        [
            'username' => $user->username,
            '_meta' => ['cleanup_paths' => [$tempDir]],
        ],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // RED（修复前）：非 mail 通道不清理 cleanup_paths，临时目录残留
    // GREEN（修复后）：NotificationJob::handle 兜底清理，目录被删除
    expect(is_dir($tempDir))->toBeFalse();
});

test('发送失败时 cleanup_paths 仍被清理', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $tempDir = storage_path('temp-certs/test_'.uniqid());
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/cert.zip', 'fake');

    app(ChannelManager::class)->register('feishu', new class implements ChannelInterface
    {
        public function send(Notification $notification): array
        {
            // 通道发送失败
            return ['code' => 0, 'msg' => 'IM 推送失败'];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function shouldSend(Model $notifiable, string $code): bool
        {
            return true;
        }
    });

    $job = new NotificationJob(
        'user',
        $user->id,
        $template->id,
        'feishu',
        [
            'username' => $user->username,
            '_meta' => ['cleanup_paths' => [$tempDir]],
        ],
        DefaultNotificationBuilder::class
    );

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    expect(is_dir($tempDir))->toBeFalse();
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

// ==========================================
// M4：失败重试（tries=5 / maxExceptions=1 / retryable 分档 / 行复用 / release / failed）
// ==========================================

test('M4：NotificationJob tries=5 且 maxExceptions=1（幂等 ShouldQueue 约定）', function () {
    $job = new NotificationJob('user', 1, 1, 'mail', [], DefaultNotificationBuilder::class);

    expect($job->tries)->toBe(5)
        ->and($job->maxExceptions)->toBe(1);
});

test('M4：瞬态失败且未达上限 → release 错峰重试（不标 failed）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')->once()
            ->andReturn(['code' => 0, 'msg' => 'SMTP 抖动', 'retryable' => true]);
    });

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], DefaultNotificationBuilder::class);
    $job->withFakeQueueInteractions(); // attempts=1 < tries=5

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertReleased();
    $job->assertNotFailed();
    expect($job->job->releaseDelay)->toBe(60); // backoff[0]

    $notification = Notification::where('notifiable_id', $user->id)->first();
    expect($notification->status)->toBe(Notification::STATUS_FAILED); // 行 UI 可见
});

test('M4：永久失败 → 不 release、行 FAILED、不进 failed_jobs（防新装机风暴）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')->once()
            ->andReturn(['code' => 0, 'msg' => '邮件服务未配置', 'retryable' => false]);
    });

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], DefaultNotificationBuilder::class);
    $job->withFakeQueueInteractions();

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertNotReleased();
    $job->assertNotFailed();

    $notification = Notification::where('notifiable_id', $user->id)->first();
    expect($notification->status)->toBe(Notification::STATUS_FAILED)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('M4：瞬态重试多轮复用同一 notifications 行（3 轮仅 1 行）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')
            ->andReturn(['code' => 0, 'msg' => 'SMTP 抖动', 'retryable' => true]);
    });

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], DefaultNotificationBuilder::class);
    $job->withFakeQueueInteractions();

    // 3 轮：attempts 1→2→3，均瞬态失败；attempts>1 时复用行
    foreach ([1, 2, 3] as $attempt) {
        $job->job->attempts = $attempt;
        $job->handle(app(NotificationRepository::class), app(ChannelManager::class));
    }

    expect(
        Notification::where('notifiable_id', $user->id)
            ->where('template_id', $template->id)
            ->count()
    )->toBe(1);
});

test('M4：瞬态失败 release 前清理本轮 cleanup_paths（含私钥 ZIP 不逐轮泄漏）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $tempDir = storage_path('temp-certs/test_'.uniqid());
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/cert.zip', 'private-key-zip');

    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')->once()
            ->andReturn(['code' => 0, 'msg' => 'SMTP 抖动', 'retryable' => true]);
    });

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', [
        'username' => $user->username,
        '_meta' => ['cleanup_paths' => [$tempDir]],
    ], DefaultNotificationBuilder::class);
    $job->withFakeQueueInteractions();

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertReleased();
    // 瞬态 release 前已清本轮 build 产物（下轮 handle 重跑 build 重生成）
    expect(is_dir($tempDir))->toBeFalse();
});

test('M4：failed() 定位行标 FAILED 终态并 Log::error 兜底', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // 模拟 handle 已落 sending 行 + 持久化 cleanup_paths
    $tempDir = storage_path('temp-certs/test_'.uniqid());
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/cert.zip', 'zip');

    $notification = $user->notifications()->create([
        'template_id' => $template->id,
        'data' => ['_meta' => ['cleanup_paths' => [$tempDir]]],
        'status' => Notification::STATUS_SENDING,
    ]);

    Log::spy();

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], DefaultNotificationBuilder::class);
    $job->failed(new RuntimeException('末轮瞬态仍失败'));

    expect($notification->fresh()->status)->toBe(Notification::STATUS_FAILED)
        ->and(is_dir($tempDir))->toBeFalse(); // failed() 幂等兜底清理

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg) => str_contains((string) $msg, '通知发送最终失败'))
        ->once();
});

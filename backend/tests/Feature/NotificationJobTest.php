<?php

use App\Jobs\NotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\Builders\DefaultNotificationBuilder;
use App\Services\Notification\Builders\NotificationBuilderInterface;
use App\Services\Notification\Builders\UserCreatedNotificationBuilder;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\Channels\ChannelInterface;
use App\Services\Notification\Channels\MailChannel;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Notification\Exceptions\TransientBuildException;
use App\Services\Notification\NotificationRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class)->group('database');

/**
 * 测试专用 Builder：build 永久失败（数据/校验错，非瞬态）。
 */
class BuildFailsPermanentBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        throw new RuntimeException('permanent build failure');
    }
}

/**
 * 测试专用 Builder：build 瞬态失败（IO/磁盘满，可自愈重试）。
 */
class BuildFailsTransientBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        throw new TransientBuildException('transient build failure');
    }
}

/**
 * 测试专用 Builder：把 context 敏感值回显进异常消息，验证 getMessage() 绝不落库（notify I-1）。
 */
class BuildFailsWithSecretInMessageBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        throw new RuntimeException('build fail: '.($intent->context['password'] ?? ''));
    }
}

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

test('M4：瞬态失败第 2 轮起退避进 300s 档（retryDelay backoff[1]）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')->once()
            ->andReturn(['code' => 0, 'msg' => 'SMTP 抖动', 'retryable' => true]);
    });

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], DefaultNotificationBuilder::class);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 2; // 第 2 次尝试 → backoff[max(0,2-1)]=backoff[1]=300

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertReleased();
    expect($job->job->releaseDelay)->toBe(300); // backoff[1]（60→300 档切换）
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

test('M4：末轮瞬态仍失败（attempts=5=tries）→ 不再 release、调 fail() 交终态兜底', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $this->mock(MailChannel::class, function ($mock) {
        $mock->shouldReceive('send')->once()
            ->andReturn(['code' => 0, 'msg' => 'SMTP 抖动', 'retryable' => true]);
    });

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], DefaultNotificationBuilder::class);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 5; // 末轮（=tries），attempts<tries 为假 → 不 release，走 fail()

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertNotReleased(); // 末轮不再 release
    $job->assertFailed();      // 显式 fail() → 触发 failed() 兜底（真实 worker 语义）
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

// ==========================================
// 包V：build 阶段失败可见性（永久/瞬态分档 + 落 FAILED 记录不携密 + 白名单摘录 + 降级）
// ==========================================

test('包V：build 永久失败 → 落 1 行 FAILED、不 release、无 pending 残留、failed_jobs=0', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], BuildFailsPermanentBuilder::class);
    $job->withFakeQueueInteractions();

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertNotReleased();
    $job->assertNotFailed();

    $rows = Notification::where('notifiable_id', $user->id)->where('template_id', $template->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe(Notification::STATUS_FAILED)
        ->and($rows->first()->data['result']['message'])->toBe('通知内容生成失败')
        ->and($rows->first()->data['_meta']['build_failed'])->toBeTrue();

    // 单写 FAILED，无 pending 中间态残留（消除 stuck-pending 窗口）
    expect(Notification::where('status', Notification::STATUS_PENDING)->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('包V：build 瞬态失败未达上限 → release(60)、不落记录', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], BuildFailsTransientBuilder::class);
    $job->withFakeQueueInteractions(); // attempts=1 < tries=5

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertReleased();
    $job->assertNotFailed();
    expect($job->job->releaseDelay)->toBe(60); // backoff[0]

    // 瞬态未末轮不落记录（前几轮不留行，避免噪声）
    expect(Notification::where('notifiable_id', $user->id)->count())->toBe(0);
});

test('包V：build 瞬态失败第 2 轮 → release(300)（retryDelay backoff 对齐 send 阶段）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], BuildFailsTransientBuilder::class);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 2; // backoff[max(0,2-1)]=backoff[1]=300

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertReleased();
    expect($job->job->releaseDelay)->toBe(300); // backoff[1]（60→300 档切换）
    expect(Notification::where('notifiable_id', $user->id)->count())->toBe(0);
});

test('包V：build 瞬态失败末轮（attempts=5=tries）→ 落 1 行 FAILED + fail()', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], BuildFailsTransientBuilder::class);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 5; // 末轮：attempts<tries 为假 → 不 release，落记录 + fail()

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $job->assertNotReleased();
    $job->assertFailed();

    $rows = Notification::where('notifiable_id', $user->id)->where('template_id', $template->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe(Notification::STATUS_FAILED)
        ->and($rows->first()->data['result']['message'])->toBe('通知内容生成失败（瞬态重试已耗尽）');
});

test('包V：build 失败落 failed 记录不含 context 明文（异常 getMessage 绝不落库）', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // 测试 Builder 把 context['password'] 嵌进抛出的异常消息——固定常量的测法（无敏感值入 message）
    // 断言恒过、抓不到 getMessage() 落库回归，故必须敏感值入 message 才真验（notify I-1）
    $job = new NotificationJob('user', $user->id, $template->id, 'mail', [
        'username' => $user->username,
        'password' => 'PlainSecret123',
    ], BuildFailsWithSecretInMessageBuilder::class);
    $job->withFakeQueueInteractions();

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $notification = Notification::where('notifiable_id', $user->id)->where('template_id', $template->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->status)->toBe(Notification::STATUS_FAILED)
        // 固定常量文案，绝不含异常 getMessage 里回显的明文密码
        ->and($notification->data['result']['message'])->toBe('通知内容生成失败');
    // 入库 JSON 整体不出现明文（兜底防藏在 _meta/result 等子结构）
    expect(json_encode($notification->data))->not->toContain('PlainSecret123');
});

test('包V：build 失败落记录直接新建、不误复用同接收者近1h 其他 failed 行', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // 预置一行「他证书」的 failed 记录（同 user + 同模板 + 近 1h，findReusableRow 会匹配）
    $existing = $user->notifications()->create([
        'template_id' => $template->id,
        'data' => ['_meta' => ['order_id' => 999], 'result' => ['status' => Notification::STATUS_FAILED, 'message' => '他证书旧失败']],
        'status' => Notification::STATUS_FAILED,
    ]);

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username, 'order_id' => 123], BuildFailsPermanentBuilder::class);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 2; // attempts>1：若误走 findReusableRow 会复用 $existing

    $job->handle(app(NotificationRepository::class), app(ChannelManager::class));

    // 直接新建（不走 findReusableRow）→ 2 行；原行 data 未被覆盖
    expect(Notification::where('notifiable_id', $user->id)->where('template_id', $template->id)->count())->toBe(2)
        ->and($existing->fresh()->data['result']['message'])->toBe('他证书旧失败');
});

test('包V：persistBuildFailure DB 写异常 → 降级 Log::error、job 不上抛', function () {
    $user = createJobUser();
    $template = createJobTemplate();

    // mock repository 使 createNotification 抛异常（handle 方法参数，注入缝干净）
    $repo = Mockery::mock(NotificationRepository::class);
    $repo->shouldReceive('createNotification')->andThrow(new RuntimeException('DB write failed'));

    Log::spy();

    $job = new NotificationJob('user', $user->id, $template->id, 'mail', ['username' => $user->username], BuildFailsPermanentBuilder::class);
    $job->withFakeQueueInteractions();

    // 不上抛（persistBuildFailure DB 写 try/catch 降级双日志）
    $job->handle($repo, app(ChannelManager::class));

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg) => str_contains((string) $msg, '落 failed 记录失败'))
        ->once();
});

test('包V：_meta.order_id 白名单标量摘录（三形态）+ data 不含 context 其他键', function () {
    $template = createJobTemplate();

    // 形态 A：order_id 为标量 → 摘录进 _meta；context 其他键（username/password）绝不入库
    $userA = createJobUser();
    $jobA = new NotificationJob('user', $userA->id, $template->id, 'mail', ['username' => $userA->username, 'order_id' => 123, 'password' => 'PlainSecret123'], BuildFailsPermanentBuilder::class);
    $jobA->withFakeQueueInteractions();
    $jobA->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $rowA = Notification::where('notifiable_id', $userA->id)->first();
    expect($rowA->data['_meta']['order_id'])->toBe(123)
        ->and($rowA->data)->not->toHaveKey('username')
        ->and($rowA->data)->not->toHaveKey('password');
    expect(json_encode($rowA->data))->not->toContain('PlainSecret123');

    // 形态 B：无 order_id → _meta 无该键
    $userB = createJobUser();
    $jobB = new NotificationJob('user', $userB->id, $template->id, 'mail', ['username' => $userB->username], BuildFailsPermanentBuilder::class);
    $jobB->withFakeQueueInteractions();
    $jobB->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $rowB = Notification::where('notifiable_id', $userB->id)->first();
    expect($rowB->data['_meta'])->not->toHaveKey('order_id');

    // 形态 C：order_id 非标量（数组）→ is_scalar 守卫拦截，_meta 无该键
    $userC = createJobUser();
    $jobC = new NotificationJob('user', $userC->id, $template->id, 'mail', ['username' => $userC->username, 'order_id' => ['nested' => 1]], BuildFailsPermanentBuilder::class);
    $jobC->withFakeQueueInteractions();
    $jobC->handle(app(NotificationRepository::class), app(ChannelManager::class));

    $rowC = Notification::where('notifiable_id', $userC->id)->first();
    expect($rowC->data['_meta'])->not->toHaveKey('order_id');
});

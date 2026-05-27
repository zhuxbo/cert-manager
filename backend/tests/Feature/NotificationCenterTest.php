<?php

use App\Jobs\NotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\Channels\MailChannel;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
});

function createNotifUser(array $overrides = []): User
{
    $notificationSettings = $overrides['notification_settings'] ?? null;
    unset($overrides['notification_settings']);

    $email = array_key_exists('email', $overrides)
        ? $overrides['email']
        : uniqid().'@example.com';
    unset($overrides['email']);

    $user = User::firstOrCreate(
        ['email' => $email],
        array_merge([
            'username' => $overrides['username'] ?? 'user_'.uniqid(),
            'password' => 'secret',
            'join_at' => now(),
        ], $overrides)
    );

    if ($notificationSettings !== null) {
        $user->notification_settings = $notificationSettings;
        $user->save();
    }

    return $user;
}

function seedNotifTemplate(string $code, string $name, string $content): void
{
    NotificationTemplate::updateOrCreate(
        ['code' => $code],
        [
            'name' => $name,
            'content' => $content,
            'variables' => ['username'],
            'status' => 1,
        ]
    );
}

function getPrivateProperty(object $object, string $property): mixed
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);

    return $prop->getValue($object);
}

function mockNotifMailChannel(): void
{
    app()->bind(MailChannel::class, fn () => new class extends MailChannel
    {
        public function send(Notification $notification): array
        {
            return ['code' => 1, 'msg' => '发送成功'];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function shouldSend(Model $notifiable, string $code): bool
        {
            if (empty($notifiable->email)) {
                return false;
            }

            if (method_exists($notifiable, 'allowsNotification')) {
                return (bool) $notifiable->allowsNotification($code);
            }

            return true;
        }
    });
    app()->forgetInstance(ChannelManager::class);
}

test('模板存在 + 通道可用 + 偏好允许 → 派 Job', function () {
    Queue::fake();
    $user = createNotifUser();
    seedNotifTemplate('test_mail', '测试模板', 'Hi {{ $username }}');

    mockNotifMailChannel();

    app(NotificationCenter::class)->dispatch(new NotificationIntent('test_mail', 'user', $user->id));

    Queue::assertPushed(NotificationJob::class, function ($job) use ($user) {
        return getPrivateProperty($job, 'notifiableId') === $user->id
            && getPrivateProperty($job, 'channel') === 'mail';
    });
});

test('收件邮箱缺失 → 不派 Job', function () {
    Queue::fake();
    $user = createNotifUser(['email' => null]);
    seedNotifTemplate('test_mail', '测试模板', 'Hi {{ $username }}');

    mockNotifMailChannel();

    app(NotificationCenter::class)->dispatch(new NotificationIntent('test_mail', 'user', $user->id));

    Queue::assertNotPushed(NotificationJob::class);
});

test('用户偏好关闭某事件 → 不派 Job', function () {
    Queue::fake();
    config(['notification.user_default_preferences' => ['test_mail' => true]]);
    $user = createNotifUser(['notification_settings' => ['test_mail' => false]]);
    seedNotifTemplate('test_mail', '测试模板', 'Hi {{ $username }}');

    mockNotifMailChannel();

    app(NotificationCenter::class)->dispatch(new NotificationIntent('test_mail', 'user', $user->id));

    Queue::assertNotPushed(NotificationJob::class);
});

test('模板不存在 → 不派 Job', function () {
    Queue::fake();
    $user = createNotifUser();

    mockNotifMailChannel();

    app(NotificationCenter::class)->dispatch(new NotificationIntent('nonexistent_code', 'user', $user->id));

    Queue::assertNotPushed(NotificationJob::class);
});

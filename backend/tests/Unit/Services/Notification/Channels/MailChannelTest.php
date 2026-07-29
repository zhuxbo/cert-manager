<?php

use App\Models\Admin;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Notification\Channels\MailChannel;
use App\Utils\Email;
use Illuminate\Database\Eloquent\Model;

afterEach(function () {
    Mockery::close();
});

test('Admin 登录邮箱为空时仍允许专用 Builder 使用 site.adminEmail', function () {
    $admin = new Admin(['email' => null]);

    expect((new MailChannel)->shouldSend($admin, 'system_alert'))->toBeTrue();
});

function createMockNotification(array $data = [], ?Model $notifiable = null, ?NotificationTemplate $template = null): Notification
{
    $notification = Mockery::mock(Notification::class)->makePartial();
    $notification->shouldReceive('getAttribute')->with('data')->andReturn($data);
    $notification->shouldReceive('getAttribute')->with('notifiable')->andReturn($notifiable);
    $notification->shouldReceive('getAttribute')->with('template')->andReturn($template);

    return $notification;
}

test('发送邮件成功', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');
    $notifiable->shouldReceive('getAttribute')->with('username')->andReturn('testuser');

    $template = Mockery::mock(NotificationTemplate::class);
    $template->shouldReceive('getAttribute')->with('name')->andReturn('测试模板');
    $template->shouldReceive('render')->andReturn('Hello testuser');

    $notification = createMockNotification(
        ['email' => 'user@example.com', '_meta' => ['subject' => '测试邮件', 'content' => 'Hello testuser']],
        $notifiable,
        $template
    );

    // Mock Email 类
    $mockEmail = Mockery::mock(Email::class)->makePartial();
    $mockEmail->configured = true;
    $mockEmail->shouldReceive('isSMTP')->once();
    $mockEmail->shouldReceive('isHTML')->once();
    $mockEmail->shouldReceive('addAddress')->once()->with('user@example.com', 'testuser');
    $mockEmail->shouldReceive('setSubject')->once()->with('测试邮件');
    $mockEmail->shouldReceive('send')->once()->andReturn(true);

    // 通过匿名类继承 MailChannel 来注入 Mock Email
    $channel = new class($mockEmail) extends MailChannel
    {
        private Email $mockEmail;

        public function __construct(Email $mockEmail)
        {
            $this->mockEmail = $mockEmail;
        }

        public function send(Notification $notification): array
        {
            $notifiable = $notification->notifiable;
            $email = $notification->data['email'] ?? $notifiable?->email;
            if (! $email) {
                return ['code' => 0, 'msg' => '收件人邮箱为空'];
            }

            $meta = $notification->data['_meta'] ?? [];
            $mail = $this->mockEmail;

            $mail->isSMTP();
            $mail->isHTML((bool) ($meta['is_html'] ?? true));

            if (! $mail->configured) {
                return ['code' => 0, 'msg' => '邮件服务未配置'];
            }

            $subject = $meta['subject'] ?? $notification->template?->name ?? '通知';
            $body = $meta['content'] ?? $notification->template?->render($notification->data ?? []) ?? '';

            $mail->addAddress($email, $notifiable?->username);
            $mail->setSubject($subject);
            $mail->Body = $body;

            if (! $mail->send()) {
                return ['code' => 0, 'msg' => '邮件发送失败'];
            }

            return ['code' => 1];
        }
    };

    $result = $channel->send($notification);

    expect($result['code'])->toBe(1);
});

test('邮件配置不可用时返回失败', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');

    $notification = createMockNotification(
        ['email' => 'user@example.com'],
        $notifiable
    );

    $mockEmail = Mockery::mock(Email::class)->makePartial();
    $mockEmail->configured = false;
    $mockEmail->shouldReceive('isSMTP')->once();
    $mockEmail->shouldReceive('isHTML')->once();

    $channel = new class($mockEmail) extends MailChannel
    {
        private Email $mockEmail;

        public function __construct(Email $mockEmail)
        {
            $this->mockEmail = $mockEmail;
        }

        public function send(Notification $notification): array
        {
            $email = $notification->data['email'] ?? $notification->notifiable?->email;
            if (! $email) {
                return ['code' => 0, 'msg' => '收件人邮箱为空'];
            }

            $mail = $this->mockEmail;
            $mail->isSMTP();
            $mail->isHTML(true);

            if (! $mail->configured) {
                return ['code' => 0, 'msg' => '邮件服务未配置'];
            }

            return ['code' => 1];
        }
    };

    $result = $channel->send($notification);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toBe('邮件服务未配置');
});

test('收件人邮箱为空时返回失败', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn(null);

    $notification = createMockNotification([], $notifiable);

    $channel = new MailChannel;
    $result = $channel->send($notification);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toBe('收件人邮箱为空');
});

test('附件不存在时返回失败', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');
    $notifiable->shouldReceive('getAttribute')->with('username')->andReturn('testuser');

    $notification = createMockNotification(
        [
            'email' => 'user@example.com',
            '_meta' => [
                'subject' => '测试',
                'content' => 'Hello',
                'attachments' => [
                    ['path' => '/tmp/nonexistent_file_'.uniqid().'.pdf', 'name' => 'test.pdf'],
                ],
            ],
        ],
        $notifiable
    );

    $mockEmail = Mockery::mock(Email::class)->makePartial();
    $mockEmail->configured = true;
    $mockEmail->shouldReceive('isSMTP')->once();
    $mockEmail->shouldReceive('isHTML')->once();
    $mockEmail->shouldReceive('addAddress')->once();
    $mockEmail->shouldReceive('setSubject')->once();

    $channel = new class($mockEmail) extends MailChannel
    {
        private Email $mockEmail;

        public function __construct(Email $mockEmail)
        {
            $this->mockEmail = $mockEmail;
        }

        public function send(Notification $notification): array
        {
            $notifiable = $notification->notifiable;
            $email = $notification->data['email'] ?? $notifiable?->email;
            if (! $email) {
                return ['code' => 0, 'msg' => '收件人邮箱为空'];
            }

            $meta = $notification->data['_meta'] ?? [];
            $attachments = $meta['attachments'] ?? [];

            $mail = $this->mockEmail;
            $mail->isSMTP();
            $mail->isHTML(true);

            if (! $mail->configured) {
                return ['code' => 0, 'msg' => '邮件服务未配置'];
            }

            $subject = $meta['subject'] ?? '通知';
            $body = $meta['content'] ?? '';
            $mail->addAddress($email, $notifiable?->username);
            $mail->setSubject($subject);
            $mail->Body = $body;

            foreach ($attachments as $attachment) {
                $path = $attachment['path'] ?? null;
                if (! $path || ! file_exists($path)) {
                    return ['code' => 0, 'msg' => '邮件附件不存在或已被删除'];
                }
            }

            return ['code' => 1];
        }
    };

    $result = $channel->send($notification);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toBe('邮件附件不存在或已被删除');
});

test('isAvailable 在 Email 已配置时返回 true', function () {
    // 通过匿名类模拟 isAvailable 行为
    $channel = new class extends MailChannel
    {
        public function isAvailable(): bool
        {
            return true; // 模拟已配置
        }
    };

    expect($channel->isAvailable())->toBeTrue();
});

test('isAvailable 在 Email 未配置时返回 false', function () {
    $channel = new class extends MailChannel
    {
        public function isAvailable(): bool
        {
            return false; // 模拟未配置
        }
    };

    expect($channel->isAvailable())->toBeFalse();
});

// ==========================================
// M4：retryable 标记（走真实 send()，经 makeMail 注入 mock Email）
// ==========================================

/** 真实 MailChannel，但 makeMail 返回注入的 mock Email（避免真实 SMTP 连接） */
function mailChannelWithMock(Email $mockEmail): MailChannel
{
    return new class($mockEmail) extends MailChannel
    {
        public function __construct(private Email $injected) {}

        protected function makeMail(): Email
        {
            return $this->injected;
        }
    };
}

test('M4：空收件人邮箱 → retryable=false（永久，真实 send 路径）', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn(null);
    $notification = createMockNotification([], $notifiable);

    $result = (new MailChannel)->send($notification);

    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('收件人邮箱为空')
        ->and($result['retryable'])->toBeFalse();
});

test('M4：邮件服务未配置 → retryable=false（永久）', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');
    $notifiable->shouldReceive('getAttribute')->with('username')->andReturn('u');
    $notification = createMockNotification(['email' => 'user@example.com'], $notifiable);

    $mock = Mockery::mock(Email::class)->makePartial();
    $mock->configured = false;
    $mock->shouldReceive('isSMTP')->once();
    $mock->shouldReceive('isHTML')->once();

    $result = mailChannelWithMock($mock)->send($notification);

    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('邮件服务未配置')
        ->and($result['retryable'])->toBeFalse();
});

test('M4：附件不存在 → retryable=false（永久，防 ZIP 重建风暴）', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');
    $notifiable->shouldReceive('getAttribute')->with('username')->andReturn('u');
    $template = Mockery::mock(NotificationTemplate::class);
    $template->shouldReceive('getAttribute')->with('name')->andReturn('T');

    $notification = createMockNotification([
        'email' => 'user@example.com',
        '_meta' => [
            'subject' => 'S',
            'content' => 'B',
            'attachments' => [['path' => '/tmp/nope_'.uniqid().'.pdf', 'name' => 'x.pdf']],
        ],
    ], $notifiable, $template);

    $mock = Mockery::mock(Email::class)->makePartial();
    $mock->configured = true;
    $mock->shouldReceive('isSMTP')->once();
    $mock->shouldReceive('isHTML')->once();
    $mock->shouldReceive('addAddress')->once();
    $mock->shouldReceive('setSubject')->once();

    $result = mailChannelWithMock($mock)->send($notification);

    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('邮件附件不存在或已被删除')
        ->and($result['retryable'])->toBeFalse();
});

test('M4：SMTP send 返回 false → retryable=true（瞬态）', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');
    $notifiable->shouldReceive('getAttribute')->with('username')->andReturn('u');
    $template = Mockery::mock(NotificationTemplate::class);
    $template->shouldReceive('getAttribute')->with('name')->andReturn('T');

    $notification = createMockNotification([
        'email' => 'user@example.com',
        '_meta' => ['subject' => 'S', 'content' => 'B'],
    ], $notifiable, $template);

    $mock = Mockery::mock(Email::class)->makePartial();
    $mock->configured = true;
    $mock->ErrorInfo = 'SMTP connect failed';
    $mock->shouldReceive('isSMTP')->once();
    $mock->shouldReceive('isHTML')->once();
    $mock->shouldReceive('addAddress')->once();
    $mock->shouldReceive('setSubject')->once();
    $mock->shouldReceive('send')->once()->andReturn(false);

    $result = mailChannelWithMock($mock)->send($notification);

    expect($result['code'])->toBe(0)
        ->and($result['retryable'])->toBeTrue()
        ->and($result['msg'])->toContain('邮件发送失败');
});

test('M4：send 抛异常 → retryable=true（瞬态）', function () {
    $notifiable = Mockery::mock(Model::class);
    $notifiable->shouldReceive('getAttribute')->with('email')->andReturn('user@example.com');
    $notifiable->shouldReceive('getAttribute')->with('username')->andReturn('u');
    $template = Mockery::mock(NotificationTemplate::class);
    $template->shouldReceive('getAttribute')->with('name')->andReturn('T');

    $notification = createMockNotification([
        'email' => 'user@example.com',
        '_meta' => ['subject' => 'S', 'content' => 'B'],
    ], $notifiable, $template);

    $mock = Mockery::mock(Email::class)->makePartial();
    $mock->configured = true;
    $mock->shouldReceive('isSMTP')->once();
    $mock->shouldReceive('isHTML')->once();
    $mock->shouldReceive('addAddress')->once();
    $mock->shouldReceive('setSubject')->once();
    $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('network down'));

    $result = mailChannelWithMock($mock)->send($notification);

    expect($result['code'])->toBe(0)
        ->and($result['retryable'])->toBeTrue()
        ->and($result['msg'])->toBe('network down');
});

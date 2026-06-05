<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\SecurityNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function buildSecurityPayload(User $user, array $context)
{
    return (new SecurityNotificationBuilder)->build(
        new NotificationIntent('security', 'user', $user->id, $context),
        $user
    );
}

test('build 组装 username/event/email，纯文本 is_html=false，不携带 transient', function () {
    $user = User::factory()->create(['username' => 'alice', 'email' => 'alice@example.com']);

    $payload = buildSecurityPayload($user, ['event' => '登录密码已修改']);

    expect($payload->data['username'])->toBe('alice')
        ->and($payload->data['event'])->toBe('登录密码已修改')
        ->and($payload->data['email'])->toBe('alice@example.com')
        ->and($payload->data['_meta']['is_html'])->toBeFalse()
        ->and($payload->data['subject'])->toContain('账号安全提醒')
        ->and($payload->transient)->toBe([]);
});

test('event 含敏感字段也不会入库（Builder 白名单只取 username/event/email）', function () {
    $user = User::factory()->create(['email' => 'u@example.com']);

    // 即便调用方误传 password，Builder 也不会把它放进 data
    $payload = buildSecurityPayload($user, ['event' => '密码已修改', 'password' => 'should-not-leak']);

    expect($payload->data)->not->toHaveKey('password')
        ->and(json_encode($payload->data))->not->toContain('should-not-leak');
});

test('email 缺省时回落到 notifiable->email', function () {
    $user = User::factory()->create(['email' => 'fallback@example.com']);

    $payload = buildSecurityPayload($user, ['event' => '密码已重置']);

    expect($payload->data['email'])->toBe('fallback@example.com');
});

test('event 为空（含纯空白）抛异常', function () {
    $user = User::factory()->create(['email' => 'u@example.com']);

    expect(fn () => buildSecurityPayload($user, ['event' => '  ']))
        ->toThrow(RuntimeException::class, '安全事件描述不能为空');
});

test('邮箱为空抛异常', function () {
    $user = User::factory()->create(['email' => null]);

    expect(fn () => buildSecurityPayload($user, ['event' => '密码已修改']))
        ->toThrow(RuntimeException::class, '邮箱为空');
});

test('非 User 接收者抛异常', function () {
    $admin = Admin::factory()->create();

    expect(fn () => (new SecurityNotificationBuilder)->build(
        new NotificationIntent('security', 'admin', $admin->id, ['event' => 'x']),
        $admin
    ))->toThrow(RuntimeException::class, '通知接收者必须为用户');
});

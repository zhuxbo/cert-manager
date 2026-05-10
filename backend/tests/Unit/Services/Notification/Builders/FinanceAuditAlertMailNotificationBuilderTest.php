<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\FinanceAuditAlertMailNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

beforeEach(function () {
    // 避开 site 设置真实查表（Unit 不走 RefreshDatabase）
    Cache::put('setting:group_name:site', ['name' => 'SSL证书管理系统'], 3600);
});

afterEach(function () {
    Mockery::close();
});

test('接收者非 Admin 时抛出异常', function () {
    $builder = new FinanceAuditAlertMailNotificationBuilder;
    $intent = new NotificationIntent('finance_audit_alert', 'admin', 1, [
        'admin_email' => 'a@b.c',
    ]);
    $user = Mockery::mock(User::class)->makePartial();

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '通知接收者必须为管理员');

test('admin_email 与 admin->email 都为空时抛出异常', function () {
    $builder = new FinanceAuditAlertMailNotificationBuilder;
    $intent = new NotificationIntent('finance_audit_alert', 'admin', 1, []);

    $admin = Mockery::mock(Admin::class)->makePartial();
    $admin->shouldReceive('getAttribute')->with('email')->andReturn(null);

    $builder->build($intent, $admin);
})->throws(RuntimeException::class, '管理员邮箱为空');

test('正常构建时输出 email / subject / is_html / violations 透传', function () {
    $builder = new FinanceAuditAlertMailNotificationBuilder;

    $violations = [
        [
            'layer' => 'L1',
            'message' => 'L1 账目恒等破：1 个用户的 balance 与 transactions 累计存在偏离',
            'rows' => [['id' => 1, 'username' => 'u1', 'drift' => '999.00']],
            'rows_total' => 1,
        ],
    ];

    $intent = new NotificationIntent('finance_audit_alert', 'admin', 1, [
        'admin_email' => 'admin@test.local',
        'violation_count' => 1,
        'violations' => $violations,
        'detected_at' => '2026-05-08 03:00:00',
    ]);

    $admin = Mockery::mock(Admin::class)->makePartial();
    $admin->shouldReceive('getAttribute')->with('email')->andReturn('fallback@test.local');

    $payload = $builder->build($intent, $admin);

    expect($payload)->toBeInstanceOf(NotificationPayload::class);
    expect($payload->channels)->toBe(['mail']);
    expect($payload->data['email'])->toBe('admin@test.local');
    expect($payload->data['violation_count'])->toBe(1);
    expect($payload->data['violations'])->toBe($violations);
    expect($payload->data['detected_at'])->toBe('2026-05-08 03:00:00');
    expect($payload->data['_meta']['email'])->toBe('admin@test.local');
    expect($payload->data['_meta']['is_html'])->toBeTrue();
    expect($payload->data['_meta']['subject'])->toContain('资金审计告警');
});

test('admin_email 缺失时回落到 notifiable->email', function () {
    $builder = new FinanceAuditAlertMailNotificationBuilder;

    $intent = new NotificationIntent('finance_audit_alert', 'admin', 1, [
        'violation_count' => 0,
        'violations' => [],
        'detected_at' => '2026-05-08 03:00:00',
    ]);

    $admin = Mockery::mock(Admin::class)->makePartial();
    $admin->shouldReceive('getAttribute')->with('email')->andReturn('fallback@test.local');

    $payload = $builder->build($intent, $admin);

    expect($payload->data['email'])->toBe('fallback@test.local');
});

test('Seeder 的 finance_audit_alert HTML 模板能用 Blade 正确渲染', function () {
    // 反射拿到 Seeder 的私有 HTML，绕开 db 依赖直接 Blade::render
    $seeder = new NotificationTemplateSeeder;
    $method = new ReflectionMethod($seeder, 'getFinanceAuditAlertHtml');
    $method->setAccessible(true);
    $html = $method->invoke($seeder);

    $rendered = Blade::render($html, [
        'admin_email' => 'admin@test.local',
        'violation_count' => 2,
        'violations' => [
            [
                'layer' => 'L1',
                'message' => 'L1 账目恒等破：1 个用户偏离',
                'rows' => [['id' => 1, 'drift' => '99.00']],
                'rows_total' => 1,
            ],
            [
                'layer' => 'L3',
                'message' => 'L3 状态-事件配对破：2 条 fund 缺 transaction',
                'rows' => [['fund_id' => 100], ['fund_id' => 101]],
                'rows_total' => 2,
            ],
        ],
        'detected_at' => '2026-05-08 03:00:00',
    ]);

    expect($rendered)->toContain('资金审计告警');
    expect($rendered)->toContain('共发现 2 项不变式违反');
    expect($rendered)->toContain('L1');
    expect($rendered)->toContain('L3');
    expect($rendered)->toContain('2026-05-08 03:00:00');
    // Blade {{ }} 自动 escape，渲染后是 &quot;fund_id&quot;
    expect($rendered)->toContain('fund_id');
});

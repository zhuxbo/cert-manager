<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\FundAudit\FundInvariants;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;

/**
 * FundAuditCommand 行为覆盖。
 *
 * Mockery 替换 FundInvariants 控制返回值（避免真实撕裂数据），并替换
 * NotificationCenter 拦截邮件 dispatch（不真发邮件）。本测试不在 Pest.php
 * afterEach invariant hook 范围内，假违反数据不会触发 hook 误报。
 */
afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    // Mock FundInvariants 控制返回值，避免造真实撕裂数据
    $this->fundInvariants = Mockery::mock(FundInvariants::class);
    $this->app->instance(FundInvariants::class, $this->fundInvariants);

    // Mock NotificationCenter 拦截邮件 dispatch
    $this->notificationCenter = Mockery::mock(NotificationCenter::class);
    $this->app->instance(NotificationCenter::class, $this->notificationCenter);
});

test('签名为 finance:audit', function () {
    $this->fundInvariants->shouldReceive('all')->once()->andReturn([]);

    $this->artisan('finance:audit')->assertSuccessful();
});

test('无违反 → 命令成功 + 不发邮件', function () {
    $this->fundInvariants->shouldReceive('all')->once()->andReturn([]);

    // 不应调 dispatch
    $this->notificationCenter->shouldNotReceive('dispatch');

    $this->artisan('finance:audit')
        ->expectsOutputToContain('资金审计校验通过')
        ->assertExitCode(0);
});

test('有 L1 违反 → 命令成功 + NotificationCenter dispatch 被调', function () {
    // 必须有 admin 才能 dispatch（否则命令走 "未找到管理员邮箱" 分支）
    Admin::factory()->create(['email' => 'admin@example.com']);

    $user = User::factory()->withBalance('100.00')->create();

    // 模拟 L1 违反返回值
    $this->fundInvariants->shouldReceive('all')->once()->andReturn([
        [
            'layer' => 'L1',
            'message' => 'L1 账目恒等破：1 个用户的 balance 与 transactions 累计存在偏离',
            'rows' => [
                [
                    'id' => $user->id,
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'balance' => '1099.00',
                    'tx_sum' => '100.00',
                    'drift' => '999.00',
                ],
            ],
        ],
    ]);

    // 期望 NotificationCenter::dispatch 被调一次，code = finance_audit_alert
    $this->notificationCenter->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($intent) {
            return $intent instanceof NotificationIntent
                && $intent->code === 'finance_audit_alert'
                && $intent->notifiableType === 'admin'
                && ($intent->context['violation_count'] ?? 0) === 1
                && isset($intent->context['violations'][0]['layer'])
                && $intent->context['violations'][0]['layer'] === 'L1';
        }));

    $this->artisan('finance:audit')
        ->expectsOutputToContain('[L1]')
        ->assertExitCode(0);

    // 命令始终 return 0（违反靠邮件告警），未启用 freeze 时 user.status 不变
    $user->refresh();
    expect($user->status)->toBe(1);
});

test('--freeze-on-violation + 违反 → 涉事 user.status=0 + dispatch 被调', function () {
    Admin::factory()->create(['email' => 'admin@example.com']);

    $user = User::factory()->withBalance('200.00')->create();
    expect($user->status)->toBe(1);

    $this->fundInvariants->shouldReceive('all')->once()->andReturn([
        [
            'layer' => 'L1',
            'message' => 'L1 账目恒等破：1 个用户的 balance 与 transactions 累计存在偏离',
            'rows' => [
                [
                    'id' => $user->id,
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'balance' => '1199.00',
                    'tx_sum' => '200.00',
                    'drift' => '999.00',
                ],
            ],
        ],
    ]);

    $this->notificationCenter->shouldReceive('dispatch')->once();

    $this->artisan('finance:audit', ['--freeze-on-violation' => true])
        ->expectsOutputToContain('已 freeze 1 个涉事用户')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->status)->toBe(0);
});

test('多个违反层 + --freeze-on-violation → 合并涉事 user_id 去重', function () {
    Admin::factory()->create(['email' => 'admin@example.com']);

    $u1 = User::factory()->withBalance('100.00')->create();
    $u2 = User::factory()->withBalance('200.00')->create();

    $this->fundInvariants->shouldReceive('all')->once()->andReturn([
        [
            'layer' => 'L1',
            'message' => 'L1 violations',
            'rows' => [
                ['user_id' => $u1->id, 'username' => $u1->username],
                ['user_id' => $u1->id, 'username' => $u1->username], // 重复 u1
            ],
        ],
        [
            'layer' => 'L3',
            'message' => 'L3 violations',
            'rows' => [
                ['fund_id' => 1, 'user_id' => $u2->id, 'type' => 'addfunds'],
            ],
        ],
    ]);

    $this->notificationCenter->shouldReceive('dispatch')->once();

    $this->artisan('finance:audit', ['--freeze-on-violation' => true])
        ->expectsOutputToContain('已 freeze 2 个涉事用户')
        ->assertExitCode(0);

    $u1->refresh();
    $u2->refresh();
    expect($u1->status)->toBe(0);
    expect($u2->status)->toBe(0);
});

test('rows 超过 10 行只显示前 10 行', function () {
    Admin::factory()->create(['email' => 'admin@example.com']);

    $rows = [];
    for ($i = 1; $i <= 15; $i++) {
        $rows[] = ['user_id' => $i, 'username' => "user{$i}"];
    }

    $this->fundInvariants->shouldReceive('all')->once()->andReturn([
        [
            'layer' => 'L1',
            'message' => 'L1 大量违反',
            'rows' => $rows,
        ],
    ]);

    $this->notificationCenter->shouldReceive('dispatch')->once();

    $this->artisan('finance:audit')
        ->expectsOutputToContain('共 15 行，仅显示前 10 行')
        ->assertExitCode(0);
});

test('FundInvariants 抛异常 → 命令返回 1（FAILURE）', function () {
    $this->fundInvariants->shouldReceive('all')
        ->once()
        ->andThrow(new \RuntimeException('DB connection lost'));

    // 异常路径不应 dispatch
    $this->notificationCenter->shouldNotReceive('dispatch');

    $this->artisan('finance:audit')
        ->expectsOutputToContain('finance:audit 执行失败')
        ->assertExitCode(1);
});

test('未找到管理员邮箱时跳过邮件但不报错', function () {
    // 不创建任何 admin
    Admin::query()->delete();

    $user = User::factory()->withBalance('100.00')->create();

    $this->fundInvariants->shouldReceive('all')->once()->andReturn([
        [
            'layer' => 'L1',
            'message' => 'test',
            'rows' => [['user_id' => $user->id]],
        ],
    ]);

    // 没有 admin → 不应调 dispatch
    $this->notificationCenter->shouldNotReceive('dispatch');

    $this->artisan('finance:audit')
        ->expectsOutputToContain('未找到管理员邮箱，跳过邮件告警')
        ->assertExitCode(0);
});

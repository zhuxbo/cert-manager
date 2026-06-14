<?php

use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsUser;

uses(RefreshDatabase::class, ActsAsUser::class);

test('updatePassword 成功后派发 security 通知（event=登录密码已修改）', function () {
    $user = User::factory()->create(['email' => 'u@example.com', 'password' => 'oldpass1']);

    $this->mock(NotificationCenter::class, function ($mock) use ($user) {
        $mock->shouldReceive('dispatch')->once()->withArgs(
            fn (NotificationIntent $intent) => $intent->code === 'security'
                && $intent->notifiableId === $user->id
                && $intent->context['event'] === '登录密码已修改'
        );
    });

    $this->actingAsUser($user)
        ->patchJson('/api/update-password', [
            'oldPassword' => 'oldpass1',
            'newPassword' => 'newpass1',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('resetPassword 对已注册邮箱成功后派发 security 通知（event 含重置）', function () {
    $user = User::factory()->create(['email' => 'r@example.com']);
    Cache::put('verify_code_reset_r@example.com', '123456', 600);

    $this->mock(NotificationCenter::class, function ($mock) use ($user) {
        $mock->shouldReceive('dispatch')->once()->withArgs(
            fn (NotificationIntent $intent) => $intent->code === 'security'
                && $intent->notifiableId === $user->id
                && str_contains($intent->context['event'], '重置')
        );
    });

    $this->postJson('/api/reset-password', [
        'email' => 'r@example.com',
        'code' => '123456',
        'password' => 'newpass1',
    ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('resetPassword 对未注册邮箱不派通知，但仍返回成功（账号枚举防护）', function () {
    Cache::put('verify_code_reset_ghost@example.com', '123456', 600);

    $this->mock(NotificationCenter::class, function ($mock) {
        $mock->shouldNotReceive('dispatch');
    });

    $this->postJson('/api/reset-password', [
        'email' => 'ghost@example.com',
        'code' => '123456',
        'password' => 'newpass1',
    ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

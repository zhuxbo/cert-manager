<?php

use App\Models\CnameDelegation;
use App\Models\User;
use Illuminate\Support\Carbon;

test('委托记录属于用户', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);

    expect($delegation->user)->toBeInstanceOf(User::class);
    expect($delegation->user->id)->toBe($user->id);
});

test('valid 字段为布尔值 cast', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'valid' => 1,
    ]);
    $delegation->refresh();

    expect($delegation->valid)->toBeBool();
    expect($delegation->valid)->toBeTrue();
});

test('无效状态的委托记录', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->invalid()->create([
        'user_id' => $user->id,
    ]);

    expect($delegation->valid)->toBeFalse();
    expect($delegation->fail_count)->toBeGreaterThan(0);
    expect($delegation->last_error)->not->toBeEmpty();
});

test('已验证状态的委托记录', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->verified()->create([
        'user_id' => $user->id,
    ]);

    expect($delegation->valid)->toBeTrue();
    expect($delegation->fail_count)->toBe(0);
    expect($delegation->last_checked_at)->not->toBeNull();
});

test('fail_count 为整数 cast', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'fail_count' => '5',
    ]);
    $delegation->refresh();

    expect($delegation->fail_count)->toBeInt();
    expect($delegation->fail_count)->toBe(5);
});

test('last_checked_at 为日期时间 cast', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->verified()->create([
        'user_id' => $user->id,
    ]);
    $delegation->refresh();

    expect($delegation->last_checked_at)->toBeInstanceOf(Carbon::class);
});

test('target_fqdn 由 label 和冻结的 proxy_domain 组合', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'label' => 'test_label_12345678',
        'proxy_domain' => 'proxy.example.com',
    ]);

    expect($delegation->proxy_domain)->toBe('proxy.example.com')
        ->and($delegation->target_fqdn)->toBe('test_label_12345678.proxy.example.com');
});

test('委托数组只暴露 proxy_domain', function () {
    $delegation = CnameDelegation::factory()->create([
        'proxy_domain' => 'proxy.example.com',
    ]);

    expect($delegation->toArray())->toHaveKey('proxy_domain')
        ->and($delegation->toArray())->not->toHaveKey('proxy_zone');
});

test('fillable 字段可批量赋值', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'label' => 'abc123def456abc123def456abc123de',
    ]);

    expect($delegation->zone)->toBe('example.com');
    expect($delegation->prefix)->toBe('_dnsauth');
});

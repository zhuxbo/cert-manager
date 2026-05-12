<?php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Carbon;

test('API 令牌属于用户', function () {
    $user = User::factory()->create();
    $apiToken = ApiToken::factory()->create(['user_id' => $user->id]);

    expect($apiToken->user)->toBeInstanceOf(User::class);
    expect($apiToken->user->id)->toBe($user->id);
});

test('token 存储为 SHA256 哈希', function () {
    $user = User::factory()->create();
    $rawToken = 'test_token_raw_value_here_12345678';

    $apiToken = ApiToken::factory()->create([
        'user_id' => $user->id,
        'token' => $rawToken,
    ]);

    // 数据库中存的是 SHA256 哈希
    expect($apiToken->getRawOriginal('token'))->toBe(hash('sha256', $rawToken));
});

test('token 字段在序列化时隐藏', function () {
    $apiToken = ApiToken::factory()->create();
    $array = $apiToken->toArray();

    expect($array)->not->toHaveKey('token');
});

test('无 IP 限制时所有 IP 都允许', function () {
    $apiToken = ApiToken::factory()->create(['allowed_ips' => null]);

    expect($apiToken->isIpAllowed('192.168.1.1'))->toBeTrue();
    expect($apiToken->isIpAllowed('10.0.0.1'))->toBeTrue();
});

test('有 IP 限制时只允许列表中的 IP', function () {
    $apiToken = ApiToken::factory()->withAllowedIps(['10.0.0.1', '10.0.0.2'])->create();

    expect($apiToken->isIpAllowed('10.0.0.1'))->toBeTrue();
    expect($apiToken->isIpAllowed('10.0.0.2'))->toBeTrue();
    expect($apiToken->isIpAllowed('10.0.0.3'))->toBeFalse();
    expect($apiToken->isIpAllowed('192.168.1.1'))->toBeFalse();
});

test('getEffectiveRateLimit 返回自定义限流值', function () {
    $apiToken = ApiToken::factory()->withRateLimit(100)->create();

    expect($apiToken->getEffectiveRateLimit())->toBe(100);
});

test('getEffectiveRateLimit 为 0 时返回默认值', function () {
    $apiToken = ApiToken::factory()->create(['rate_limit' => 0]);

    expect($apiToken->getEffectiveRateLimit(60))->toBe(60);
    expect($apiToken->getEffectiveRateLimit(30))->toBe(30);
});

test('createToken 创建令牌并返回原始值', function () {
    $user = User::factory()->create();

    $plainToken = ApiToken::createToken($user->id);

    expect($plainToken)->toBeString();
    expect(strlen($plainToken))->toBe(64);

    // 数据库中应存在该令牌
    $exists = ApiToken::where('token', hash('sha256', $plainToken))->exists();
    expect($exists)->toBeTrue();
});

test('deleteTokenByToken 删除指定令牌', function () {
    $user = User::factory()->create();
    $rawToken = 'token_to_delete_12345678';
    $apiToken = ApiToken::factory()->create([
        'user_id' => $user->id,
        'token' => $rawToken,
    ]);

    ApiToken::deleteTokenByToken($rawToken);

    expect(ApiToken::find($apiToken->id))->toBeNull();
});

test('deleteTokenByUserId 删除用户所有令牌', function () {
    $user = User::factory()->create();
    ApiToken::factory()->count(3)->create(['user_id' => $user->id]);

    expect(ApiToken::where('user_id', $user->id)->count())->toBe(3);

    ApiToken::deleteTokenByUserId($user->id);

    expect(ApiToken::where('user_id', $user->id)->count())->toBe(0);
});

test('allowed_ips 为数组 cast', function () {
    $apiToken = ApiToken::factory()->withAllowedIps(['1.1.1.1', '2.2.2.2'])->create();
    $apiToken->refresh();

    expect($apiToken->allowed_ips)->toBeArray();
    expect($apiToken->allowed_ips)->toContain('1.1.1.1');
});

test('status 为整数 cast', function () {
    $apiToken = ApiToken::factory()->create(['status' => '1']);
    $apiToken->refresh();

    expect($apiToken->status)->toBeInt();
});

test('last_used_at 为日期时间 cast', function () {
    $apiToken = ApiToken::factory()->used()->create();
    $apiToken->refresh();

    expect($apiToken->last_used_at)->toBeInstanceOf(Carbon::class);
});

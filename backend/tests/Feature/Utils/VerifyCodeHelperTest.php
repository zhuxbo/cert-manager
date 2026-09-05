<?php

use App\Utils\VerifyCodeHelper;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::store('runtime')->flush();
});

test('verifyEmailCode 正确验证码通过并删除缓存', function () {
    Cache::store('runtime')->put('verify_code_reset_user@example.com', '123456', 600);

    expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '123456', 'reset'))->toBeTrue();
    // 成功后验证码被删除
    expect(Cache::store('runtime')->has('verify_code_reset_user@example.com'))->toBeFalse();
});

test('verifyEmailCode 连续失败达到阈值后作废验证码', function () {
    Cache::store('runtime')->put('verify_code_reset_user@example.com', '123456', 600);

    // 前 4 次错误：验证码仍在
    for ($i = 0; $i < 4; $i++) {
        expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '000000', 'reset'))->toBeFalse();
    }
    expect(Cache::store('runtime')->has('verify_code_reset_user@example.com'))->toBeTrue();

    // 第 5 次错误：达到阈值，作废验证码
    expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '000000', 'reset'))->toBeFalse();
    expect(Cache::store('runtime')->has('verify_code_reset_user@example.com'))->toBeFalse();

    // 即便后续提交正确验证码也无法通过（已被作废，无法继续暴力猜测）
    expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '123456', 'reset'))->toBeFalse();
});

test('verifyEmailCode 无验证码时直接失败且不累计', function () {
    // 无缓存验证码：失败，不写失败计数
    expect(VerifyCodeHelper::verifyEmailCode('ghost@example.com', '123456', 'reset'))->toBeFalse();
    expect(Cache::store('runtime')->has('verify_code_fail_reset_ghost@example.com'))->toBeFalse();
});

test('verifySmsCode 同样具备失败计数作废逻辑', function () {
    Cache::store('runtime')->put('verify_code_reset_13800138000', '654321', 600);

    for ($i = 0; $i < 5; $i++) {
        expect(VerifyCodeHelper::verifySmsCode('13800138000', '111111', 'reset'))->toBeFalse();
    }

    // 作废后正确码也失效
    expect(Cache::store('runtime')->has('verify_code_reset_13800138000'))->toBeFalse();
    expect(VerifyCodeHelper::verifySmsCode('13800138000', '654321', 'reset'))->toBeFalse();
});

test('generateCode 生成 6 位纯数字且随机', function () {
    $method = new ReflectionMethod(VerifyCodeHelper::class, 'generateCode');
    $method->setAccessible(true);

    $codes = [];
    for ($i = 0; $i < 50; $i++) {
        $code = $method->invoke(null);
        expect($code)->toMatch('/^\d{6}$/');
        $codes[] = $code;
    }

    // 50 次生成不应全部相同（random_int 随机性）
    expect(count(array_unique($codes)))->toBeGreaterThan(1);
});

test('checkSendCooldown 首次放行并原子占位，冷却期内二次被拦截', function () {
    $check = new ReflectionMethod(VerifyCodeHelper::class, 'checkSendCooldown');
    $check->setAccessible(true);

    // 首次：放行（null），并用 Cache::store('runtime')->add 写入冷却占位
    expect($check->invoke(null, 'user@example.com'))->toBeNull();

    // 二次（冷却期内）：被原子占位拦截
    $result = $check->invoke(null, 'user@example.com');
    expect($result)->toBeArray()
        ->and($result['code'])->toBe(0)
        ->and($result['msg'])->toContain('过于频繁');
});

test('releaseSendCooldown 释放占位后可立即重试（模拟发送失败回滚）', function () {
    $check = new ReflectionMethod(VerifyCodeHelper::class, 'checkSendCooldown');
    $check->setAccessible(true);
    $release = new ReflectionMethod(VerifyCodeHelper::class, 'releaseSendCooldown');
    $release->setAccessible(true);

    expect($check->invoke(null, 'user@example.com'))->toBeNull();          // 占位
    expect($check->invoke(null, 'user@example.com'))->toBeArray();         // 冷却期内被拦

    $release->invoke(null, 'user@example.com');                            // 发送失败 → 释放占位

    expect($check->invoke(null, 'user@example.com'))->toBeNull();          // 释放后可立即重试
});

test('checkSendCooldown 今日达上限时拒绝并释放冷却占位', function () {
    $check = new ReflectionMethod(VerifyCodeHelper::class, 'checkSendCooldown');
    $check->setAccessible(true);

    // 预置当日计数已达上限（DAILY_SEND_LIMIT = 10）
    $dailyKey = 'verify_code_daily_user@example.com_'.date('Ymd');
    Cache::store('runtime')->put($dailyKey, 10, now()->endOfDay());

    // 冷却占位成功但每日超限 → 返回"已达上限"
    $result = $check->invoke(null, 'user@example.com');
    expect($result)->toBeArray()
        ->and($result['code'])->toBe(0)
        ->and($result['msg'])->toContain('上限');

    // 关键回归：超限时释放了冷却占位 → 再次调用仍返回"上限"（而非"过于频繁"），
    // 证明冷却未被白占（若漏 releaseSendCooldown，此处会变成"过于频繁"）
    $result2 = $check->invoke(null, 'user@example.com');
    expect($result2['msg'])->toContain('上限');
});

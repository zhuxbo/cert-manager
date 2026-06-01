<?php

use App\Utils\VerifyCodeHelper;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('verifyEmailCode 正确验证码通过并删除缓存', function () {
    Cache::put('verify_code_reset_user@example.com', '123456', 600);

    expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '123456', 'reset'))->toBeTrue();
    // 成功后验证码被删除
    expect(Cache::has('verify_code_reset_user@example.com'))->toBeFalse();
});

test('verifyEmailCode 连续失败达到阈值后作废验证码', function () {
    Cache::put('verify_code_reset_user@example.com', '123456', 600);

    // 前 4 次错误：验证码仍在
    for ($i = 0; $i < 4; $i++) {
        expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '000000', 'reset'))->toBeFalse();
    }
    expect(Cache::has('verify_code_reset_user@example.com'))->toBeTrue();

    // 第 5 次错误：达到阈值，作废验证码
    expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '000000', 'reset'))->toBeFalse();
    expect(Cache::has('verify_code_reset_user@example.com'))->toBeFalse();

    // 即便后续提交正确验证码也无法通过（已被作废，无法继续暴力猜测）
    expect(VerifyCodeHelper::verifyEmailCode('user@example.com', '123456', 'reset'))->toBeFalse();
});

test('verifyEmailCode 无验证码时直接失败且不累计', function () {
    // 无缓存验证码：失败，不写失败计数
    expect(VerifyCodeHelper::verifyEmailCode('ghost@example.com', '123456', 'reset'))->toBeFalse();
    expect(Cache::has('verify_code_fail_reset_ghost@example.com'))->toBeFalse();
});

test('verifySmsCode 同样具备失败计数作废逻辑', function () {
    Cache::put('verify_code_reset_13800138000', '654321', 600);

    for ($i = 0; $i < 5; $i++) {
        expect(VerifyCodeHelper::verifySmsCode('13800138000', '111111', 'reset'))->toBeFalse();
    }

    // 作废后正确码也失效
    expect(Cache::has('verify_code_reset_13800138000'))->toBeFalse();
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

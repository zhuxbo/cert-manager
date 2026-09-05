<?php

use App\Exceptions\ApiResponseException;
use App\Http\Middleware\LoginRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 清理登录限流和锁定状态，避免缓存状态在测试间串扰。
 */
function clearLoginRateLimitState(string $key): void
{
    RateLimiter::clear($key);
    RateLimiter::clear($key.':lockout');
    Cache::store('runtime')->forget($key.'_locked');
}

beforeEach(function () {
    Cache::store('runtime')->flush();
});

// ==========================================
// 基础功能
// ==========================================

test('LoginRateLimiter 正常请求透传', function () {
    $middleware = new LoginRateLimiter;
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'pass']);

    $response = $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1, 'data' => ['access_token' => 'xxx']]);
    }, 'admin');

    expect($response->getData(true)['code'])->toBe(1);
});

test('LoginRateLimiter 登录失败增加计数器', function () {
    $middleware = new LoginRateLimiter;
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'wrong']);

    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
    }, 'admin');

    expect((int) RateLimiter::attempts('admin:testuser'))->toBe(1)
        ->and((int) RateLimiter::attempts('admin:testuser:lockout'))->toBe(1);
});

test('LoginRateLimiter 登录成功重置计数器', function () {
    $middleware = new LoginRateLimiter;

    for ($i = 0; $i < 3; $i++) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }
    expect((int) RateLimiter::attempts('admin:testuser'))->toBe(3)
        ->and((int) RateLimiter::attempts('admin:testuser:lockout'))->toBe(3);

    $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'correct']);
    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1, 'data' => ['access_token' => 'xxx']]);
    }, 'admin');

    expect((int) RateLimiter::attempts('admin:testuser'))->toBe(0)
        ->and((int) RateLimiter::attempts('admin:testuser:lockout'))->toBe(0);
});

test('LoginRateLimiter 超过限制抛出异常', function () {
    $middleware = new LoginRateLimiter;

    for ($i = 0; $i < 5; $i++) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }

    $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'try']);
    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1]);
    }, 'admin');
})->throws(ApiResponseException::class);

test('LoginRateLimiter 被锁定账号抛出异常', function () {
    Cache::store('runtime')->put('admin:testuser_locked', true, now()->addHours(24));

    $middleware = new LoginRateLimiter;
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'testuser', 'password' => 'pass']);

    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1]);
    }, 'admin');
})->throws(ApiResponseException::class);

test('LoginRateLimiter 无 account 时使用 IP 作为 key', function () {
    $middleware = new LoginRateLimiter;
    $request = Request::create('/api/admin/login', 'POST');

    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 0, 'msg' => '账号不能为空']);
    }, 'admin');

    expect((int) RateLimiter::attempts('admin:127.0.0.1'))->toBe(1);
});

// ==========================================
// 锁定机制
// ==========================================

test('LoginRateLimiter 跨窗口累计失败达到阈值后锁定账号', function () {
    $middleware = new LoginRateLimiter;
    $key = 'admin:lockoutuser';
    $lockoutKey = $key.':lockout';

    // 使用较小阈值方便测试：窗口 2 次, 锁定 4 次
    config()->set('auth.login_rate_limiter.default', [
        'max_attempts_per_window' => 2,
        'decay_minutes' => 10,
        'lockout_attempts' => 4,
        'lockout_minutes' => 60,
        'lockout_counter_decay_minutes' => 60,
    ]);

    // 第一轮：2 次真实失败，触发限流
    for ($i = 0; $i < 2; $i++) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'lockoutuser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }
    expect((int) RateLimiter::attempts($lockoutKey))->toBe(2)
        ->and(Cache::store('runtime')->has($key.'_locked'))->toBeFalse();

    // 模拟限流窗口过期（仅清除短期计数器，保留锁定计数器）
    RateLimiter::clear($key);

    // 第二轮：第 3 次真实失败，不应提前锁定
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'lockoutuser', 'password' => 'wrong']);
    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
    }, 'admin');
    expect((int) RateLimiter::attempts($lockoutKey))->toBe(3)
        ->and(Cache::store('runtime')->has($key.'_locked'))->toBeFalse();

    // 第 4 次真实失败 → 锁定（抛出 ApiResponseException）
    try {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'lockoutuser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    } catch (ApiResponseException) {
    }
    expect(Cache::store('runtime')->has($key.'_locked'))->toBeTrue()
        ->and((int) RateLimiter::attempts($key))->toBe(0)
        ->and((int) RateLimiter::attempts($lockoutKey))->toBe(0);
});

test('LoginRateLimiter 超过限制后持续被拦截', function () {
    $middleware = new LoginRateLimiter;

    // 先累计 5 次失败
    for ($i = 0; $i < 5; $i++) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'blockeduser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }

    // 第 6 次被拦截（即使传入正确密码也不应到达 $next）
    $nextCalled = false;
    try {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'blockeduser', 'password' => 'correct']);
        $middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new JsonResponse(['code' => 1]);
        }, 'admin');
    } catch (ApiResponseException) {
        // expected
    }

    expect($nextCalled)->toBeFalse();
});

test('LoginRateLimiter 锁定后即使密码正确也无法登录', function () {
    Cache::store('runtime')->put('admin:lockeduser_locked', true, now()->addHours(24));

    $middleware = new LoginRateLimiter;
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'lockeduser', 'password' => 'correct']);

    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1, 'data' => ['access_token' => 'xxx']]);
    }, 'admin');
})->throws(ApiResponseException::class);

test('LoginRateLimiter 成功登录清除锁定标记和计数器', function () {
    $middleware = new LoginRateLimiter;
    $key = 'admin:clearuser';
    $lockoutKey = $key.':lockout';

    // 模拟之前有失败记录
    RateLimiter::hit($key, 600);
    RateLimiter::hit($key, 600);
    RateLimiter::hit($lockoutKey, 600);
    RateLimiter::hit($lockoutKey, 600);

    // 成功登录
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'clearuser', 'password' => 'correct']);
    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1, 'data' => ['access_token' => 'xxx']]);
    }, 'admin');

    expect((int) RateLimiter::attempts($key))->toBe(0)
        ->and((int) RateLimiter::attempts($lockoutKey))->toBe(0)
        ->and(Cache::store('runtime')->has($key.'_locked'))->toBeFalse();
});

// ==========================================
// 配置与隔离
// ==========================================

test('LoginRateLimiter guard 配置覆盖默认值', function () {
    $middleware = new LoginRateLimiter;

    config()->set('auth.login_rate_limiter.default', [
        'max_attempts_per_window' => 5,
        'decay_minutes' => 10,
        'lockout_attempts' => 10,
        'lockout_minutes' => 1440,
        'lockout_counter_decay_minutes' => 1440,
    ]);
    config()->set('auth.login_rate_limiter.guards.admin', [
        'max_attempts_per_window' => 1,
    ]);

    // 第 1 次失败
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'cfguser', 'password' => 'wrong']);
    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
    }, 'admin');

    // 第 2 次应被限流（因为 admin guard 只允许 1 次）
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'cfguser', 'password' => 'wrong']);
    $middleware->handle($request, function () {
        return new JsonResponse(['code' => 0]);
    }, 'admin');
})->throws(ApiResponseException::class);

test('LoginRateLimiter 自定义锁定阈值跨窗口累积触发锁定', function () {
    $middleware = new LoginRateLimiter;
    $key = 'admin:cfglockuser';

    config()->set('auth.login_rate_limiter.default', [
        'max_attempts_per_window' => 2,
        'decay_minutes' => 10,
        'lockout_attempts' => 3,
        'lockout_minutes' => 60,
        'lockout_counter_decay_minutes' => 60,
    ]);

    // 第一轮：2 次真实失败，触发限流
    for ($i = 0; $i < 2; $i++) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'cfglockuser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }

    // 模拟窗口过期
    RateLimiter::clear($key);

    // 第二轮：第 3 次真实失败 → 锁定（抛出 ApiResponseException）
    try {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'cfglockuser', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    } catch (ApiResponseException) {
    }

    expect(Cache::store('runtime')->has($key.'_locked'))->toBeTrue();
});

test('LoginRateLimiter 频率限制不影响不同账号', function () {
    $middleware = new LoginRateLimiter;

    // 账号 A 连续失败 5 次
    for ($i = 0; $i < 5; $i++) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => 'userA', 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }

    // 账号 B 不应受影响
    $request = Request::create('/api/admin/login', 'POST', ['account' => 'userB', 'password' => 'correct']);
    $response = $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1, 'data' => ['access_token' => 'xxx']]);
    }, 'admin');

    expect($response->getData(true)['code'])->toBe(1)
        ->and((int) RateLimiter::attempts('admin:userB'))->toBe(0);
});

test('LoginRateLimiter 账号大小写/空白变体共用同一限流桶（防变体绕过爆破）', function () {
    $middleware = new LoginRateLimiter;

    // 同一账号的大小写 + 前后空白变体：DB 账号匹配为 ci collation（大小写不敏感），本就是同一用户
    // 归一化后 4 个变体应落到同一个 key 'admin:victim@x.com'，而非分散到 4 个桶各计 1 次
    foreach (['victim@x.com', 'Victim@X.com', 'VICTIM@X.COM', '  victim@x.com  '] as $variant) {
        $request = Request::create('/api/admin/login', 'POST', ['account' => $variant, 'password' => 'wrong']);
        $middleware->handle($request, function () {
            return new JsonResponse(['code' => 0, 'msg' => '密码错误']);
        }, 'admin');
    }

    expect((int) RateLimiter::attempts('admin:victim@x.com'))->toBe(4);
});

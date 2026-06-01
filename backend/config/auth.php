<?php

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\UserRefreshToken;

return [
    'guards' => [
        'admin' => [
            'driver' => 'jwt',
            'provider' => 'admins',
        ],
        'user' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'api-token',
            'provider' => 'api_tokens',
        ],
        'admin-refresh-token' => [
            'driver' => 'refresh-token',
            'provider' => 'admin_refresh_tokens',
        ],
        'user-refresh-token' => [
            'driver' => 'refresh-token',
            'provider' => 'user_refresh_tokens',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
        'api_tokens' => [
            'driver' => 'eloquent',
            'model' => ApiToken::class,
        ],
        'admin_refresh_tokens' => [
            'driver' => 'eloquent',
            'model' => AdminRefreshToken::class,
        ],
        'user_refresh_tokens' => [
            'driver' => 'eloquent',
            'model' => UserRefreshToken::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 刷新令牌有效时间
    |--------------------------------------------------------------------------
    |
    | 由于每次刷新都会重新生成刷新令牌
    | 因此 这个时间相当于在多少分钟内 没有访问到接口 就会失效
    | 默认值是 1440 分钟，即 24 小时
    |
    */

    'refresh_token_ttl' => [
        'admin' => env('ADMIN_REFRESH_TOKEN_TTL', 1440),
        'user' => env('USER_REFRESH_TOKEN_TTL', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | 登录限流配置
    |--------------------------------------------------------------------------
    |
    | - max_attempts_per_window: 单个时间窗口内允许失败次数
    | - decay_minutes: 窗口时长（分钟）
    | - lockout_attempts: 达到该累计失败次数后锁定账号
    | - lockout_minutes: 锁定时长（分钟）
    | - lockout_counter_decay_minutes: 累计失败计数器有效期（分钟），建议 >= lockout_minutes，
    |   否则锁定期内计数器可能提前过期，导致解锁后失去累计保护
    |
    | 可在 guards.{guard} 中覆盖默认配置
    |
    */
    'login_rate_limiter' => [
        'default' => [
            'max_attempts_per_window' => (int) env('LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('LOGIN_RATE_LIMIT_DECAY_MINUTES', 10),
            'lockout_attempts' => (int) env('LOGIN_RATE_LIMIT_LOCKOUT_ATTEMPTS', 10),
            'lockout_minutes' => (int) env('LOGIN_RATE_LIMIT_LOCKOUT_MINUTES', 24 * 60),
            'lockout_counter_decay_minutes' => (int) env('LOGIN_RATE_LIMIT_LOCKOUT_COUNTER_DECAY_MINUTES', 24 * 60),
        ],
        'guards' => [
            'admin' => [],
            'user' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 验证码 / 账户接口限流
    |--------------------------------------------------------------------------
    |
    | 用于无需认证的 register / reset-password / send-email-code 端点。
    | email 维度防换 IP 绕过 + NAT 误伤；ip 维度做粗粒度上限。
    | *_max 为窗口内最大次数，*_decay 为窗口分钟数；可在 actions.{action} 覆盖。
    |
    */
    'verify_code_rate_limiter' => [
        'default' => [
            'email_max' => (int) env('VERIFY_CODE_RL_EMAIL_MAX', 5),
            'email_decay' => (int) env('VERIFY_CODE_RL_EMAIL_DECAY', 10),
            'ip_max' => (int) env('VERIFY_CODE_RL_IP_MAX', 20),
            'ip_decay' => (int) env('VERIFY_CODE_RL_IP_DECAY', 10),
        ],
        'actions' => [
            'register' => [],
            'reset-password' => [],
            'send-email-code' => [],
        ],
    ],
];

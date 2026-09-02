<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class UserDashboardCache
{
    private const CERTIFICATE_KEYS = [
        'overview',
        'orders',
        'monthly_comparison',
    ];

    private const BALANCE_KEYS = [
        'overview',
        'assets',
        'orders',
        'trend:month',
        'trend:quarter',
        'trend:year',
        'monthly_comparison',
    ];

    public static function forgetForCertificateChange(int|string $userId): void
    {
        self::forget($userId, self::CERTIFICATE_KEYS);
    }

    public static function forgetForBalanceChange(int|string $userId): void
    {
        self::forget($userId, self::BALANCE_KEYS);
    }

    private static function forget(int|string $userId, array $keys): void
    {
        foreach ($keys as $key) {
            Cache::forget("dashboard:user:$userId:$key");
        }
    }
}

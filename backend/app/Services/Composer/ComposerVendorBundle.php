<?php

namespace App\Services\Composer;

use RuntimeException;

final class ComposerVendorBundle
{
    public const MARKER = 'vendor/composer/.ssl-manager-lock.sha256';

    public static function matchesLock(string $backendDir): bool
    {
        return self::vendorMatchesLock("$backendDir/vendor", "$backendDir/composer.lock");
    }

    public static function vendorMatchesLock(string $vendorDir, string $lock): bool
    {
        $autoload = "$vendorDir/autoload.php";
        $marker = "$vendorDir/composer/.ssl-manager-lock.sha256";

        if (! is_file($lock) || ! is_file($autoload) || ! is_file($marker)) {
            return false;
        }

        $expected = hash_file('sha256', $lock);
        $actual = strtolower(trim((string) file_get_contents($marker)));

        return is_string($expected)
            && preg_match('/^[a-f0-9]{64}$/', $actual) === 1
            && hash_equals(strtolower($expected), $actual);
    }

    public static function assertMatchesLock(string $backendDir): void
    {
        if (! self::matchesLock($backendDir)) {
            throw new RuntimeException('发布包 vendor 与 composer.lock 不匹配');
        }
    }

    public static function assertVendorMatchesLock(string $vendorDir, string $lock): void
    {
        if (! self::vendorMatchesLock($vendorDir, $lock)) {
            throw new RuntimeException('发布包 vendor 与 composer.lock 不匹配');
        }
    }
}

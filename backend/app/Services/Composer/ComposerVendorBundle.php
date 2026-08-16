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

    /**
     * Composer 成功生成 vendor 后，原子刷新与 composer.lock 对应的完整性标记。
     */
    public static function writeMarker(string $backendDir): void
    {
        $lock = "$backendDir/composer.lock";
        $autoload = "$backendDir/vendor/autoload.php";
        $composerDir = "$backendDir/vendor/composer";

        if (! is_file($lock) || ! is_file($autoload)) {
            throw new RuntimeException('无法写入 vendor 标记：composer.lock 或 vendor/autoload.php 不存在');
        }

        $hash = @hash_file('sha256', $lock);
        if (! is_string($hash)) {
            throw new RuntimeException('无法写入 vendor 标记：composer.lock 哈希计算失败');
        }

        if (! is_dir($composerDir) && ! @mkdir($composerDir, 0755, true) && ! is_dir($composerDir)) {
            throw new RuntimeException('无法写入 vendor 标记：vendor/composer 目录创建失败');
        }

        $marker = "$composerDir/.ssl-manager-lock.sha256";
        $temporary = $composerDir.'/.ssl-manager-lock.sha256.tmp-'.bin2hex(random_bytes(6));

        try {
            if (@file_put_contents($temporary, strtolower($hash)."\n", LOCK_EX) === false) {
                throw new RuntimeException('无法写入 vendor 标记临时文件');
            }
            if (! @rename($temporary, $marker)) {
                throw new RuntimeException('无法原子更新 vendor 标记');
            }
        } finally {
            @unlink($temporary);
        }
    }
}

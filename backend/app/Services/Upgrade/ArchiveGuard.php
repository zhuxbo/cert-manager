<?php

namespace App\Services\Upgrade;

use RuntimeException;
use ZipArchive;

/**
 * 归档（ZIP）解压安全校验 —— zip-slip / 符号链接 纵深防御。
 *
 * 由 BackupManager（备份恢复）与 PluginManager（插件安装）共用，避免两处校验逻辑漂移。
 *
 * 现代 libzip 已会剥离条目名中的 `../`，但仍需在应用层兜底：
 *   1. 跨平台 / 老 libzip 实现可能不剥离；
 *   2. extractTo 不防符号链接条目（解压出的 symlink 指向目标目录外，后续写入即越界）；
 *   3. 防御纵深：以应用层不可绕过的方式拒绝非法条目，不依赖底层库行为。
 */
class ArchiveGuard
{
    /**
     * Unix 文件类型掩码与符号链接标志（external attributes 高 16 位为 Unix mode）。
     */
    private const S_IFMT = 0xF000;

    private const S_IFLNK = 0xA000;

    /**
     * 逐条目校验 ZIP：拒绝路径遍历（`..`）、绝对路径（以 `/` 开头）、符号链接条目。
     *
     * @param  ZipArchive  $zip  已 open 的归档
     * @param  array<int, string>  $allowExact  精确放行的合法条目名白名单（如备份包内的 `../version.json`，
     *                                          调用方会单独安全处理这些条目，不走 extractTo）
     * @param  string  $message  校验失败抛出的异常文案（PluginManager 历史契约为「ZIP 包含非法路径」）
     *
     * @throws RuntimeException 存在非法条目
     */
    public static function assertSafeEntries(ZipArchive $zip, array $allowExact = [], string $message = 'ZIP 包含非法路径'): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            if ($entryName === false) {
                throw new RuntimeException($message);
            }

            // 精确白名单放行（调用方负责安全落地，不经 extractTo）
            if (in_array($entryName, $allowExact, true)) {
                continue;
            }

            if (self::isUnsafeEntryName($entryName)) {
                throw new RuntimeException($message);
            }

            if (self::isSymlinkEntry($zip, $i)) {
                throw new RuntimeException($message);
            }
        }
    }

    /**
     * 解压后断言：每个落地条目的 realpath 必须仍位于 $baseDir 之内。
     *
     * 这是 assertSafeEntries 的纵深兜底 —— 即便底层库未剥离 `../`、或符号链接绕过了
     * 条目级检查，最终产物落到目标目录外时也会被发现并报错（解压侧应回滚清理）。
     *
     * @param  string  $baseDir  解压目标根目录
     * @param  array<int, string>  $entryNames  归档内条目名列表
     * @param  array<int, string>  $allowExact  精确白名单（不在 $baseDir 内、由调用方另行处理的条目）
     *
     * @throws RuntimeException 存在落点越界的产物
     */
    public static function assertExtractedWithin(string $baseDir, array $entryNames, array $allowExact = [], string $message = 'ZIP 解压产物越界'): void
    {
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            throw new RuntimeException($message);
        }
        $realBase = rtrim($realBase, '/').'/';

        foreach ($entryNames as $entryName) {
            if ($entryName === '' || str_ends_with($entryName, '/')) {
                continue; // 目录条目本身无产物文件
            }
            if (in_array($entryName, $allowExact, true)) {
                continue;
            }

            $target = "$baseDir/$entryName";
            // 产物可能因解压失败而不存在；存在则其 realpath 必须落在 base 内
            $real = realpath($target);
            if ($real === false) {
                continue;
            }
            if (! str_starts_with($real, $realBase)) {
                throw new RuntimeException($message);
            }
        }
    }

    /**
     * 条目名是否不安全（路径遍历或绝对路径）。
     *
     * 拒绝任何含 `..` 段或反斜杠（Windows 风格分隔符，防跨平台绕过）的条目，
     * 以及以 `/` 开头的绝对路径。
     */
    private static function isUnsafeEntryName(string $entryName): bool
    {
        if (str_contains($entryName, '..')) {
            return true;
        }
        if (str_starts_with($entryName, '/')) {
            return true;
        }
        if (str_contains($entryName, '\\')) {
            return true;
        }

        return false;
    }

    /**
     * 判断条目是否为符号链接（读 external attributes 高 16 位的 Unix mode）。
     *
     * 非 Unix 归档 / 读取失败时按「非符号链接」处理 —— 此场景下条目名校验与解压后
     * realpath 断言仍是有效防线。
     */
    private static function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $mode = ($attr >> 16) & 0xFFFF;

        return ($mode & self::S_IFMT) === self::S_IFLNK;
    }
}

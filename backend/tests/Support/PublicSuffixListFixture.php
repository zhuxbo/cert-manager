<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * 公共后缀表（PSL）测试夹具。
 *
 * `DomainUtil::loadRules()` 缓存 `storage_path('domain-rules/public_suffix_list.dat')`（30 天
 * TTL），缓存缺失或过期就实网抓 publicsuffix.org；抓不到会静默回落到它内置的极简后缀表——那张表
 * **没有任何多级后缀**，于是 `example.com.cn` / `sub.example.co.uk` 的解析结果整体变形，
 * `DomainUtilTest` 报一堆“两字符串不相等”，排障成本极高。
 *
 * 而 `TestCase::isolateWorkerStorage()` 把测试期 storage 重定向到 worker 专属目录，全新克隆 /
 * 干净 CI / 新增 paratest worker 的首跑都是空目录 → 必须联网；缓存过期后重抓又会把测试结果绑到
 * 上游当时的表，上游改一条后缀就可能自发飘红。
 *
 * 故把 PSL 快照固化进仓库，隔离目录建好后直接灌进去：测试确定性、可离线，
 * **生产行为不变**（`DomainUtil` 一行不改，线上仍按 30 天 TTL 抓最新表）。
 *
 * 夹具刷新：`curl -fsSL -o backend/tests/Fixtures/public_suffix_list.dat
 * https://publicsuffix.org/list/public_suffix_list.dat`，跑 `DomainUtilTest` 绿了再提交。
 *
 * 这里的缓存路径是 `DomainUtil::loadRules()` 的手抄副本，抄错时联网 CI 会照常全绿
 * （DomainUtil 自己把真表抓回来），离线确定性静默失效。`PublicSuffixListFixtureTest`
 * 用探针后缀实测“DomainUtil 确实读的是被灌入的这份表”，让路径漂移当场变红。
 */
final class PublicSuffixListFixture
{
    /**
     * 缓存文件相对 storage 根的路径，与 DomainUtil::loadRules() 保持一致。
     */
    private const CACHE_RELATIVE_PATH = 'domain-rules/public_suffix_list.dat';

    /**
     * 缓存超过该时长就跳过内容比对、强制重新校验夹具并全量重灌。
     * （"mtime 不过期"由快路径的 touch 无条件保证，不归它管。）
     */
    private const REFRESH_AFTER = 7 * 24 * 60 * 60;

    /**
     * 内容比对算法，只用于判等，不做安全用途。
     */
    private const HASH_ALGO = 'xxh128';

    /**
     * 夹具最少行数。完整 PSL 约 1.6 万行，明显被截断时先在这里挡下；
     * 更精细的内容校验交给 REQUIRED_SUFFIXES（行数达标不等于内容完整）。
     */
    private const MIN_LINES = 10000;

    /**
     * 必须存在的多级后缀。少任意一条都说明夹具不是一份真正的 PSL，
     * 而多级后缀正是内置回落表缺失、DomainUtilTest 会红的那部分。
     */
    private const REQUIRED_SUFFIXES = ['com.cn', 'co.uk', 'com.au', 'org.uk', 'co.jp'];

    /**
     * 本进程已校验通过的缓存路径。同一 worker 内每个测试都会调 seed()，
     * 记下来避免重复读两个 333KB 文件做内容比对。
     */
    private static ?string $verifiedCache = null;

    /**
     * 仓内 PSL 快照路径。
     */
    public static function path(): string
    {
        return dirname(__DIR__).'/Fixtures/public_suffix_list.dat';
    }

    /**
     * 隔离 storage 中的缓存路径，供测试断言灌入结果。
     */
    public static function cachePath(string $storagePath): string
    {
        return rtrim($storagePath, '/').'/'.self::CACHE_RELATIVE_PATH;
    }

    /**
     * 校验夹具本体，合格返回 null，否则返回可直接读懂的原因。
     */
    public static function validationError(): ?string
    {
        $path = self::path();

        if (! is_file($path) || ! is_readable($path)) {
            return "公共后缀表夹具缺失或不可读：$path";
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return "公共后缀表夹具读取失败：$path";
        }

        $count = count($lines);
        if ($count < self::MIN_LINES) {
            return "公共后缀表夹具疑似被截断：$path 仅 $count 行，少于 ".self::MIN_LINES.' 行';
        }

        $present = array_flip($lines);
        $missing = array_values(array_filter(
            self::REQUIRED_SUFFIXES,
            fn (string $suffix) => ! isset($present[$suffix])
        ));

        if ($missing !== []) {
            return '公共后缀表夹具缺少多级后缀 '.implode('、', $missing)."：$path 不是有效的 PSL";
        }

        return null;
    }

    /**
     * 把夹具灌进隔离 storage 的 domain-rules 缓存位，使 DomainUtil 命中“本地缓存未过期”分支。
     *
     * 比对内容而不只比体积：旧版本 PSL 残留或被写坏的缓存都可能恰好等长，
     * 那样 DomainUtil 读到的就不是本夹具，测试又变回不确定。
     *
     * @param  string  $storagePath  worker 隔离 storage 根目录
     *
     * @throws RuntimeException 夹具不合格或写入失败时明确报错，而不是让 DomainUtil 静默回落
     */
    public static function seed(string $storagePath): void
    {
        $fixture = self::path();
        $cache = self::cachePath($storagePath);

        $fresh = is_file($cache) && (time() - (int) @filemtime($cache)) < self::REFRESH_AFTER;
        if ($fresh && (self::$verifiedCache === $cache || self::sameContent($cache, $fixture))) {
            // 每次都把 mtime 顶到当下。DomainUtil 的 30 天 TTL 是另一份手抄常量，谁把它改短，
            // 长期不动的 worker 缓存就会悄悄过期、退回实网抓表，而这里的守卫一条都不会响
            // （守卫自身写文件就会刷新 mtime，结构上侦测不到 TTL 漂移）。
            @touch($cache);
            self::$verifiedCache = $cache;

            return;
        }

        $error = self::validationError();
        if ($error !== null) {
            throw new RuntimeException(
                $error."\n夹具刷新：curl -fsSL -o backend/tests/Fixtures/public_suffix_list.dat https://publicsuffix.org/list/public_suffix_list.dat"
            );
        }

        $dir = dirname($cache);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("无法创建公共后缀表缓存目录：$dir");
        }

        // 先写同目录临时文件再 rename：copy() 会先 truncate，直写会让并发读到半截表
        // （worker-single 这个 token 在所有单进程跑法之间共享）。
        // rename 后 mtime 取当前时间，DomainUtil 的 30 天 TTL 判定为“未过期”。
        $tmp = $cache.'.'.getmypid().'.tmp';
        if (! @copy($fixture, $tmp) || ! @rename($tmp, $cache)) {
            @unlink($tmp);

            throw new RuntimeException("公共后缀表夹具写入失败：$fixture → $cache");
        }

        self::$verifiedCache = $cache;
    }

    /**
     * 缓存与夹具内容是否一致。
     *
     * 夹具读不到时返回 false 而不是让两边的 hash_file 都返回 false ——
     * 否则 `false === false` 会短路掉后面的夹具校验与明确报错。
     */
    private static function sameContent(string $cache, string $fixture): bool
    {
        $fixtureHash = @hash_file(self::HASH_ALGO, $fixture);

        return $fixtureHash !== false && $fixtureHash === @hash_file(self::HASH_ALGO, $cache);
    }
}

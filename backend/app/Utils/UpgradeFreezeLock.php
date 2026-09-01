<?php

namespace App\Utils;

use Carbon\Carbon;
use Throwable;

/**
 * 升级冻结锁（与 cache driver 解耦的文件锁）
 *
 * 升级期 freeze flag 不存 cache，存文件锁 storage/framework/upgrade.lock：
 * - 文件存在 = freeze 中
 * - 文件内容：{frozen_at, version_from, version_to, ttl_seconds, owner_source, owner_pid, reason?}
 * - owner_source/owner_pid = 持有方身份（web=后台升级进程本体、shell=upgrade.sh 经 artisan
 *   子进程、manual=admin 手动），供 upgrade:watchdog 判「锁是否属于 status.json 追踪的那场
 *   已死升级」——他方持锁绝不 unfreeze（防拆掉别人升级危险窗的 HTTP 写闸）；
 *   旧格式锁（无 owner 字段）由 watchdog 按 frozen_at vs 死升级最后心跳回退判定
 * - TTL 兜底防忘（默认 7200s），过期文件视同 unfreeze
 * - 与 Cache::flush() / cache:clear / optimize:clear / config:clear 完全解耦
 * - 跨 PHP 进程重启持久（落盘）
 */
class UpgradeFreezeLock
{
    /**
     * 默认 TTL（秒）
     */
    private const int DEFAULT_TTL_SECONDS = 7200;

    /**
     * 写入 freeze 锁文件
     *
     * 已存在普通升级锁则覆盖；已存在恢复锁则拒绝，避免升级进入恢复危险窗口。
     * 稳定 guard lock 串行化替换与删除，临时文件 rename 保证读者只见完整 JSON。
     *
     * @return bool true=写锁成功；false=写锁失败（json_encode / file_put_contents / 异常路径）
     *              调用方（UpgradeController::freeze、upgrade.sh）应据此回滚或停止流程，
     *              避免在锁未生效时仍报告 freeze 已激活、产生半坏的升级状态。
     */
    public static function freeze(?string $versionFrom = null, ?string $versionTo = null, int $ttlSeconds = self::DEFAULT_TTL_SECONDS, string $ownerSource = 'unknown'): bool
    {
        $data = [
            'frozen_at' => Carbon::now()->toIso8601String(),
            'version_from' => $versionFrom,
            'version_to' => $versionTo,
            'ttl_seconds' => $ttlSeconds,
            'owner_source' => $ownerSource,
            'owner_pid' => getmypid() ?: null,
        ];

        return self::withExclusiveGuard(function () use ($data): bool {
            $current = self::read();
            if ($current === null && is_file(self::path())) {
                return false;
            }
            if ($current !== null && ! self::isExpired($current) && ($current['owner_source'] ?? null) === 'restore') {
                return false;
            }

            return self::writeAtomically($data);
        });
    }

    /**
     * 写入数据库恢复专用持久冻结锁。
     *
     * null TTL 表示只能由 restore owner 显式解除，普通升级 watchdog 不得按时间清除。
     */
    public static function freezeRestore(string $reason): bool
    {
        $data = [
            'frozen_at' => Carbon::now()->toIso8601String(),
            'version_from' => null,
            'version_to' => null,
            'ttl_seconds' => null,
            'owner_source' => 'restore',
            'owner_pid' => getmypid() ?: null,
            'reason' => $reason,
        ];

        return self::withExclusiveGuard(function () use ($data): bool {
            $current = self::read();
            if ($current === null && is_file(self::path())) {
                return false;
            }
            if ($current !== null && ! self::isExpired($current) && ($current['owner_source'] ?? null) !== 'restore') {
                return false;
            }

            return self::writeAtomically($data);
        });
    }

    /**
     * 删除 freeze 锁文件
     *
     * 文件不存在则静默；删除失败仅记录 error_log，不抛异常。
     */
    public static function unfreeze(?string $requesterOwner = null): bool
    {
        return self::withExclusiveGuard(function () use ($requesterOwner): bool {
            $path = self::path();
            if (! is_file($path)) {
                return true;
            }

            $data = self::read();
            if ($data === null) {
                return false;
            }

            if (($data['owner_source'] ?? null) === 'restore' && $requesterOwner !== 'restore') {
                return false;
            }

            if (! @unlink($path)) {
                error_log("UpgradeFreezeLock::unfreeze() unlink failed: $path");

                return false;
            }

            return true;
        });
    }

    /**
     * 是否处于 freeze 状态
     *
     * - 文件不存在返回 false
     * - 存在但 TTL 已过期：自动删除文件并返回 false
     * - 存在且未过期返回 true
     */
    public static function isFrozen(): bool
    {
        $data = self::read();
        if ($data === null) {
            return is_file(self::path());
        }

        if (self::isExpired($data)) {
            return ! self::unfreeze();
        }

        return true;
    }

    /**
     * 读取锁信息
     *
     * 文件不存在或已过期返回 null；过期会自动删除文件。
     *
     * @return array<string, mixed>|null
     */
    public static function info(): ?array
    {
        $data = self::read();
        if ($data === null) {
            return null;
        }

        if (self::isExpired($data)) {
            if (self::unfreeze()) {
                return null;
            }

            return self::read();
        }

        return $data;
    }

    /**
     * 锁文件绝对路径
     *
     * 平时 = storage/framework/upgrade.lock；
     * 跑 paratest 并行测试时，附加 `.${TEST_TOKEN}` 后缀让每个 worker 进程
     * 独占一个锁文件，避免一个进程 freeze 影响所有进程的鉴权/健康检查。
     * paratest 仅在测试期注入 TEST_TOKEN（生产/单跑均无），逻辑零侵入。
     */
    public static function path(): string
    {
        $base = storage_path('framework/upgrade.lock');
        $token = getenv('TEST_TOKEN');
        if ($token !== false && $token !== '') {
            return $base.'.'.$token;
        }

        return $base;
    }

    /**
     * 读取并解析锁文件
     *
     * 文件不存在、不可读或 JSON 解析失败返回 null。
     *
     * @return array<string, mixed>|null
     */
    private static function read(): ?array
    {
        $path = self::path();

        try {
            if (! is_file($path)) {
                return null;
            }

            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                return null;
            }

            $data = json_decode($content, true);
            if (! is_array($data)) {
                return null;
            }

            return $data;
        } catch (Throwable $e) {
            error_log('UpgradeFreezeLock::read() exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * 判断锁是否已过期
     *
     * 缺失 frozen_at / ttl_seconds 字段时按未过期处理（保持兼容）。
     *
     * @param  array<string, mixed>  $data
     */
    private static function isExpired(array $data): bool
    {
        $frozenAt = $data['frozen_at'] ?? null;
        $ttlSeconds = array_key_exists('ttl_seconds', $data)
            ? $data['ttl_seconds']
            : self::DEFAULT_TTL_SECONDS;

        if (! is_string($frozenAt) || $frozenAt === '') {
            return false;
        }

        if ($ttlSeconds === null) {
            return ($data['owner_source'] ?? null) !== 'restore';
        }

        if (! is_int($ttlSeconds) && ! (is_string($ttlSeconds) && ctype_digit($ttlSeconds))) {
            return false;
        }

        try {
            return Carbon::parse($frozenAt)->addSeconds((int) $ttlSeconds)->isPast();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 使用不会被 rename 替换的同目录 guard inode，串行化替换与读判删。
     */
    private static function withExclusiveGuard(callable $callback): bool
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        $guard = @fopen($path.'.guard', 'c+');
        if ($guard === false) {
            return false;
        }

        $locked = false;
        try {
            $locked = @flock($guard, LOCK_EX);
            if (! $locked) {
                return false;
            }

            return (bool) $callback();
        } catch (Throwable $e) {
            error_log('UpgradeFreezeLock::withExclusiveGuard() exception: '.$e->getMessage());

            return false;
        } finally {
            if ($locked) {
                @flock($guard, LOCK_UN);
            }
            @fclose($guard);
        }
    }

    private static function writeAtomically(array $data): bool
    {
        $path = self::path();
        $temporaryPath = null;

        try {
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return false;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }

            $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));
            if (@file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                return false;
            }

            if (! @rename($temporaryPath, $path)) {
                return false;
            }

            $temporaryPath = null;

            return true;
        } catch (Throwable $e) {
            error_log('UpgradeFreezeLock::writeAtomically() exception: '.$e->getMessage());

            return false;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}

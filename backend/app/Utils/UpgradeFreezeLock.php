<?php

namespace App\Utils;

use Carbon\Carbon;
use Throwable;

/**
 * 升级冻结锁（与 cache driver 解耦的文件锁）
 *
 * 升级期 freeze flag 不存 cache，存文件锁 storage/framework/upgrade.lock：
 * - 文件存在 = freeze 中
 * - 文件内容：{frozen_at, version_from, version_to, ttl_seconds}
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
     * 已存在则覆盖；用 LOCK_EX 防并发损坏。
     *
     * @return bool true=写锁成功；false=写锁失败（json_encode / file_put_contents / 异常路径）
     *              调用方（UpgradeController::freeze、upgrade.sh）应据此回滚或停止流程，
     *              避免在锁未生效时仍报告 freeze 已激活、产生半坏的升级状态。
     */
    public static function freeze(?string $versionFrom = null, ?string $versionTo = null, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
    {
        $path = self::path();

        try {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $data = [
                'frozen_at' => Carbon::now()->toIso8601String(),
                'version_from' => $versionFrom,
                'version_to' => $versionTo,
                'ttl_seconds' => $ttlSeconds,
            ];

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                error_log('UpgradeFreezeLock::freeze() json_encode failed');

                return false;
            }

            $result = @file_put_contents($path, $json, LOCK_EX);
            if ($result === false) {
                error_log("UpgradeFreezeLock::freeze() file_put_contents failed: $path");

                return false;
            }

            return true;
        } catch (Throwable $e) {
            error_log('UpgradeFreezeLock::freeze() exception: '.$e->getMessage());

            return false;
        }
    }

    /**
     * 删除 freeze 锁文件
     *
     * 文件不存在则静默；删除失败仅记录 error_log，不抛异常。
     */
    public static function unfreeze(): void
    {
        $path = self::path();

        try {
            if (is_file($path)) {
                if (! @unlink($path)) {
                    error_log("UpgradeFreezeLock::unfreeze() unlink failed: $path");
                }
            }
        } catch (Throwable $e) {
            error_log('UpgradeFreezeLock::unfreeze() exception: '.$e->getMessage());
        }
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
            return false;
        }

        if (self::isExpired($data)) {
            self::unfreeze();

            return false;
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
            self::unfreeze();

            return null;
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
        $ttlSeconds = $data['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS;

        if (! is_string($frozenAt) || $frozenAt === '') {
            return false;
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
}

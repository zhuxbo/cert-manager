<?php

namespace App\Support;

use RuntimeException;

final class ApplicationBootstrapLock
{
    private const ENTRY_BEGIN = '// SSL_MANAGER_BOOTSTRAP_LOCK_V1_BEGIN';

    private const ENTRY_END = '// SSL_MANAGER_BOOTSTRAP_LOCK_V1_END';

    private const AUTOLOAD_ANCHOR = '// Register the Composer autoloader...';

    /**
     * @return resource
     */
    public static function acquireExclusive()
    {
        $path = dirname(base_path()).'/.upgrade-bootstrap.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('无法创建应用启动切换锁');
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('无法获取应用启动切换锁');
        }

        return $handle;
    }

    /**
     * 首次从不带共享锁的旧版本升级时，先原子注入锁片段并排空旧请求。
     *
     * 新请求会在注入后立即参与共享锁；注入前已进入的请求最多运行配置的
     * FPM 请求上限。状态文件让中断重试继续剩余时间，不能因入口已有标记而跳过。
     */
    public static function prepareLegacyHttpEntry(
        string $sourceIndex,
        string $targetIndex,
        int $drainSeconds
    ): void {
        if ($drainSeconds < 0) {
            throw new RuntimeException('旧请求排空时间不能小于 0');
        }

        $target = @file_get_contents($targetIndex);
        if (! is_string($target)) {
            throw new RuntimeException('无法读取当前 HTTP 入口');
        }

        $statePath = dirname(dirname(dirname($targetIndex))).'/.upgrade-bootstrap-prepared.json';
        $state = self::readPreparationState($statePath);
        $hasEntry = str_contains($target, self::ENTRY_BEGIN)
            && str_contains($target, self::ENTRY_END);

        if (! $hasEntry) {
            $source = @file_get_contents($sourceIndex);
            if (! is_string($source)) {
                throw new RuntimeException('无法读取升级包 HTTP 入口');
            }

            $snippet = self::extractEntrySnippet($source);
            $anchorOffset = strpos($target, self::AUTOLOAD_ANCHOR);
            if ($anchorOffset === false) {
                throw new RuntimeException('当前 HTTP 入口缺少 Composer 加载锚点，无法安全注入启动锁');
            }

            self::writePreparationState($statePath, ['status' => 'preparing']);
            $patched = substr($target, 0, $anchorOffset).$snippet."\n\n".substr($target, $anchorOffset);
            self::atomicReplace($targetIndex, $patched);

            $startedAt = time();
            $state = [
                'status' => 'draining',
                'started_at' => $startedAt,
                'ready_at' => $startedAt + $drainSeconds,
            ];
            self::writePreparationState($statePath, $state);
        } elseif (($state['status'] ?? null) === 'preparing') {
            // 上次在入口原子替换后、写排空时间前中断：从现在重新完整排空。
            $startedAt = time();
            $state = [
                'status' => 'draining',
                'started_at' => $startedAt,
                'ready_at' => $startedAt + $drainSeconds,
            ];
            self::writePreparationState($statePath, $state);
        }

        // 入口原本就带锁且没有准备状态，说明来自新装或已原生具备该机制，无需排空。
        if (($state['status'] ?? null) !== 'draining') {
            return;
        }

        $remaining = max(0, (int) ($state['ready_at'] ?? time()) - time());
        if ($remaining > 0) {
            sleep($remaining);
        }
    }

    private static function extractEntrySnippet(string $source): string
    {
        $begin = strpos($source, self::ENTRY_BEGIN);
        $end = strpos($source, self::ENTRY_END);
        if ($begin === false || $end === false || $end < $begin) {
            throw new RuntimeException('升级包 HTTP 入口缺少启动锁片段');
        }

        return substr($source, $begin, $end + strlen(self::ENTRY_END) - $begin);
    }

    /** @return array<string, mixed> */
    private static function readPreparationState(string $path): array
    {
        $json = @file_get_contents($path);
        if (! is_string($json)) {
            return [];
        }

        $state = json_decode($json, true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private static function writePreparationState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::atomicReplace($path, $json."\n", 0664);
    }

    private static function atomicReplace(string $path, string $contents, ?int $mode = null): void
    {
        $directory = dirname($path);
        $temporary = $directory.'/.'.basename($path).'.upgrade-'.bin2hex(random_bytes(6));
        $existingMode = @fileperms($path);

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException("无法写入临时文件：{$temporary}");
            }
            @chmod($temporary, $mode ?? (is_int($existingMode) ? $existingMode & 0777 : 0644));
            if (! @rename($temporary, $path)) {
                throw new RuntimeException("无法原子替换文件：{$path}");
            }
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @param  resource|null  $handle
     */
    public static function release($handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

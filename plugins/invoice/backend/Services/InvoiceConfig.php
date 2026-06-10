<?php

declare(strict_types=1);

namespace Plugins\Invoice\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class InvoiceConfig
{
    private const FILE = 'private/invoice-external.json';

    private const ENCRYPTED_KEYS = ['external_token'];

    /**
     * 进程内缓存配置全量数据，避免每次 get/set 都读盘解析。
     * null 表示尚未加载（区别于"已加载但为空数组"）。
     */
    private static ?array $cache = null;

    /**
     * 重置进程内缓存（测试在 Storage::fake 重置磁盘后调用，
     * 或外部确需强制回源最新文件时调用）。
     */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::readAll();
        if (! array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];
        if (in_array($key, self::ENCRYPTED_KEYS, true) && is_string($value) && $value !== '') {
            return Crypt::decryptString($value);
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $data = self::readAll();
        if (in_array($key, self::ENCRYPTED_KEYS, true) && is_string($value) && $value !== '') {
            $value = Crypt::encryptString($value);
        }
        $data[$key] = $value;
        Storage::disk('local')->put(
            self::FILE,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        // 写盘后同步刷新缓存，使后续 get 立即可见新值
        self::$cache = $data;
    }

    private static function readAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (! Storage::disk('local')->exists(self::FILE)) {
            return self::$cache = [];
        }
        $raw = Storage::disk('local')->get(self::FILE);
        $data = json_decode($raw ?: '{}', true);

        return self::$cache = (is_array($data) ? $data : []);
    }
}

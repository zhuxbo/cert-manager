<?php

declare(strict_types=1);

namespace Plugins\Invoice\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class InvoiceConfig
{
    private const FILE = 'private/invoice-external.json';

    private const ENCRYPTED_KEYS = ['external_token'];

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
    }

    private static function readAll(): array
    {
        if (! Storage::disk('local')->exists(self::FILE)) {
            return [];
        }
        $raw = Storage::disk('local')->get(self::FILE);
        $data = json_decode($raw ?: '{}', true);

        return is_array($data) ? $data : [];
    }
}

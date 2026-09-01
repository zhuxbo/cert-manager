<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use RuntimeException;
use Throwable;

final class RestoreForeignKeyPlanStore
{
    private const FORMAT_VERSION = 1;

    /** @param array<string, array<string, mixed>> $activeForeignKeys */
    public function write(RestoreContext $context, array $activeForeignKeys): void
    {
        $path = $this->path($context);
        if (is_file($path)) {
            if ($this->load($context) !== $activeForeignKeys) {
                throw new RuntimeException('恢复外键计划已存在且内容不一致');
            }

            return;
        }

        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $payload = $this->payload($context, $activeForeignKeys);
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.part';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('无法创建恢复外键计划临时文件');
        }

        try {
            $this->writeAll($stream, $json);
            if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new RuntimeException('无法同步恢复外键计划临时文件');
            }
        } catch (Throwable $e) {
            @unlink($temporary);
            throw $e;
        } finally {
            fclose($stream);
        }

        try {
            if ($this->read($temporary, $context) !== $activeForeignKeys) {
                throw new RuntimeException('恢复外键计划临时文件回读不一致');
            }
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('无法原子发布恢复外键计划');
            }
            $this->syncDirectory($directory);
            if ($this->load($context) !== $activeForeignKeys) {
                throw new RuntimeException('恢复外键计划发布后回读不一致');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function load(RestoreContext $context): array
    {
        $path = $this->path($context);
        if (! is_file($path)) {
            throw new RuntimeException('恢复外键计划不存在');
        }

        return $this->read($path, $context);
    }

    public function exists(RestoreContext $context): bool
    {
        return is_file($this->path($context));
    }

    public function delete(RestoreContext $context): void
    {
        $path = $this->path($context);
        $directory = dirname($path);
        if (! is_file($path)) {
            @rmdir($directory);

            return;
        }
        if (! @unlink($path)) {
            throw new RuntimeException('无法删除恢复外键计划');
        }
        $this->syncDirectory($directory);
        @rmdir($directory);
    }

    /** @return array<string, array<string, mixed>> */
    private function read(string $path, RestoreContext $context): array
    {
        $json = @file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException('无法读取恢复外键计划');
        }
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('恢复外键计划校验失败', 0, $e);
        }
        if (! is_array($payload)) {
            throw new RuntimeException('恢复外键计划校验失败');
        }
        $checksum = $payload['checksum'] ?? null;
        unset($payload['checksum']);
        if (! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
            || ! hash_equals($checksum, $this->checksum($payload))) {
            throw new RuntimeException('恢复外键计划校验失败');
        }
        $foreignKeys = $payload['active_foreign_keys'] ?? null;
        if (($payload['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($payload['restore_token'] ?? null) !== $context->restoreToken
            || ($payload['artifact_sha256'] ?? null) !== $context->artifactSha256
            || ($payload['database'] ?? null) !== $this->databaseName()
            || ($payload['context_fingerprint'] ?? null) !== $this->contextFingerprint($context)
            || ($payload['swap_tables'] ?? null) !== $context->swapTables()
            || ! is_array($foreignKeys)) {
            throw new RuntimeException('恢复外键计划与当前恢复上下文不匹配');
        }

        return $foreignKeys;
    }

    /** @param array<string, array<string, mixed>> $activeForeignKeys @return array<string, mixed> */
    private function payload(RestoreContext $context, array $activeForeignKeys): array
    {
        $payload = [
            'format_version' => self::FORMAT_VERSION,
            'restore_token' => $context->restoreToken,
            'artifact_sha256' => $context->artifactSha256,
            'database' => $this->databaseName(),
            'context_fingerprint' => $this->contextFingerprint($context),
            'swap_tables' => $context->swapTables(),
            'active_foreign_keys' => $activeForeignKeys,
        ];
        $payload['checksum'] = $this->checksum($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function contextFingerprint(RestoreContext $context): string
    {
        return hash('sha256', json_encode([
            $context->restoreToken,
            $context->artifactSha256,
            $context->swapTables(),
            $context->shadowTableMap,
            $context->oldTableMap,
            $context->desiredForeignKeys,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('无法写入恢复外键计划临时文件');
            }
            $offset += $written;
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建恢复外键计划目录');
        }
        if (! is_writable($directory)) {
            throw new RuntimeException('恢复外键计划目录不可写');
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            throw new RuntimeException('当前 PHP 不支持恢复计划目录 fsync');
        }
        $stream = @fopen($directory, 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法打开恢复计划目录进行同步');
        }
        try {
            if (! @fsync($stream)) {
                throw new RuntimeException('无法同步恢复外键计划目录');
            }
        } finally {
            fclose($stream);
        }
    }

    private function path(RestoreContext $context): string
    {
        return storage_path('restores/'.$context->restoreToken.'.json');
    }

    private function databaseName(): string
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.$connection.database");
        if (! is_string($database) || $database === '') {
            throw new RuntimeException('无法确定恢复目标数据库');
        }

        return $database;
    }
}

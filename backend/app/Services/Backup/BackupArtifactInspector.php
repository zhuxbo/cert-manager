<?php

namespace App\Services\Backup;

use RuntimeException;

final class BackupArtifactInspector
{
    private const CHUNK_BYTES = 1048576;

    public function __construct(private readonly ?string $basePath = null) {}

    /**
     * @return array{
     *   id:string, sql_path:string, schema_path:?string, legacy:bool,
     *   schema:?array<string,mixed>, metadata:array<string,mixed>,
     *   integrity:array{verified:bool, compressed_bytes:int, sha256:string, gzip_eof?:bool, uncompressed_bytes?:int}
     * }
     */
    public function inspect(string $backupId, bool $verifyContents = true): array
    {
        if (! preg_match('/^[a-z_]+_\d{8}_\d{6}$/', $backupId)) {
            throw new RuntimeException("备份 ID 格式无效: $backupId");
        }

        $basePath = $this->basePath ?? storage_path('databak');
        $sqlPath = "$basePath/$backupId.sql.gz";
        $schemaPath = "$basePath/$backupId.schema.json";
        $hasSql = is_file($sqlPath);
        $hasSchema = is_file($schemaPath);
        $schema = $hasSchema ? $this->readSchema($schemaPath) : null;
        $hasMetadata = is_array($schema) && array_key_exists('backup_meta', $schema);
        $metadata = is_array($schema['backup_meta'] ?? null) ? $schema['backup_meta'] : [];
        $newFormat = $hasMetadata;

        if (! $hasSql) {
            if ($newFormat) {
                throw new RuntimeException("新版备份缺少最终 SQL 文件: $backupId");
            }

            throw new RuntimeException("备份 SQL 文件不存在: $backupId");
        }

        if ($newFormat) {
            if (($metadata['format_version'] ?? null) !== 2) {
                throw new RuntimeException("不支持的备份元数据格式: $backupId");
            }

            $integrity = $this->integrity($sqlPath, $verifyContents, verifyGzip: false);
            if ($verifyContents) {
                $this->assertNewFormatIntegrity($metadata, $integrity);
            }

            return [
                'id' => $backupId,
                'sql_path' => $sqlPath,
                'schema_path' => $schemaPath,
                'legacy' => false,
                'schema' => $schema,
                'metadata' => $metadata,
                'integrity' => $integrity,
            ];
        }

        $integrity = $this->integrity($sqlPath, $verifyContents);

        return [
            'id' => $backupId,
            'sql_path' => $sqlPath,
            'schema_path' => $hasSchema ? $schemaPath : null,
            'legacy' => true,
            'schema' => $schema,
            'metadata' => [
                'warning' => $hasSchema
                    ? '遗留备份 Schema 缺少 backup_meta，未执行元数据完整性校验。'
                    : '遗留备份缺少 Schema，未执行元数据完整性校验。',
            ],
            'integrity' => $integrity,
        ];
    }

    /** @return array<string, mixed> */
    private function readSchema(string $schemaPath): array
    {
        $contents = file_get_contents($schemaPath);
        $schema = $contents === false ? null : json_decode($contents, true);
        if (! is_array($schema)) {
            throw new RuntimeException("备份 Schema 无法解析: $schemaPath");
        }

        return $schema;
    }

    /** @return array{verified:bool, compressed_bytes:int, sha256:string, gzip_eof?:bool, uncompressed_bytes?:int} */
    private function integrity(string $sqlPath, bool $verifyContents, bool $verifyGzip = true): array
    {
        $compressedBytes = (int) filesize($sqlPath);
        if (! $verifyContents) {
            return [
                'verified' => false,
                'compressed_bytes' => $compressedBytes,
                'sha256' => '',
                'gzip_eof' => false,
            ];
        }

        $hash = hash_init('sha256');
        $stream = fopen($sqlPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("无法读取备份 SQL 文件: $sqlPath");
        }
        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK_BYTES);
            if ($chunk === false) {
                fclose($stream);
                throw new RuntimeException("读取备份 SQL 文件失败: $sqlPath");
            }
            hash_update($hash, $chunk);
        }
        fclose($stream);
        $integrity = [
            'verified' => true,
            'compressed_bytes' => $compressedBytes,
            'sha256' => hash_final($hash),
        ];
        if ($verifyGzip) {
            $integrity['uncompressed_bytes'] = $this->verifyGzip($sqlPath, $compressedBytes);
            $integrity['gzip_eof'] = true;
        }

        return $integrity;
    }

    private function verifyGzip(string $sqlPath, int $compressedBytes): int
    {
        if ($compressedBytes < 18) {
            throw new RuntimeException("gzip 文件截断或损坏: $sqlPath");
        }

        $error = null;
        set_error_handler(function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $gzip = gzopen($sqlPath, 'rb');
        } finally {
            restore_error_handler();
        }
        if ($gzip === false || $error !== null) {
            throw new RuntimeException("gzip 文件截断或损坏: $sqlPath");
        }

        $uncompressedBytes = 0;
        $crc = hash_init('crc32b');
        try {
            while (true) {
                $error = null;
                set_error_handler(function (int $severity, string $message) use (&$error): bool {
                    $error = $message;

                    return true;
                });
                try {
                    $decoded = gzread($gzip, self::CHUNK_BYTES);
                } finally {
                    restore_error_handler();
                }
                if ($decoded === false || $error !== null) {
                    throw new RuntimeException("gzip 文件截断或损坏: $sqlPath");
                }
                if ($decoded === '') {
                    if (! gzeof($gzip)) {
                        throw new RuntimeException("gzip 文件截断或损坏: $sqlPath");
                    }

                    break;
                }
                $uncompressedBytes += strlen($decoded);
                hash_update($crc, $decoded);
            }
        } finally {
            gzclose($gzip);
        }

        $trailerStream = fopen($sqlPath, 'rb');
        if ($trailerStream === false || fseek($trailerStream, -8, SEEK_END) !== 0) {
            if (is_resource($trailerStream)) {
                fclose($trailerStream);
            }
            throw new RuntimeException("gzip 文件截断或损坏: $sqlPath");
        }
        $trailer = fread($trailerStream, 8);
        fclose($trailerStream);
        $expected = $trailer === false ? false : unpack('Vcrc/Visize', $trailer);
        $actualCrc = (int) hexdec(hash_final($crc));
        if (! is_array($expected) ||
            $expected['crc'] !== $actualCrc ||
            $expected['isize'] !== ($uncompressedBytes & 0xFFFFFFFF)) {
            throw new RuntimeException("gzip 文件截断或损坏: $sqlPath");
        }

        return $uncompressedBytes;
    }

    /** @param array<string, mixed> $metadata */
    private function assertNewFormatIntegrity(array $metadata, array $integrity): void
    {
        $stream = $metadata['stream'] ?? null;
        if (! is_array($stream)) {
            throw new RuntimeException('新版备份元数据缺少 stream。');
        }

        if (! is_int($stream['compressed_bytes'] ?? null) ||
            $stream['compressed_bytes'] !== $integrity['compressed_bytes']) {
            throw new RuntimeException('备份压缩大小与元数据不符。');
        }
        if (! is_string($stream['sha256'] ?? null) ||
            ! hash_equals(strtolower($stream['sha256']), $integrity['sha256'])) {
            throw new RuntimeException('备份 SHA-256 与元数据不符。');
        }
        if (isset($stream['uncompressed_bytes']) && ! is_int($stream['uncompressed_bytes'])) {
            throw new RuntimeException('备份解压大小元数据无效。');
        }
    }
}

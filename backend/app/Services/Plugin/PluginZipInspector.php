<?php

namespace App\Services\Plugin;

use App\Services\Upgrade\ArchiveGuard;
use RuntimeException;
use ZipArchive;

class PluginZipInspector
{
    public function inspect(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException("ZIP 文件不存在: $zipPath");
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new RuntimeException("无法打开 ZIP 文件: 错误码 $result");
        }

        try {
            ArchiveGuard::assertSafeEntries($zip);
            $manifestEntry = $this->findManifestEntry($zip);
            $manifestContent = $zip->getFromName($manifestEntry);
            if (! is_string($manifestContent)) {
                throw new RuntimeException('无法读取 plugin.json');
            }

            $manifest = json_decode($manifestContent, true);
            if (! is_array($manifest)) {
                throw new RuntimeException('plugin.json 格式错误');
            }

            $name = $manifest['name'] ?? null;
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('plugin.json 缺少 name 字段');
            }

            return [
                'name' => $name,
                'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : null,
            ];
        } finally {
            $zip->close();
        }
    }

    private function findManifestEntry(ZipArchive $zip): string
    {
        $nestedManifest = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! is_string($name)) {
                continue;
            }

            $trimmed = trim($name, '/');
            if ($trimmed === 'plugin.json') {
                return $name;
            }

            if (preg_match('#^(?:[^/]+/){1,2}plugin\.json$#', $trimmed)) {
                $nestedManifest ??= $name;
            }
        }

        if ($nestedManifest !== null) {
            return $nestedManifest;
        }

        throw new RuntimeException('ZIP 包中未找到 plugin.json');
    }
}

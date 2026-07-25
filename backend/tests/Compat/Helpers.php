<?php

declare(strict_types=1);

namespace Tests\Compat;

/**
 * Compat 测试辅助函数集合。
 *
 * 全局函数 `expectsBreakingChange()` 由 tests/Pest.php 引入注册。
 *
 * 注意：fixture 路径规范化逻辑也在这里，capture 与 compare 必须一致。
 */
final class Helpers
{
    private const MAX_FIXTURE_FILE_NAME_BYTES = 255;

    /**
     * 把测试名规范化为 fixture 文件名。
     *
     * Pest 测试名形如：
     *   "P\Tests\Feature\Http\Controllers\Admin\AcmeControllerTest::__pest_evaluable_index_返回列表"
     *
     * 转换为：
     *   "Tests_Feature_Http_Controllers_Admin_AcmeControllerTest__pest_evaluable_index_返回列表.json"
     *
     * 保留中文（与 Pest Str::evaluable 一致），文件系统 UTF-8 即可。
     * 其他文件名非法字符（路径分隔符等）替换为 "_"。
     */
    public static function fixtureFileName(string $testName): string
    {
        // 去掉 Pest 包装的 P\ 前缀
        $name = preg_replace('/^P\\\\/', '', $testName) ?? $testName;

        // class::method → class__method
        $name = str_replace('::', '__', $name);

        // 反斜杠 → 下划线
        $name = str_replace('\\', '_', $name);

        // 文件名非法字符（POSIX path separator 等）→ "_"
        // 同时保留 ASCII 字母数字下划线 + 多字节字符（\x80-\xff，UTF-8 续字节）
        $name = preg_replace('/[^a-zA-Z0-9_\x80-\xff]/', '_', $name) ?? $name;

        // 多个连续下划线压成一个
        $name = preg_replace('/_+/', '_', $name) ?? $name;

        $name = trim($name, '_');
        $fileName = $name.'.json';
        if (strlen($fileName) <= self::MAX_FIXTURE_FILE_NAME_BYTES) {
            return $fileName;
        }

        $suffix = '_'.substr(hash('sha256', $name), 0, 16).'.json';
        $prefix = mb_strcut(
            $name,
            0,
            self::MAX_FIXTURE_FILE_NAME_BYTES - strlen($suffix),
            'UTF-8'
        );

        return rtrim($prefix, '_').$suffix;
    }

    /**
     * 返回 fixture 文件绝对路径。
     */
    public static function fixturePath(string $testName): string
    {
        return __DIR__.'/fixtures/'.self::fixtureFileName($testName);
    }

    /**
     * 是否启用 capture 模式（写 fixture）。
     *
     * 仅读 process env（不读 config），保证 phpunit boot 期/Pest 启动期都能识别。
     */
    public static function isCaptureMode(): bool
    {
        return getenv('COMPAT_CAPTURE') === 'true' || ($_ENV['COMPAT_CAPTURE'] ?? null) === 'true';
    }

    /**
     * 是否启用 compare 模式（读 fixture 并断言）。
     */
    public static function isCompareMode(): bool
    {
        return getenv('COMPAT_COMPARE') === 'true' || ($_ENV['COMPAT_COMPARE'] ?? null) === 'true';
    }
}

// 全局函数 expectsBreakingChange() 声明在独立的 GlobalFunctions.php（脱离 namespace），
// 由 Pest.php 自动 require。

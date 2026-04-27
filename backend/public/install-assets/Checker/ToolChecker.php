<?php

namespace Install\Checker;

use Install\DTO\CheckResult;

/**
 * 外部工具检查器（Composer、Java）
 */
class ToolChecker
{
    private bool $execEnabled;

    public function __construct()
    {
        $this->execEnabled = function_exists('exec')
            && ! in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
    }

    /**
     * 检查 Composer
     */
    public function checkComposer(): CheckResult
    {
        if (! $this->execEnabled) {
            return CheckResult::error(
                'Composer',
                'exec 函数不可用',
                'Composer 检测需要 exec 函数'
            );
        }

        exec('composer --version 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            return CheckResult::error(
                'Composer',
                '未安装或无法执行',
                'Composer 未安装或无法执行，请先安装 Composer'
            );
        }

        $version = $this->parseComposerVersion($output);

        // 检查版本是否低于 2.8（低版本会导致依赖解析或安装错误）
        if ($version && version_compare($version, '2.8.0', '<')) {
            return CheckResult::error(
                'Composer',
                "已安装 (版本: $version)",
                "Composer 版本 $version 过低，需要 2.8+（低版本会导致依赖安装错误），请运行 composer self-update 升级"
            );
        }

        return CheckResult::success(
            'Composer',
            "已安装 (版本: $version)"
        );
    }

    /**
     * 检查 Java
     */
    public function checkJava(): CheckResult
    {
        if (! $this->execEnabled) {
            return CheckResult::warning(
                'Java',
                '无法检测',
                'Java 检测需要 exec 函数'
            );
        }

        exec('java -version 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            return CheckResult::warning(
                'Java',
                'Java 不可用',
                '未检测到 Java 命令。如需使用 keytool 生成 JKS 格式证书，请安装 JDK 或 JRE'
            );
        }

        $version = $this->parseJavaVersion($output);

        // 检查版本是否低于 17
        if ($version) {
            preg_match('/^(\d+)/', $version, $matches);
            $majorVersion = (int) ($matches[1] ?? 0);

            if ($majorVersion < 17) {
                return CheckResult::warning(
                    'Java',
                    "已安装 (版本: $version)",
                    "Java 版本 $version 低于推荐版本 17，建议升级"
                );
            }
        }

        return CheckResult::success(
            'Java',
            "已安装 (版本: $version)"
        );
    }

    /**
     * 解析 Composer 版本
     */
    private function parseComposerVersion(array $output): ?string
    {
        $outputStr = implode("\n", $output);

        if (preg_match('/Composer version (\d+\.\d+(?:\.\d+)?)/', $outputStr, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 解析 Java 版本
     */
    private function parseJavaVersion(array $output): ?string
    {
        $outputStr = implode("\n", $output);

        if (preg_match('/(?:version |openjdk version )"([^"]+)"/', $outputStr, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 检查 Composer 是否可用
     */
    public function isComposerAvailable(): bool
    {
        return $this->checkComposer()->success;
    }

    /**
     * 检查 Java 是否可用
     */
    public function isJavaAvailable(): bool
    {
        $result = $this->checkJava();

        return $result->status !== CheckResult::STATUS_ERROR;
    }
}

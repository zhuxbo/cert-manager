<?php

namespace App\Services\Upgrade;

class EnvironmentChecker
{
    /** 后台升级失败时给用户的 remediation 引导文案 */
    public const REMEDIATION_HINT = '后台升级仅检测不修复 PHP 环境，请在服务器执行 bash upgrade.sh 由脚本自动处理';

    /**
     * 校验 PHP 运行环境是否满足 release zip 内 php-requirements.json 声明的需求。
     *
     * 返回 report 结构：
     * - ok: bool        所有 required 都满足
     * - skipped: bool   清单文件缺失/无效时跳过校验（向后兼容旧版本）
     * - php: {current, min, recommended, ok}
     * - extensions: {missing_required, missing_recommended}
     * - functions:  {disabled_required, disabled_recommended}
     */
    public function check(string $requirementsPath): array
    {
        if (! is_file($requirementsPath)) {
            return $this->skippedReport('requirements_file_missing');
        }

        $content = @file_get_contents($requirementsPath);
        $requirements = json_decode((string) $content, true);
        if (! is_array($requirements)) {
            return $this->skippedReport('requirements_file_invalid');
        }

        $report = [
            'ok' => true,
            'skipped' => false,
            'php' => [
                'current' => PHP_VERSION,
                'min' => $requirements['php_min'] ?? null,
                'recommended' => $requirements['php_recommended'] ?? null,
                'ok' => true,
            ],
            'extensions' => [
                'missing_required' => [],
                'missing_recommended' => [],
            ],
            'functions' => [
                'disabled_required' => [],
                'disabled_recommended' => [],
            ],
        ];

        if (! empty($requirements['php_min'])) {
            $report['php']['ok'] = version_compare(PHP_VERSION, $requirements['php_min'], '>=');
            if (! $report['php']['ok']) {
                $report['ok'] = false;
            }
        }

        foreach ($requirements['extensions']['required'] ?? [] as $ext) {
            if (! extension_loaded($ext)) {
                $report['extensions']['missing_required'][] = $ext;
                $report['ok'] = false;
            }
        }
        foreach ($requirements['extensions']['recommended'] ?? [] as $ext) {
            if (! extension_loaded($ext)) {
                $report['extensions']['missing_recommended'][] = $ext;
            }
        }

        // function_exists 对 disable_functions 中的函数也返回 false，一道判断即可
        foreach ($requirements['functions']['required'] ?? [] as $fn) {
            if (! function_exists($fn)) {
                $report['functions']['disabled_required'][] = $fn;
                $report['ok'] = false;
            }
        }
        foreach ($requirements['functions']['recommended'] ?? [] as $fn) {
            if (! function_exists($fn)) {
                $report['functions']['disabled_recommended'][] = $fn;
            }
        }

        return $report;
    }

    /**
     * 把 check() 返回值压扁为可写入 status.json 的失败 details（仅在 ok=false 时调用有意义）。
     * 数据字段与人类可读文案分别由 toDetails() 和 buildMessage() 产出，本方法负责组合。
     */
    public function summarize(array $report): array
    {
        $details = $this->toDetails($report);
        $details['message'] = $this->buildMessage($report, $details);
        $details['remediation'] = self::REMEDIATION_HINT;

        return $details;
    }

    /**
     * 提取结构化数据字段（不含文案），供前端渲染或日志记录使用。
     */
    protected function toDetails(array $report): array
    {
        return [
            'type' => 'php_environment',
            'current_php' => $report['php']['current'] ?? PHP_VERSION,
            'required_php' => $report['php']['min'] ?? null,
            'recommended_php' => $report['php']['recommended'] ?? null,
            'missing_extensions' => $report['extensions']['missing_required'] ?? [],
            'recommended_missing_extensions' => $report['extensions']['missing_recommended'] ?? [],
            'disabled_functions' => $report['functions']['disabled_required'] ?? [],
        ];
    }

    /**
     * 按 report 中的失败项拼出可读 message（PHP 版本 / 扩展 / 函数三段合并）。
     */
    protected function buildMessage(array $report, array $details): string
    {
        $msgs = [];
        if (! ($report['php']['ok'] ?? true)) {
            $msgs[] = "PHP 版本过低：当前 {$report['php']['current']}，需要 >= {$report['php']['min']}";
        }
        if (! empty($details['missing_extensions'])) {
            $msgs[] = '缺失必需扩展: '.implode(', ', $details['missing_extensions']);
        }
        if (! empty($details['disabled_functions'])) {
            $msgs[] = '必需函数被禁用: '.implode(', ', $details['disabled_functions']);
        }

        return $msgs ? implode('；', $msgs) : 'PHP 环境符合要求';
    }

    protected function skippedReport(string $reason): array
    {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => $reason,
            'php' => [
                'current' => PHP_VERSION,
                'min' => null,
                'recommended' => null,
                'ok' => true,
            ],
            'extensions' => ['missing_required' => [], 'missing_recommended' => []],
            'functions' => ['disabled_required' => [], 'disabled_recommended' => []],
        ];
    }
}

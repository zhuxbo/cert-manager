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
     * - extensions: {missing_required, missing_recommended, redis_dyn_required}
     * - functions:  {disabled_required, disabled_recommended}
     *
     * $envPath 用于探测 backend/.env 是否启用 redis（cache/queue），缺省回落到 base_path('.env')。
     * 测试可显式注入临时 .env 路径。
     */
    public function check(string $requirementsPath, ?string $envPath = null): array
    {
        if (! is_file($requirementsPath)) {
            return $this->skippedReport('requirements_file_missing');
        }

        $content = @file_get_contents($requirementsPath);
        $requirements = json_decode((string) $content, true);
        if (! is_array($requirements)) {
            return $this->skippedReport('requirements_file_invalid');
        }

        // 动态判定 redis 是否必装：与 deploy/upgrade.sh::_redis_required_from_env 对称
        // 任一为 redis：CACHE_DRIVER（项目实际读取）/ CACHE_STORE（L11+ 别名）/ QUEUE_CONNECTION
        $envPath ??= base_path('.env');
        $redisDynRequired = $this->isRedisRequiredFromEnv($envPath);

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
                'redis_dyn_required' => $redisDynRequired,
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

        // 动态必装：.env 启用 redis 且当前未加载 → 加入 missing_required
        // 防御性 in_array 去重：php-requirements.json 未来若把 redis 直接列到 required 也不会重复
        if ($redisDynRequired
            && ! extension_loaded('redis')
            && ! in_array('redis', $report['extensions']['missing_required'], true)) {
            $report['extensions']['missing_required'][] = 'redis';
            $report['ok'] = false;
        }

        foreach ($requirements['extensions']['recommended'] ?? [] as $ext) {
            // redis 已升级为必装则从推荐路径跳过，避免同一项被同时报为必需缺失 + 推荐缺失
            if ($ext === 'redis' && $redisDynRequired) {
                continue;
            }
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
     * 检查 backend/.env 的 cache/queue 配置是否启用 redis。
     *
     * 与 deploy/upgrade.sh::_redis_required_from_env 对称：扫描 CACHE_DRIVER / CACHE_STORE /
     * QUEUE_CONNECTION 三个 key，任一为 "redis" 即认为 redis 扩展必装。
     * 解析时处理引号、行内注释、CRLF、前后空白；.env 缺失/不可读时返回 false（fail-safe，不误报必装）。
     */
    public function isRedisRequiredFromEnv(string $envPath): bool
    {
        if (! is_file($envPath)) {
            return false;
        }
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return false;
        }

        $keys = ['CACHE_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION'];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (! preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            if (! in_array($m[1], $keys, true)) {
                continue;
            }
            $v = rtrim($m[2]);
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                $q = $v[0];
                $end = strpos($v, $q, 1);
                $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
            } else {
                $v = (string) preg_replace('/\s+#.*$/', '', $v);
                $parts = preg_split('/\s/', $v);
                $v = $parts[0] ?? '';
            }
            if (strtolower(trim($v)) === 'redis') {
                return true;
            }
        }

        return false;
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
            'redis_dyn_required' => $report['extensions']['redis_dyn_required'] ?? false,
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
            $extMsg = '缺失必需扩展: '.implode(', ', $details['missing_extensions']);
            // redis 是因 .env 启用 cache/queue redis 而临时升级为必装时，附加来源说明，
            // 便于运维定位（默认 php-requirements.json 里 redis 是 recommended）
            if (! empty($details['redis_dyn_required'])
                && in_array('redis', $details['missing_extensions'], true)) {
                $extMsg .= '（redis 因 backend/.env 中 cache/queue 启用 redis 列为必装）';
            }
            $msgs[] = $extMsg;
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
            'extensions' => [
                'missing_required' => [],
                'missing_recommended' => [],
                'redis_dyn_required' => false,
            ],
            'functions' => ['disabled_required' => [], 'disabled_recommended' => []],
        ];
    }
}

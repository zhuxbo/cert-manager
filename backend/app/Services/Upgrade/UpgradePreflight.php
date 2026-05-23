<?php

namespace App\Services\Upgrade;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;

/**
 * 升级 binary preflight：在执行升级前一次性跑 4 项检查（FPM ini / php / composer / CLI ini），
 * 任何一项不通过都计入 blocking，让运维一次看到所有问题再修，而不是修一个发现下一个。
 *
 * 检查顺序固定：①FPM ini → ②php → ③composer → ④CLI ini。
 * 即便 ② php 失败，③ composer 也会自然失败（composer 内部调 php），但仍会执行让用户看到完整列表。
 */
class UpgradePreflight
{
    public function __construct(protected BinaryLocator $locator) {}

    /**
     * 跑全部 binary preflight 检查。
     *
     * @return array{
     *     blocking: array<int, array{code: string, reason: string, fix: string}>,
     *     items: array<int, array{tool: string, status: string, path?: string, diagnose?: string[]}>,
     *     ini: array{
     *         fpm: array{disable_functions_ok: bool, ini_path: ?string},
     *         cli: array{disable_functions_ok: bool, ini_path: ?string},
     *     }
     * }
     */
    public function check(): array
    {
        try {
            return $this->doCheck();
        } catch (\Throwable $e) {
            // BinaryLocator 内部意外错误（非 BinaryNotFoundException），兜成 health_check_failed
            // 让 Controller 仍能返 503 + 友好错误，不冒泡成 500
            return [
                'blocking' => [[
                    'code' => 'health_check_failed',
                    'reason' => 'binary 健康检查自身异常: '.$e->getMessage(),
                    'fix' => '使用 upgrade.sh 升级',
                ]],
                'items' => [],
                'ini' => ['fpm' => [], 'cli' => []],
            ];
        }
    }

    protected function doCheck(): array
    {
        $blocking = [];
        $items = [];

        // inspectFpmIni 仅读当前进程 ini，必然不抛；inspectCliIni 起 CLI 子进程，依赖 php()，
        // 故推迟到 php() 解析成功后调用，避免 PHP 缺失时连锁失败
        $fpmIni = $this->locator->inspectFpmIni();

        // ① FPM disable_functions
        if (! ($fpmIni['disable_functions_ok'] ?? false)) {
            $blocking[] = [
                'code' => 'fpm_proc_open_disabled',
                'reason' => 'PHP-FPM disable_functions 已禁用 proc_open / exec，升级所需的子进程调用无法执行',
                'fix' => '在站点 PHP 配置（宝塔：网站 → 设置 → 配置文件）的 disable_functions 中移除 proc_open / exec，重启 php-fpm 生效',
            ];
        }

        // ② php CLI 探测
        $phpOk = false;
        try {
            $php = $this->locator->php();
            $items[] = ['tool' => 'php', 'status' => 'ok', 'path' => $php];
            $phpOk = true;
        } catch (BinaryNotFoundException $e) {
            $items[] = ['tool' => 'php', 'status' => 'missing', 'diagnose' => $e->diagnose()];
            $blocking[] = [
                'code' => 'php_cli_missing',
                'reason' => '未找到可执行的 PHP CLI: '.$e->getMessage(),
                'fix' => '使用 upgrade.sh 升级',
            ];
        }

        // ③ composer phar 探测（composer 内部依赖 php，php 失败时这里会自然抛 BinaryNotFoundException）
        try {
            $composer = $this->locator->composer();
            $items[] = ['tool' => 'composer', 'status' => 'ok', 'path' => $composer];
        } catch (BinaryNotFoundException $e) {
            $items[] = ['tool' => 'composer', 'status' => 'missing', 'diagnose' => $e->diagnose()];
            $blocking[] = [
                'code' => 'composer_missing',
                'reason' => '未找到 composer phar: '.$e->getMessage(),
                'fix' => '使用 upgrade.sh 升级',
            ];
        }

        // ④ CLI disable_functions（CLI 和 FPM 用不同的 ini 文件，宝塔尤其常见）
        // 依赖 php()，php 失败时用兜底字典跳过探测但仍报告 cli ini 阻塞
        $cliIni = $phpOk
            ? $this->locator->inspectCliIni()
            : ['ini_path' => null, 'disable_functions_ok' => false, 'error' => 'php_not_resolved'];

        if (! ($cliIni['disable_functions_ok'] ?? false)) {
            $blocking[] = [
                'code' => 'cli_proc_open_disabled',
                'reason' => $phpOk
                    ? 'PHP-CLI disable_functions 已禁用 proc_open / exec（CLI 和 FPM 用不同的 ini 文件），composer install 内部子进程调用会失败'
                    : '无法探测 CLI ini：PHP CLI 未解析成功',
                'fix' => $phpOk
                    ? '编辑 '.($cliIni['ini_path'] ?? '/www/server/php/{ver}/etc/php-cli.ini（或对应 CLI ini 文件）').'，从 disable_functions 中移除 proc_open / exec'
                    : '使用 upgrade.sh 升级',
            ];
        }

        return [
            'blocking' => $blocking,
            'items' => $items,
            'ini' => [
                'fpm' => [
                    'disable_functions_ok' => $fpmIni['disable_functions_ok'] ?? false,
                    'ini_path' => $fpmIni['ini_path'] ?? null,
                ],
                'cli' => [
                    'disable_functions_ok' => $cliIni['disable_functions_ok'] ?? false,
                    'ini_path' => $cliIni['ini_path'] ?? null,
                ],
            ],
        ];
    }
}

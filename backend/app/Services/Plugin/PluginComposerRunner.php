<?php

namespace App\Services\Plugin;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\UpgradePreflight;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 插件运行时 composer 安装器。
 *
 * 通用能力：当被安装/更新的插件自带 `backend/composer.json` 时，在插件目录内跑
 * `composer install --no-dev`，把第三方依赖（如 cloud-deploy 的阿里/腾讯官方 SDK）拉到
 * 插件自己的 `backend/vendor/`——使这些插件的 vendor **不必入 git / 不必随发布包分发**。
 *
 * 设计取舍：
 *   - 复用 {@see BinaryLocator::composer()}（escapeshellarg 过的 `php phar` 命令前缀，多版本 PHP 下
 *     不踩 shebang 选错）与 {@see UpgradePreflight}（composer phar + CLI proc_open 探测）。命令拼接
 *     全部字面量 + escapeshellarg，**绝不插值任何用户输入**（插件名已被 PluginManager 校验为
 *     `^[a-z][a-z0-9-]*$`，但这里仍只把已 escape 的绝对路径喂给 shell）。
 *   - 镜像自动切换逻辑（阿里云）与 UpgradeService 同源思路，独立实现以免 PluginManager ←→
 *     UpgradeService 互相耦合；两者共享的稳定底座是 BinaryLocator。
 *   - 失败 **抛 RuntimeException 携明确中文文案**（不静默）：preflight 不通过 / composer 找不到 /
 *     install 退出码非 0 都抛，由 PluginManager 的 install（清理半装目录）/ update（恢复备份）catch。
 *
 * 可测试性：exec 与镜像切换走 protected 方法，子类可覆盖断言命令构造、避免单测真跑 80M 下载。
 */
class PluginComposerRunner
{
    public function __construct(
        protected BinaryLocator $locator,
        protected UpgradePreflight $preflight,
    ) {}

    /**
     * 插件 backend 目录是否存在 composer.json（决定是否需要装依赖）。
     *
     * 无 composer.json 的插件（easy/invoice/notice/api-docs 等）整条 composer 路径跳过，
     * 安装/更新流程零影响——这是「不破坏现有插件」的硬保证。
     */
    public function pluginHasComposer(string $pluginDir): bool
    {
        return is_file($this->composerJsonPath($pluginDir));
    }

    /**
     * 读取插件 composer.lock 的 sha256（不存在返回空串）。
     *
     * 更新流程据此对比新旧 lock：未变则跳过 install（避免每次更新重拉 80M），变了才装。
     */
    public function lockHash(string $pluginDir): string
    {
        $lock = "$pluginDir/backend/composer.lock";

        return is_file($lock) ? hash_file('sha256', $lock) : '';
    }

    /**
     * 在插件 backend 目录内安装 composer 依赖。
     *
     * 前置：调用方应先 {@see pluginHasComposer()} 确认有 composer.json（无则不该调本方法）。
     * 流程：① preflight 探测（composer/CLI proc_open）不通过抛错 → ② 自动检测镜像 →
     *       ③ `composer install --no-dev --no-interaction --optimize-autoloader` → ④ 恢复镜像。
     *
     * library 插件无 root scripts，理论上 scripts 安全；仍加 `--no-scripts` 保守兜底
     * （FPM www 用户跑第三方 install 时不触发任何被装包的脚本，纵深防御）。
     *
     * @param  string  $pluginDir  插件根目录（其下 backend/composer.json 已就位）
     * @param  string  $name  插件名（仅用于日志）
     *
     * @throws RuntimeException preflight 不通过 / composer 不可用 / install 失败
     */
    public function install(string $pluginDir, string $name): void
    {
        $backendDir = "$pluginDir/backend";

        // ① 失败前置探测：composer phar / CLI proc_open 任一不可用 → 明确文案，不静默
        $this->assertComposerUsable($name);

        try {
            $composerCmd = $this->locator->composer();
        } catch (BinaryNotFoundException $e) {
            // 理论上被 assertComposerUsable 拦在前面；防御性兜底
            throw new RuntimeException(
                "插件 $name 依赖需要 composer，但未找到可执行的 composer：{$e->getMessage()}。".
                '请在服务器安装 composer（或解除 PHP disable_functions 对 proc_open / exec 的限制）后重试。'
            );
        }

        // ② 自动检测并切换镜像（GitHub 不可达时切阿里云）
        $mirrorConfigured = $this->configureComposerMirror($backendDir, $composerCmd);

        // ③ 安装依赖（命令全字面量 + 已 escape 的路径，无用户输入插值）
        $command = sprintf(
            'cd %s && %s install --no-dev --no-interaction --optimize-autoloader --no-scripts 2>&1',
            escapeshellarg($backendDir),
            $composerCmd
        );

        Log::info("[Plugin] composer install 开始: $name");
        [$exitCode, $output] = $this->runShell($command);

        // ④ 恢复镜像配置（无论成败都还原，避免污染插件 composer 配置）
        if ($mirrorConfigured) {
            $this->resetComposerMirror($backendDir, $composerCmd);
        }

        if ($exitCode !== 0) {
            Log::error("[Plugin] composer install 失败: $name (exit=$exitCode)", ['output' => $output]);
            throw new RuntimeException(
                "插件 {$name} 依赖安装失败（composer install 退出码 {$exitCode}）。".
                '请检查服务器网络连通性与 composer 可用性，详见后端日志。'
            );
        }

        Log::info("[Plugin] composer install 完成: $name");
    }

    /**
     * preflight 探测 composer 可用性，不通过抛携首条 blocking 文案的 RuntimeException。
     *
     * 复用 UpgradePreflight 的检查项，但只关心 composer 相关阻塞（composer 缺失 / CLI proc_open 被禁 /
     * php 缺失），FPM ini 的 proc_open 不阻断本路径（composer install 走 CLI 子进程）。
     */
    protected function assertComposerUsable(string $name): void
    {
        $report = $this->preflight->check();

        // 仅 composer install 真正需要的阻塞项（CLI 子进程相关）；FPM ini 不在此列
        $relevant = ['composer_missing', 'php_cli_missing', 'cli_proc_open_disabled', 'health_check_failed'];

        foreach ($report['blocking'] as $block) {
            if (in_array($block['code'] ?? '', $relevant, true)) {
                throw new RuntimeException(
                    "插件 $name 依赖需要 composer，但环境检测未通过：{$block['reason']}。修复建议：{$block['fix']}。"
                );
            }
        }
    }

    /**
     * composer.json 绝对路径。
     */
    protected function composerJsonPath(string $pluginDir): string
    {
        return "$pluginDir/backend/composer.json";
    }

    /**
     * 执行 shell 命令，返回 [exitCode, outputString]。独立成方法便于测试覆盖（避免真跑 composer）。
     *
     * @return array{0:int,1:string}
     */
    protected function runShell(string $command): array
    {
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /**
     * 检测网络并配置 composer 镜像（GitHub 不可达时切阿里云）。返回是否已切换。
     *
     * 与 UpgradeService::configureComposerMirror 同策略：FORCE_CHINA_MIRROR 环境变量优先，
     * 否则探 api.github.com 可达性。
     */
    protected function configureComposerMirror(string $basePath, string $composerCmd): bool
    {
        $forceMirror = getenv('FORCE_CHINA_MIRROR');
        if ($forceMirror !== false) {
            if ($forceMirror === '0') {
                return false;
            }
            if ($forceMirror === '1') {
                return $this->setAliyunMirror($basePath, $composerCmd);
            }
        }

        if ($this->checkNetworkAccess('https://api.github.com', 3)) {
            return false;
        }

        return $this->setAliyunMirror($basePath, $composerCmd);
    }

    /**
     * 设置阿里云 composer 镜像（仅作用于该插件目录的 composer 配置）。
     */
    protected function setAliyunMirror(string $basePath, string $composerCmd): bool
    {
        $configCmd = sprintf(
            'cd %s && %s config repo.packagist composer https://mirrors.aliyun.com/composer/ 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );
        [$exitCode] = $this->runShell($configCmd);

        if ($exitCode === 0) {
            Log::info('[Plugin] 已切换阿里云 composer 镜像');

            return true;
        }

        Log::warning('[Plugin] 切换 composer 镜像失败，使用默认源');

        return false;
    }

    /**
     * 还原阿里云镜像配置（unset 该插件目录的 repo.packagist）。
     */
    protected function resetComposerMirror(string $basePath, string $composerCmd): void
    {
        $resetCmd = sprintf(
            'cd %s && %s config --unset repo.packagist 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );
        $this->runShell($resetCmd);
    }

    /**
     * 探测网络可达性（HEAD 请求）。独立成方法便于测试覆盖。
     */
    protected function checkNetworkAccess(string $url, int $timeout = 3): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }
}

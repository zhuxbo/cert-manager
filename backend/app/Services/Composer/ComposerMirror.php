<?php

namespace App\Services\Composer;

/**
 * Composer 阿里云镜像的共享纯逻辑（无副作用：不执行 shell、不记日志）。
 *
 * 背景：`UpgradeService` 与 `PluginComposerRunner` 各自维护一份"切换 / 还原阿里云
 * composer 镜像"的逻辑，shell 命令字符串与网络探测逐字相同，但**执行机制与日志前缀不同**
 * （UpgradeService 用裸 `exec` + `[Upgrade]` 前缀；PluginComposerRunner 用自己的 `runShell()`
 * + `[Plugin]` 前缀）。
 *
 * 取舍：本类只承载两边**逐字相同**的部分 ——
 *   - {@see setAliyunCommand()} / {@see resetCommand()}：纯 `sprintf` 命令字符串构造（含
 *     `escapeshellarg` 同款 escape），不执行；调用方拿到字符串后用**各自原有的执行器**跑。
 *   - {@see networkReachable()}：curl HEAD 探测，无副作用纯函数。
 *
 * **刻意不抽** `configureComposerMirror()`（决策含 UpgradeService 独有的 `FORCE_CHINA_MIRROR`
 * 分支与日志，PluginComposerRunner 的日志又不同）与完整的 `setAliyunMirror()`/`resetComposerMirror()`
 * （二者把执行器 + 日志文案与命令字符串耦合在一起，抽走需引入 `callable $runShell` 间接层并合并
 * 两套不同日志，反而增加耦合）。保留在各自原类，保证 shell 执行机制 / escape / 日志一字不变。
 */
class ComposerMirror
{
    /**
     * 构造"切换阿里云镜像"的 composer 命令字符串（不执行）。
     *
     * 与抽取前两处逐字相同：`config repo.packagist composer https://mirrors.aliyun.com/composer/`。
     *
     * @param  string  $basePath  composer 配置作用目录（其下生成 composer 配置）
     * @param  string  $composerCmd  已由 BinaryLocator escape 过的 composer 命令前缀
     */
    public function setAliyunCommand(string $basePath, string $composerCmd): string
    {
        return sprintf(
            'cd %s && %s config repo.packagist composer https://mirrors.aliyun.com/composer/ 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );
    }

    /**
     * 构造"还原镜像配置"的 composer 命令字符串（不执行）。
     *
     * 与抽取前两处逐字相同：`config --unset repo.packagist`。
     *
     * @param  string  $basePath  composer 配置作用目录
     * @param  string  $composerCmd  已由 BinaryLocator escape 过的 composer 命令前缀
     */
    public function resetCommand(string $basePath, string $composerCmd): string
    {
        return sprintf(
            'cd %s && %s config --unset repo.packagist 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );
    }

    /**
     * 探测网络可达性（curl HEAD 请求）。无副作用，两处抽取前逐字相同。
     */
    public function networkReachable(string $url, int $timeout = 3): bool
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

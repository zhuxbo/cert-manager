<?php

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\Config;

class VersionManager
{
    /**
     * 获取版本配置文件的路径
     */
    public function getVersionPath(): string
    {
        $rootDir = dirname(base_path());
        $rootPath = "$rootDir/version.json";
        $backendPath = base_path('version.json');

        // 优先检查项目根目录
        if (file_exists($rootPath)) {
            return $rootPath;
        }

        // 回退到 backend 目录
        if (file_exists($backendPath)) {
            return $backendPath;
        }

        // 默认使用项目根目录（用于新建）
        return is_writable($rootDir) ? $rootPath : $backendPath;
    }

    /**
     * 从 version.json 读取版本配置（实时读取，避免缓存问题）
     */
    protected function getVersionJson(): array
    {
        $paths = [
            dirname(base_path()).'/version.json',  // 项目根目录
            base_path('version.json'),             // backend 目录
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $config = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $config;
                }
            }
        }

        return [];
    }

    /**
     * 获取当前版本信息
     */
    public function getCurrentVersion(): array
    {
        $configJson = $this->getVersionJson();

        return [
            'version' => $configJson['version'] ?? Config::get('version.version', '0.0.0'),
            'name' => Config::get('version.name', 'SSL Manager'),
            'build_time' => $configJson['build_time'] ?? Config::get('version.build_time', ''),
            'build_commit' => Config::get('version.build_commit', ''),
            'channel' => $configJson['channel'] ?? Config::get('version.channel', 'main'),
        ];
    }

    /**
     * 获取当前版本号
     */
    public function getVersionString(): string
    {
        $configJson = $this->getVersionJson();

        return $configJson['version'] ?? Config::get('version.version', '0.0.0');
    }

    /**
     * 获取当前发布通道
     */
    public function getChannel(): string
    {
        $configJson = $this->getVersionJson();

        return $configJson['channel'] ?? Config::get('version.channel', 'main');
    }

    /**
     * 获取自定义 release 服务 URL
     * 如果配置了此字段，将使用 local provider 模式
     */
    public function getReleaseUrl(): ?string
    {
        $config = $this->getVersionJson();

        return $config['release_url'] ?? null;
    }

    /**
     * 设置发布通道
     */
    public function setChannel(string $channel): bool
    {
        if (! in_array($channel, ['main', 'dev'])) {
            return false;
        }

        $versionPath = $this->getVersionPath();
        $existingConfig = $this->getVersionJson();

        if (empty($existingConfig)) {
            // 如果没有现有配置，创建新的
            $config = [
                'version' => $this->getVersionString(),
                'channel' => $channel,
                'build_time' => Config::get('version.build_time', ''),
            ];
        } else {
            $config = $existingConfig;
            $config['channel'] = $channel;
        }

        $result = file_put_contents(
            $versionPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($result !== false) {
            // 清除配置缓存
            Config::set('version.channel', $channel);
        }

        return $result !== false;
    }

    /**
     * 比较两个语义化版本
     *
     * 走 PHP 原生 version_compare 处理 SemVer 语义：
     * - 数字段递增正确（beta.10 > beta.9）
     * - 关键字优先级 dev < alpha < beta < rc < 正式版
     *
     * 入口 strtolower 标准化：PHP version_compare 把大写关键字（如 `Beta`）当未知映射为 `#`
     * （排在 rc 之后），与 bash `tr '[:upper:]' '[:lower:]'` / TS `toLowerCase()` 行为不一致。
     * 这里小写化让四处实现（PHP VersionManager / PHP ReleaseClient / TS / bash version_gt）
     * 对所有大小写组合输出一致。
     *
     * @return int -1 if v1 < v2, 0 if v1 == v2, 1 if v1 > v2
     */
    public function compareVersions(string $v1, string $v2): int
    {
        return version_compare(
            strtolower(ltrim($v1, 'vV')),
            strtolower(ltrim($v2, 'vV'))
        );
    }

    /**
     * 解析版本号
     */
    protected function parseVersion(string $version): array
    {
        // 匹配 1.0.0、1.0.0-dev、1.0.0-alpha.1 等格式
        if (preg_match('/^(\d+\.\d+\.\d+)(?:-(.+))?$/', $version, $matches)) {
            return [
                'version' => $matches[1],
                'prerelease' => $matches[2] ?? '',
            ];
        }

        return [
            'version' => $version,
            'prerelease' => '',
        ];
    }

    /**
     * 检查是否允许升级到目标版本
     */
    public function isUpgradeAllowed(string $targetVersion): bool
    {
        $currentVersion = $this->getVersionString();
        $allowDowngrade = Config::get('upgrade.constraints.allow_downgrade', false);

        // 比较版本
        $comparison = $this->compareVersions($targetVersion, $currentVersion);

        // 如果目标版本比当前版本旧，且不允许降级
        if ($comparison < 0 && ! $allowDowngrade) {
            return false;
        }

        // 如果版本相同，不需要升级
        if ($comparison === 0) {
            return false;
        }

        return true;
    }

    /**
     * 检查是否为连续版本升级（逐版本升级约束）
     *
     * @param  array  $availableVersions  可用版本列表（已按版本号排序）
     */
    public function isSequentialUpgrade(string $targetVersion, array $availableVersions): bool
    {
        $currentVersion = $this->getVersionString();
        $channel = $this->getChannel();

        // 过滤同通道的版本并排序
        $versions = $this->filterAndSortVersions($availableVersions, $channel, $currentVersion);

        if (empty($versions)) {
            return false;
        }

        // 获取下一个可升级版本
        $nextVersion = $versions[0] ?? null;

        if (! $nextVersion) {
            return false;
        }

        // 目标版本必须等于下一个版本
        $targetNormalized = ltrim($targetVersion, 'vV');
        $nextNormalized = ltrim($nextVersion, 'vV');

        return $this->compareVersions($targetNormalized, $nextNormalized) === 0;
    }

    /**
     * 获取下一个可升级版本
     *
     * @param  array  $availableVersions  可用版本列表
     */
    public function getNextUpgradeVersion(array $availableVersions): ?string
    {
        $currentVersion = $this->getVersionString();
        $channel = $this->getChannel();

        $versions = $this->filterAndSortVersions($availableVersions, $channel, $currentVersion);

        return $versions[0] ?? null;
    }

    /**
     * 过滤并排序版本列表
     * 返回比当前版本高的同通道版本，按版本号升序排列
     */
    protected function filterAndSortVersions(array $versions, string $channel, string $currentVersion): array
    {
        $filtered = [];

        foreach ($versions as $version) {
            $v = ltrim($version, 'vV');

            // 检查是否为同通道版本
            if (! $this->isSameChannel($v, $channel)) {
                continue;
            }

            // 只保留比当前版本高的版本
            if ($this->compareVersions($v, $currentVersion) > 0) {
                $filtered[] = $v;
            }
        }

        // 按版本号升序排序（从小到大）
        usort($filtered, fn ($a, $b) => $this->compareVersions($a, $b));

        return $filtered;
    }

    /**
     * 检查版本是否属于指定通道
     */
    protected function isSameChannel(string $version, string $channel): bool
    {
        $parts = $this->parseVersion($version);
        $isPreRelease = ! empty($parts['prerelease']);

        // dev 通道接受预发布版本，main 通道只接受正式版本
        return ($channel === 'dev') === $isPreRelease;
    }

    /**
     * 检查 PHP 版本是否满足最低要求
     */
    public function checkPhpVersion(): bool
    {
        $minVersion = Config::get('upgrade.constraints.min_php_version', '8.3.0');

        return version_compare(PHP_VERSION, $minVersion, '>=');
    }

    /**
     * 获取最低 PHP 版本要求
     */
    public function getMinPhpVersion(): string
    {
        return Config::get('upgrade.constraints.min_php_version', '8.3.0');
    }
}

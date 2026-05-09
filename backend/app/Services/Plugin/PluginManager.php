<?php

namespace App\Services\Plugin;

use App\Services\Upgrade\VersionManager;
use App\Traits\ResolvesExecutablePath;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class PluginManager
{
    use ResolvesExecutablePath;

    protected string $pluginsPath;

    protected string $downloadPath;

    public function __construct(
        protected VersionManager $versionManager,
    ) {
        $this->pluginsPath = base_path('../plugins');
        $this->downloadPath = Config::get('upgrade.package.download_path', storage_path('upgrades'));

        if (! File::isDirectory($this->downloadPath)) {
            File::makeDirectory($this->downloadPath, 0755, true);
        }
    }

    /**
     * 获取已安装插件列表
     */
    public function getInstalledPlugins(): array
    {
        $plugins = [];

        if (! is_dir($this->pluginsPath)) {
            return $plugins;
        }

        foreach (glob("$this->pluginsPath/*/plugin.json") as $manifestFile) {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (! $manifest) {
                continue;
            }

            $name = $manifest['name'] ?? basename(dirname($manifestFile));
            $plugins[] = [
                'name' => $name,
                'version' => $manifest['version'] ?? '0.0.0',
                'description' => $manifest['description'] ?? '',
                'release_url' => $manifest['release_url'] ?? '',
                'provider' => $manifest['provider'] ?? '',
            ];
        }

        return $plugins;
    }

    /**
     * 检查所有插件更新
     */
    public function checkUpdates(): array
    {
        $plugins = $this->getInstalledPlugins();
        $results = [];

        foreach ($plugins as $plugin) {
            $name = $plugin['name'];
            $currentVersion = $plugin['version'];

            try {
                $releases = $this->fetchPluginReleases($name);
                $latest = $this->findLatestRelease($releases);

                if ($latest && version_compare($latest['version'], $currentVersion, '>')) {
                    $results[] = [
                        'name' => $name,
                        'current_version' => $currentVersion,
                        'latest_version' => $latest['version'],
                        'has_update' => true,
                        'release_name' => $latest['name'] ?? '',
                        'release_body' => $latest['body'] ?? '',
                    ];
                } else {
                    $results[] = [
                        'name' => $name,
                        'current_version' => $currentVersion,
                        'latest_version' => $currentVersion,
                        'has_update' => false,
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'name' => $name,
                    'current_version' => $currentVersion,
                    'latest_version' => null,
                    'has_update' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * 远程安装插件
     */
    public function install(string $name, ?string $releaseUrl = null, ?string $version = null): array
    {
        $this->validatePluginName($name);

        // 检查是否已安装
        $pluginDir = "$this->pluginsPath/$name";
        if (is_dir($pluginDir) && file_exists("$pluginDir/plugin.json")) {
            throw new RuntimeException("插件 $name 已安装，请使用更新功能");
        }

        // 确定更新地址
        $resolvedUrl = $releaseUrl ?: $this->getSystemPluginUrl($name);
        if (! $resolvedUrl) {
            throw new RuntimeException('无法确定插件下载地址，请指定 release_url');
        }

        $this->validateReleaseUrl($resolvedUrl);

        // 获取远程版本信息
        $releases = $this->fetchRemoteReleases($resolvedUrl);
        $release = $version
        ? $this->findReleaseByVersion($releases, $version)
        : $this->findLatestRelease($releases);

        if (! $release) {
            throw new RuntimeException($version ? "未找到版本 $version" : '未找到可用版本');
        }

        // 检查兼容性
        $this->checkCompatibility($release);

        // 下载
        $downloadUrl = $this->resolveAssetUrl($release, $resolvedUrl);
        $zipPath = "$this->downloadPath/plugin-$name-{$release['version']}.zip";
        $this->downloadPlugin($downloadUrl, $zipPath);

        try {
            // 解压 → 验证 → 安装
            $extractDir = $this->extractPlugin($zipPath);
            $pluginSourceDir = $this->findPluginDir($extractDir, $name);
            $this->validatePlugin($pluginSourceDir, $name);

            // 写入 release_url
            if ($releaseUrl) {
                $this->updatePluginManifest($pluginSourceDir, ['release_url' => $releaseUrl]);
            }

            // 移动到 plugins 目录
            $this->applyPlugin($pluginSourceDir, $pluginDir);

            // 运行 migrate
            $this->runPluginMigrations($name);
            $this->runPluginSeeders($name);

            // 替换 nginx 占位符
            $this->replaceNginxPlaceholders("$pluginDir/nginx");

            // 清理缓存
            $this->clearCaches();

            $result = [
                'name' => $name,
                'version' => $release['version'],
                'message' => "插件 $name v{$release['version']} 安装成功",
            ];

            if ($this->hasNginxConfig("$pluginDir/nginx")) {
                $result['nginx_reload'] = true;
                $result['message'] .= '，请重载 Nginx 以使配置生效';
            }

            return $result;
        } finally {
            // 清理临时文件
            $this->cleanupTemp($zipPath, $extractDir ?? null);
        }
    }

    /**
     * 从上传的 ZIP 安装插件
     */
    public function installFromZip(string $zipPath): array
    {
        $extractDir = $this->extractPlugin($zipPath);

        try {
            // 查找插件目录（ZIP 内可能有一层根目录）
            $pluginSourceDir = $this->findPluginDirInExtract($extractDir);
            $manifest = json_decode(file_get_contents("$pluginSourceDir/plugin.json"), true);
            $name = $manifest['name'] ?? null;

            if (! $name) {
                throw new RuntimeException('plugin.json 缺少 name 字段');
            }

            $this->validatePluginName($name);
            $this->validatePlugin($pluginSourceDir, $name);

            // 检查是否已安装
            $pluginDir = "$this->pluginsPath/$name";
            if (is_dir($pluginDir) && file_exists("$pluginDir/plugin.json")) {
                throw new RuntimeException("插件 $name 已安装，请使用更新功能");
            }

            // 移动到 plugins 目录
            $this->applyPlugin($pluginSourceDir, $pluginDir);

            // 运行 migrate
            $this->runPluginMigrations($name);
            $this->runPluginSeeders($name);

            // 替换 nginx 占位符
            $this->replaceNginxPlaceholders("$pluginDir/nginx");

            // 清理缓存
            $this->clearCaches();

            $version = $manifest['version'] ?? '0.0.0';

            $result = [
                'name' => $name,
                'version' => $version,
                'message' => "插件 $name v$version 安装成功",
            ];

            if ($this->hasNginxConfig("$pluginDir/nginx")) {
                $result['nginx_reload'] = true;
                $result['message'] .= '，请重载 Nginx 以使配置生效';
            }

            return $result;
        } finally {
            $this->cleanupTemp(null, $extractDir);
        }
    }

    /**
     * 更新插件
     */
    public function update(string $name, ?string $version = null): array
    {
        $this->validatePluginName($name);

        $pluginDir = "$this->pluginsPath/$name";
        if (! is_dir($pluginDir) || ! file_exists("$pluginDir/plugin.json")) {
            throw new RuntimeException("插件 $name 未安装");
        }

        $currentManifest = json_decode(file_get_contents("$pluginDir/plugin.json"), true);
        $currentVersion = $currentManifest['version'] ?? '0.0.0';

        // 获取更新地址
        $releaseUrl = $this->getPluginReleaseUrl($name);
        if (! $releaseUrl) {
            throw new RuntimeException("插件 $name 无法确定更新地址");
        }

        // 获取远程版本
        $releases = $this->fetchRemoteReleases($releaseUrl);
        $release = $version
        ? $this->findReleaseByVersion($releases, $version)
        : $this->findLatestRelease($releases);

        if (! $release) {
            throw new RuntimeException($version ? "未找到版本 $version" : '未找到可用版本');
        }

        if (version_compare($release['version'], $currentVersion, '<=')) {
            throw new RuntimeException("当前版本 v$currentVersion 已是最新或更高版本");
        }

        // 检查兼容性
        $this->checkCompatibility($release);

        // 备份当前插件
        $backupDir = $this->backupPlugin($name);

        // 下载新版本
        $downloadUrl = $this->resolveAssetUrl($release, $releaseUrl);
        $zipPath = "$this->downloadPath/plugin-$name-{$release['version']}.zip";
        $this->downloadPlugin($downloadUrl, $zipPath);

        try {
            $extractDir = $this->extractPlugin($zipPath);
            $pluginSourceDir = $this->findPluginDir($extractDir, $name);
            $this->validatePlugin($pluginSourceDir, $name);

            // 保留原有 release_url
            $releaseUrlValue = $currentManifest['release_url'] ?? '';
            if ($releaseUrlValue) {
                $this->updatePluginManifest($pluginSourceDir, ['release_url' => $releaseUrlValue]);
            }

            // 删除旧版本 → 移入新版本（校验路径归属，防止 symlink 攻击）
            $this->validatePluginPath($pluginDir);
            File::deleteDirectory($pluginDir);
            $this->applyPlugin($pluginSourceDir, $pluginDir);

            // 运行 migrate（增量迁移）
            $this->runPluginMigrations($name);
            $this->runPluginSeeders($name);

            // 替换 nginx 占位符 + 清理缓存
            $this->replaceNginxPlaceholders("$pluginDir/nginx");
            $this->clearCaches();

            // 清理备份
            if ($backupDir) {
                File::deleteDirectory($backupDir);
            }

            $result = [
                'name' => $name,
                'from_version' => $currentVersion,
                'version' => $release['version'],
                'message' => "插件 $name 从 v$currentVersion 更新到 v{$release['version']} 成功",
            ];

            if ($this->hasNginxConfig("$pluginDir/nginx")) {
                $result['nginx_reload'] = true;
                $result['message'] .= '，请重载 Nginx 以使配置生效';
            }

            return $result;
        } catch (\Exception $e) {
            // 从备份恢复
            if ($backupDir && is_dir($backupDir)) {
                Log::warning("[Plugin] 更新失败，恢复备份: $name", ['error' => $e->getMessage()]);
                File::deleteDirectory($pluginDir);
                File::moveDirectory($backupDir, $pluginDir);
            }

            throw $e;
        } finally {
            $this->cleanupTemp($zipPath, $extractDir ?? null);
        }
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $name, bool $removeData = false): array
    {
        $this->validatePluginName($name);

        $pluginDir = "$this->pluginsPath/$name";
        if (! is_dir($pluginDir)) {
            throw new RuntimeException("插件 $name 不存在");
        }

        // 在删除前检查是否有 nginx 配置
        $hasNginx = $this->hasNginxConfig("$pluginDir/nginx");

        // 回滚迁移（删除数据库表）
        if ($removeData) {
            $this->rollbackPluginMigrations($name);
            $this->cleanupPluginSeeders($name);
        }

        // 删除插件目录（校验路径归属，防止 symlink 攻击）
        $this->validatePluginPath($pluginDir);
        File::deleteDirectory($pluginDir);

        // 清理缓存
        $this->clearCaches();

        $message = "插件 $name 已卸载".($removeData ? '（数据已清除）' : '（数据已保留）');

        $result = [
            'name' => $name,
            'remove_data' => $removeData,
            'message' => $message,
        ];

        if ($hasNginx) {
            $result['nginx_reload'] = true;
            $result['message'] .= '，请重载 Nginx';
        }

        return $result;
    }

    /**
     * 获取插件更新地址
     */
    public function getPluginReleaseUrl(string $name): ?string
    {
        $manifestFile = "$this->pluginsPath/$name/plugin.json";
        if (file_exists($manifestFile)) {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            $releaseUrl = $manifest['release_url'] ?? '';
            if ($releaseUrl) {
                return rtrim($releaseUrl, '/');
            }
        }

        return $this->getSystemPluginUrl($name);
    }

    /**
     * 获取主系统子目录的插件更新地址
     */
    protected function getSystemPluginUrl(string $name): ?string
    {
        $systemReleaseUrl = $this->versionManager->getReleaseUrl();
        if (! $systemReleaseUrl) {
            return null;
        }

        // plugins 目录与主系统目录同级，去掉 URL 最后一段路径
        $url = rtrim($systemReleaseUrl, '/');
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $parentPath = substr($path, 0, (int) strrpos($path, '/')) ?: '';
        $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        if (! empty($parsed['port'])) {
            $base .= ':'.$parsed['port'];
        }

        return "$base$parentPath/plugins/$name";
    }

    /**
     * 获取插件的远程 releases.json
     */
    public function fetchPluginReleases(string $name): array
    {
        $releaseUrl = $this->getPluginReleaseUrl($name);
        if (! $releaseUrl) {
            throw new RuntimeException("插件 $name 无法确定更新地址");
        }

        return $this->fetchRemoteReleases($releaseUrl);
    }

    /**
     * 从指定 URL 获取 releases.json
     */
    protected function fetchRemoteReleases(string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/').'/releases.json';

        try {
            $response = Http::timeout(10)->get($url);
            if ($response->successful()) {
                return $response->json()['releases'] ?? [];
            }

            throw new RuntimeException("HTTP {$response->status()}");
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new RuntimeException("获取版本信息失败: {$e->getMessage()}");
        }
    }

    /**
     * 下载插件包
     */
    protected function downloadPlugin(string $url, string $savePath): void
    {
        $timeout = Config::get('upgrade.package.download_timeout', 300);

        // 优先使用 curl
        if ($this->downloadWithCurl($url, $savePath, $timeout)) {
            return;
        }

        // 回退到 PHP HTTP
        try {
            $response = Http::timeout($timeout)
                ->withOptions(['sink' => $savePath])
                ->get($url);

            if ($response->successful() && file_exists($savePath)) {
                return;
            }

            throw new RuntimeException("HTTP {$response->status()}");
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new RuntimeException("下载失败: {$e->getMessage()}");
        }
    }

    /**
     * 使用 curl 下载
     */
    protected function downloadWithCurl(string $url, string $savePath, int $timeout): bool
    {
        $curlPath = $this->resolveExecutablePath('curl');
        if ($curlPath === null) {
            return false;
        }

        $command = sprintf(
            '%s -sL --max-time %s -o %s %s 2>&1',
            escapeshellarg($curlPath),
            escapeshellarg((string) $timeout),
            escapeshellarg($savePath),
            escapeshellarg($url),
        );

        exec($command, $output, $exitCode);

        return $exitCode === 0 && file_exists($savePath) && filesize($savePath) > 0;
    }

    /**
     * 解压插件包
     */
    protected function extractPlugin(string $zipPath): string
    {
        if (! file_exists($zipPath)) {
            throw new RuntimeException("文件不存在: $zipPath");
        }

        $extractDir = "$this->downloadPath/extract_".uniqid();
        File::makeDirectory($extractDir, 0755, true);

        $zip = new ZipArchive;
        $result = $zip->open($zipPath);

        if ($result !== true) {
            File::deleteDirectory($extractDir);
            throw new RuntimeException("无法打开 ZIP 文件: 错误码 $result");
        }

        // 解压前检查所有条目，防止路径遍历攻击
        for ($i = 0; $i < $zip->count(); $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || str_contains($entryName, '..') || str_starts_with($entryName, '/')) {
                $zip->close();
                File::deleteDirectory($extractDir);
                throw new RuntimeException('ZIP 包含非法路径');
            }
        }

        if (! $zip->extractTo($extractDir)) {
            $zip->close();
            File::deleteDirectory($extractDir);
            throw new RuntimeException('解压失败');
        }

        $zip->close();

        return $extractDir;
    }

    /**
     * 验证插件目录
     */
    protected function validatePlugin(string $path, ?string $expectedName = null): void
    {
        $manifestFile = "$path/plugin.json";
        if (! file_exists($manifestFile)) {
            throw new RuntimeException('插件包无效：缺少 plugin.json');
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (! $manifest) {
            throw new RuntimeException('plugin.json 格式错误');
        }

        if (empty($manifest['name'])) {
            throw new RuntimeException('plugin.json 缺少 name 字段');
        }

        if ($expectedName && $manifest['name'] !== $expectedName) {
            throw new RuntimeException("插件名不匹配：期望 {$expectedName}，实际 {$manifest['name']}");
        }

        // php_ext 扩展校验（插件可声明依赖的 PHP 扩展）
        // 空数组 / 缺字段 → 不强制要求扩展；非空数组 → 逐项 extension_loaded 校验
        if (isset($manifest['php_ext'])) {
            if (! is_array($manifest['php_ext'])) {
                throw new RuntimeException('plugin.json php_ext 字段必须是数组（如 ["redis", "intl"]）');
            }
            $missing = [];
            foreach ($manifest['php_ext'] as $ext) {
                if (! is_string($ext) || $ext === '') {
                    continue;
                }
                if (! extension_loaded($ext)) {
                    $missing[] = $ext;
                }
            }
            if (! empty($missing)) {
                $list = implode(', ', $missing);
                throw new RuntimeException("插件依赖的 PHP 扩展未加载: $list — 请联系运维启用扩展后再安装");
            }
        }

        // realpath 防路径遍历
        $realPath = realpath($path);
        $realDownloadPath = realpath($this->downloadPath) ?: $this->downloadPath;
        if ($realPath === false || ! str_starts_with($realPath, $realDownloadPath)) {
            // 也允许已安装到 pluginsPath 的路径
            $realPluginsPath = realpath($this->pluginsPath) ?: $this->pluginsPath;
            if ($realPath === false || ! str_starts_with($realPath, $realPluginsPath)) {
                throw new RuntimeException('无效的插件路径');
            }
        }
    }

    /**
     * 在解压目录中查找插件目录
     */
    protected function findPluginDir(string $extractDir, string $name): string
    {
        // 直接在解压目录下查找 plugin.json
        if (file_exists("$extractDir/$name/plugin.json")) {
            return "$extractDir/$name";
        }

        // 查找子目录
        foreach (File::directories($extractDir) as $dir) {
            if (file_exists("$dir/plugin.json")) {
                return $dir;
            }
            // 再深一层
            if (file_exists("$dir/$name/plugin.json")) {
                return "$dir/$name";
            }
        }

        throw new RuntimeException("在解压包中未找到插件 $name 的 plugin.json");
    }

    /**
     * 在解压目录中查找任意插件目录（上传安装场景）
     */
    protected function findPluginDirInExtract(string $extractDir): string
    {
        if (file_exists("$extractDir/plugin.json")) {
            return $extractDir;
        }

        foreach (File::directories($extractDir) as $dir) {
            if (file_exists("$dir/plugin.json")) {
                return $dir;
            }
        }

        throw new RuntimeException('ZIP 包中未找到 plugin.json');
    }

    /**
     * 移动插件到目标目录
     */
    protected function applyPlugin(string $from, string $to): void
    {
        if (! is_dir($this->pluginsPath)) {
            File::makeDirectory($this->pluginsPath, 0755, true);
        }

        File::moveDirectory($from, $to);
    }

    /**
     * 运行插件迁移
     */
    protected function runPluginMigrations(string $name): void
    {
        $migrationsPath = "plugins/$name/backend/migrations";
        $fullPath = base_path("../$migrationsPath");

        if (! is_dir($fullPath)) {
            return;
        }

        try {
            Artisan::call('migrate', [
                '--path' => "../$migrationsPath",
                '--force' => true,
            ]);
            Log::info("[Plugin] 迁移完成: $name");
        } catch (\Exception $e) {
            Log::warning("[Plugin] 迁移失败: $name - {$e->getMessage()}");
        }
    }

    /**
     * 回滚插件迁移
     */
    protected function rollbackPluginMigrations(string $name): void
    {
        $migrationsPath = "plugins/$name/backend/migrations";
        $fullPath = base_path("../$migrationsPath");

        if (! is_dir($fullPath)) {
            return;
        }

        // 收集迁移文件名（不含扩展名），用于清理 migrations 表记录
        $migrationNames = collect(File::files($fullPath))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->map(fn ($f) => $f->getFilenameWithoutExtension())
            ->values()
            ->all();

        try {
            Artisan::call('migrate:rollback', [
                '--path' => "../$migrationsPath",
                '--force' => true,
            ]);
            Log::info("[Plugin] 回滚迁移完成: $name");
        } catch (\Exception $e) {
            Log::warning("[Plugin] 回滚迁移失败: $name - {$e->getMessage()}");
        }

        // 确保 migrations 表记录被清理（防止 rollback 失败后残留，导致重装跳过迁移）
        if (! empty($migrationNames)) {
            $deleted = DB::table('migrations')->whereIn('migration', $migrationNames)->delete();
            if ($deleted > 0) {
                Log::info("[Plugin] 清理迁移记录: $name ($deleted 条)");
            }
        }
    }

    /**
     * 运行插件 Seeder（安装/升级后）
     */
    protected function runPluginSeeders(string $name): void
    {
        $class = $this->loadPluginSeederClass($name);
        if ($class === null) {
            return;
        }

        try {
            Artisan::call('db:seed', [
                '--class' => $class,
                '--force' => true,
            ]);
            Log::info("[Plugin] Seed 完成: $name ($class)");
        } catch (\Exception $e) {
            Log::warning("[Plugin] Seed 失败: $name - {$e->getMessage()}");
        }
    }

    /**
     * 清理插件 Seeder 产物（卸载并删除数据时）
     */
    protected function cleanupPluginSeeders(string $name): void
    {
        $class = $this->loadPluginSeederClass($name);
        if ($class === null) {
            return;
        }

        try {
            $seeder = app($class);
            if (method_exists($seeder, 'clear')) {
                // clear 为插件可选清理钩子，用于删除 Seed 产生的配置数据。
                $seeder->clear();
                Log::info("[Plugin] Seed 清理完成: $name ($class)");
            }
        } catch (\Exception $e) {
            Log::warning("[Plugin] Seed 清理失败: $name - {$e->getMessage()}");
        }
    }

    /**
     * 加载插件 Seeder 类，返回完整类名
     */
    protected function loadPluginSeederClass(string $name): ?string
    {
        $pluginClassName = Str::studly($name);
        $class = "Plugins\\$pluginClassName\\Seeders\\PluginSeeder";
        $paths = [
            base_path("../plugins/$name/backend/Seeders/PluginSeeder.php"),
            base_path("../plugins/$name/backend/seeders/PluginSeeder.php"),
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                require_once $path;
                if (class_exists($class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * 检查插件是否包含 nginx 配置文件
     */
    protected function hasNginxConfig(string $nginxDir): bool
    {
        if (! is_dir($nginxDir)) {
            return false;
        }

        foreach (File::allFiles($nginxDir) as $file) {
            if ($file->getExtension() === 'conf') {
                return true;
            }
        }

        return false;
    }

    /**
     * 替换 nginx 配置中的占位符
     */
    protected function replaceNginxPlaceholders(string $nginxDir): void
    {
        if (! is_dir($nginxDir)) {
            return;
        }

        $projectRoot = $this->getProjectRoot();

        foreach (File::allFiles($nginxDir) as $file) {
            if ($file->getExtension() === 'conf') {
                $content = File::get($file->getRealPath());
                $content = str_replace('__PROJECT_ROOT__', $projectRoot, $content);
                File::put($file->getRealPath(), $content);
            }
        }
    }

    /**
     * 获取项目根目录（仅支持宝塔部署）
     */
    protected function getProjectRoot(): string
    {
        return dirname(base_path());
    }

    /**
     * 清理缓存
     */
    protected function clearCaches(): void
    {
        try {
            Artisan::call('route:clear');
            Artisan::call('config:clear');

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
        } catch (\Exception $e) {
            Log::warning("[Plugin] 清理缓存部分失败: {$e->getMessage()}");
        }
    }

    /**
     * 备份插件目录
     */
    protected function backupPlugin(string $name): ?string
    {
        $pluginDir = "$this->pluginsPath/$name";
        if (! is_dir($pluginDir)) {
            return null;
        }

        $backupDir = "$this->downloadPath/plugin-backup-$name-".date('YmdHis');
        File::copyDirectory($pluginDir, $backupDir);
        Log::info("[Plugin] 备份完成: $name → $backupDir");

        return $backupDir;
    }

    /**
     * 验证路径确实在 pluginsPath 下（防止 symlink 攻击）
     */
    protected function validatePluginPath(string $path): void
    {
        $realPath = realpath($path);
        $realPluginsPath = realpath($this->pluginsPath);

        if ($realPath === false || $realPluginsPath === false) {
            throw new RuntimeException("无效的插件路径: $path");
        }

        if (! str_starts_with($realPath, $realPluginsPath.'/')) {
            throw new RuntimeException("插件路径不在允许范围内: $path");
        }
    }

    /**
     * 验证插件名
     */
    protected function validatePluginName(string $name): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new RuntimeException("无效的插件名: {$name}（仅允许小写字母、数字和连字符，以字母开头）");
        }
    }

    /**
     * 验证更新地址安全性
     */
    protected function validateReleaseUrl(string $url): void
    {
        if (str_starts_with($url, '/')) {
            return; // 本地路径
        }

        if (str_starts_with($url, 'https://')) {
            return; // HTTPS
        }

        if (str_starts_with($url, 'http://')) {
            return; // HTTP（允许内网场景）
        }

        throw new RuntimeException('不安全的更新地址，仅支持 HTTPS、HTTP 或本地路径');
    }

    /**
     * 找到 releases 中最新版本
     */
    protected function findLatestRelease(array $releases): ?array
    {
        $latest = null;
        $latestVersion = '0.0.0';

        foreach ($releases as $release) {
            $tagName = $release['tag_name'] ?? '';
            $version = ltrim($tagName, 'vV');

            if ($version && version_compare($version, $latestVersion, '>')) {
                $latestVersion = $version;
                $latest = $release;
                $latest['version'] = $version;
            }
        }

        return $latest;
    }

    /**
     * 根据版本号查找 release
     */
    protected function findReleaseByVersion(array $releases, string $version): ?array
    {
        $version = ltrim($version, 'vV');

        foreach ($releases as $release) {
            $tagName = $release['tag_name'] ?? '';
            $releaseVersion = ltrim($tagName, 'vV');
            if ($releaseVersion === $version) {
                $release['version'] = $releaseVersion;

                return $release;
            }
        }

        return null;
    }

    /**
     * 检查兼容性（requires 字段）
     */
    protected function checkCompatibility(array $release): void
    {
        $requires = $release['requires'] ?? null;
        if (! $requires) {
            return;
        }

        $systemVersion = $this->versionManager->getVersionString();

        // 解析 requires 格式：>=1.0.0
        if (preg_match('/^([><=!]+)(.+)$/', $requires, $matches)) {
            $operator = $matches[1];
            $requiredVersion = $matches[2];

            $allowedOps = ['<', '<=', '>', '>=', '==', '!='];
            if (! in_array($operator, $allowedOps, true)) {
                throw new RuntimeException("无效的版本约束: $requires");
            }

            if (! version_compare($systemVersion, $requiredVersion, $operator)) {
                throw new RuntimeException("该插件要求系统版本 {$requires}，当前版本 v{$systemVersion}，请先升级系统");
            }
        }
    }

    /**
     * 解析下载地址
     */
    protected function resolveAssetUrl(array $release, string $baseUrl): string
    {
        $assets = $release['assets'] ?? [];

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if (str_ends_with($name, '.zip')) {
                $url = $asset['browser_download_url'] ?? '';
                if ($url) {
                    // 相对路径转换为完整 URL
                    if (! str_starts_with($url, 'http')) {
                        $url = rtrim($baseUrl, '/').'/'.$url;
                    }

                    return $url;
                }
            }
        }

        // 构造默认 URL
        $tagName = $release['tag_name'] ?? 'v'.$release['version'];
        $version = $release['version'];
        $name = basename(rtrim($baseUrl, '/'));

        return rtrim($baseUrl, '/')."/$tagName/$name-plugin-$version.zip";
    }

    /**
     * 更新 plugin.json 中的字段
     */
    protected function updatePluginManifest(string $pluginDir, array $fields): void
    {
        $manifestFile = "$pluginDir/plugin.json";
        if (! file_exists($manifestFile)) {
            return;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true) ?: [];
        $manifest = array_merge($manifest, $fields);
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * 清理临时文件
     */
    protected function cleanupTemp(?string $zipPath, ?string $extractDir): void
    {
        if ($zipPath && file_exists($zipPath)) {
            @unlink($zipPath);
        }

        if ($extractDir && is_dir($extractDir) && str_contains($extractDir, 'extract_')) {
            File::deleteDirectory($extractDir);
        }
    }
}

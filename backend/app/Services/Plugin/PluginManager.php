<?php

namespace App\Services\Plugin;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\ArchiveGuard;
use App\Services\Upgrade\VersionManager;
use App\Support\ApplicationBootstrapLock;
use App\Support\Opcache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use ZipArchive;

class PluginManager
{
    protected string $pluginsPath;

    protected string $downloadPath;

    protected PluginComposerRunner $composerRunner;

    protected mixed $progressReporter = null;

    /**
     * @var array<string, string>
     */
    protected array $migrationMarkerPaths = [];

    /**
     * @param  PluginComposerRunner|null  $composerRunner  运行时 composer 安装器；为兼容存量单测
     *                                                     （`new PluginManager($vm)` 单参构造）默认 null，
     *                                                     首次使用时从容器解析
     */
    public function __construct(
        protected VersionManager $versionManager,
        ?PluginComposerRunner $composerRunner = null,
    ) {
        $this->pluginsPath = base_path('../plugins');
        $this->downloadPath = Config::get('upgrade.package.download_path', storage_path('upgrades'));
        $this->composerRunner = $composerRunner ?? app(PluginComposerRunner::class);

        if (! File::isDirectory($this->downloadPath)) {
            File::makeDirectory($this->downloadPath, 0755, true);
        }
    }

    public function withProgressReporter(callable $reporter): self
    {
        $clone = clone $this;
        $clone->progressReporter = $reporter;

        return $clone;
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
                    'error' => $this->safeError($e),
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
        $this->report('fetching_release', '正在获取插件版本信息...');
        $releases = $this->fetchRemoteReleases($resolvedUrl);
        $release = $version
        ? $this->findReleaseByVersion($releases, $version)
        : $this->findLatestRelease($releases);

        if (! $release) {
            throw new RuntimeException($version ? "未找到版本 $version" : '未找到可用版本');
        }

        // 检查兼容性
        $this->checkCompatibility($release);

        // 下载 + 完整性校验（有 sha256 则强校验，无则告警放行）
        $downloadUrl = $this->resolveAssetUrl($release, $resolvedUrl);
        $expectedSha256 = $this->findPluginAssetSha256($release);
        $zipPath = "$this->downloadPath/plugin-$name-{$release['version']}.zip";
        $this->report('downloading', '正在下载插件包...');
        $this->downloadPlugin($downloadUrl, $zipPath);
        $this->report('verifying', '正在校验插件包...');
        $this->verifyPluginPackageHash($zipPath, $expectedSha256);
        try {
            // 解压 → 验证 → 安装
            $this->report('extracting', '正在解压插件包...');
            $extractDir = $this->extractPlugin($zipPath);
            $pluginSourceDir = $this->findPluginDir($extractDir, $name);

            return $this->installExtractedPlugin(
                $pluginSourceDir,
                $name,
                $release['version'],
                $releaseUrl,
            );
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
        $this->report('extracting', '正在解压插件包...');
        $extractDir = $this->extractPlugin($zipPath);

        try {
            // 查找插件目录（ZIP 内可能有包装目录）
            $pluginSourceDir = $this->findPluginDirInExtract($extractDir);
            $manifest = json_decode(file_get_contents("$pluginSourceDir/plugin.json"), true);
            $name = $manifest['name'] ?? null;

            if (! $name) {
                throw new RuntimeException('plugin.json 缺少 name 字段');
            }

            $this->validatePluginName($name);

            $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';

            return $this->installExtractedPlugin($pluginSourceDir, $name, $version);
        } finally {
            $this->cleanupTemp(null, $extractDir);
        }
    }

    /**
     * 安装已解压的插件包。
     *
     * 在线安装与上传安装在各自完成取包后统一进入此流程，避免包校验、依赖安装、
     * 迁移与失败清理语义发生漂移。
     */
    protected function installExtractedPlugin(
        string $pluginSourceDir,
        string $name,
        string $version,
        ?string $releaseUrl = null,
    ): array {
        $this->report('validating', '正在校验插件包...');
        $this->validatePlugin($pluginSourceDir, $name);

        $manifest = json_decode(file_get_contents("$pluginSourceDir/plugin.json"), true);
        $this->checkCompatibility(is_array($manifest) ? $manifest : []);

        $pluginDir = "$this->pluginsPath/$name";
        if (is_dir($pluginDir) && file_exists("$pluginDir/plugin.json")) {
            throw new RuntimeException("插件 $name 已安装，请使用更新功能");
        }

        // 保留“已安装/不兼容”错误优先级，同时确保包内依赖在任何落盘修改前完成校验。
        $this->validateBundledPluginVendor($name, $pluginSourceDir);

        if ($releaseUrl) {
            $this->updatePluginManifest($pluginSourceDir, ['release_url' => $releaseUrl]);
        }

        $applied = false;
        $bootstrapLock = null;
        $migrationRecordsBefore = [];
        $migrationAttempted = false;

        try {
            $bootstrapLock = ApplicationBootstrapLock::acquireExclusive();
            $this->report('applying', '正在安装插件文件...');
            $this->applyPlugin($pluginSourceDir, $pluginDir);
            $applied = true;

            $this->installPluginComposerDeps($name, $pluginDir);

            $this->report('migrating', '正在运行插件迁移...');
            $migrationRecordsBefore = $this->pluginMigrationRecordNames($name);
            $migrationAttempted = true;
            $this->runPluginMigrations($name);
            $this->report('seeding', '正在运行插件初始化数据...');
            $this->runPluginSeeders($name);

            $this->replaceNginxPlaceholders("$pluginDir/nginx");

            $this->report('clearing_cache', '正在清理系统缓存...');
            $this->clearCaches();

            $result = [
                'name' => $name,
                'version' => $version,
                'message' => "插件 $name v$version 安装成功",
            ];

            if ($this->hasNginxConfig("$pluginDir/nginx")) {
                $result['nginx_reload'] = true;
                $result['message'] .= '，请重载 Nginx 以使配置生效';
            }

            $this->cleanupMigrationMarker($name);

            return $result;
        } catch (\Throwable $e) {
            $migrationsClean = true;
            if ($migrationAttempted) {
                $migrationsClean = $this->rollbackNewPluginMigrations($name, $migrationRecordsBefore);
            }

            if ($migrationsClean && $applied && is_dir($pluginDir)) {
                File::deleteDirectory($pluginDir);
            } elseif (! $migrationsClean) {
                $recoveryDir = $this->quarantinePluginDirectory($name, $pluginDir);
                Log::error("[Plugin] 迁移回滚失败，已隔离半装目录供人工恢复: $name", [
                    'recovery_dir' => $recoveryDir,
                ]);
            }

            throw $e;
        } finally {
            ApplicationBootstrapLock::release($bootstrapLock);
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
        $this->report('fetching_release', '正在获取插件版本信息...');
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

        // 旧版本 composer.lock 哈希（删旧版前抓取），用于更新后对比决定是否重装依赖
        $oldLockHash = $this->composerRunner->lockHash($pluginDir);

        // 旧 vendor 暂存路径（删旧目录前移出，lock 未变时移回复用，避免重拉大体量 vendor）
        $vendorStash = null;
        $bootstrapLock = null;
        $migrationRecordsBefore = [];
        $migrationAttempted = false;

        // 下载新版本
        $downloadUrl = $this->resolveAssetUrl($release, $releaseUrl);
        $expectedSha256 = $this->findPluginAssetSha256($release);
        $zipPath = "$this->downloadPath/plugin-$name-{$release['version']}.zip";
        $this->report('downloading', '正在下载插件包...');
        $this->downloadPlugin($downloadUrl, $zipPath);

        try {
            // 完整性校验（有 sha256 则强校验，无则告警放行）；放 try 内使失败时
            // 走下方 catch 恢复备份 + finally 清理临时文件，旧版本不受影响
            $this->report('verifying', '正在校验插件包...');
            $this->verifyPluginPackageHash($zipPath, $expectedSha256);

            $this->report('extracting', '正在解压插件包...');
            $extractDir = $this->extractPlugin($zipPath);
            $pluginSourceDir = $this->findPluginDir($extractDir, $name);
            $this->report('validating', '正在校验插件包...');
            $this->validatePlugin($pluginSourceDir, $name);
            $this->validateBundledPluginVendor($name, $pluginSourceDir);

            // 保留原有 release_url
            $releaseUrlValue = $currentManifest['release_url'] ?? '';
            if ($releaseUrlValue) {
                $this->updatePluginManifest($pluginSourceDir, ['release_url' => $releaseUrlValue]);
            }

            // 删除旧版本 → 移入新版本（校验路径归属，防止 symlink 攻击）
            $this->validatePluginPath($pluginDir);
            $bootstrapLock = ApplicationBootstrapLock::acquireExclusive();

            // 删目录前暂存旧 vendor：新包自带 vendor 时会丢弃该暂存；历史包仍可在
            // lock 未变时复用，避免无意丢失已安装依赖。
            $oldVendorDir = "$pluginDir/backend/vendor";
            if (is_dir($oldVendorDir)) {
                $vendorStash = "$this->downloadPath/vendor-stash-$name-".bin2hex(random_bytes(6));
                File::moveDirectory($oldVendorDir, $vendorStash);
            }

            File::deleteDirectory($pluginDir);
            $this->report('applying', '正在更新插件文件...');
            $this->applyPlugin($pluginSourceDir, $pluginDir);

            // 安装 composer 依赖：仅当插件自带 composer.json。
            // 新发布包携带完整 vendor 时直接使用；老包继续兼容“lock 未变复用旧
            // vendor / lock 变化运行 Composer”的历史路径。
            if ($this->composerRunner->pluginHasComposer($pluginDir)) {
                $newLockHash = $this->composerRunner->lockHash($pluginDir);
                $bundledVendorMatches = $this->composerRunner->bundledVendorMatchesLock($pluginDir);
                if (is_dir("$pluginDir/backend/vendor") && ! $bundledVendorMatches) {
                    throw new RuntimeException("插件 $name 的包内 vendor 与 composer.lock 不匹配");
                }
                if ($bundledVendorMatches) {
                    Log::info("[Plugin] 使用发布包内 vendor: $name");
                    if ($vendorStash && is_dir($vendorStash)) {
                        File::deleteDirectory($vendorStash);
                        $vendorStash = null;
                    }
                } elseif ($newLockHash === $oldLockHash && $vendorStash && is_dir($vendorStash)) {
                    Log::info("[Plugin] composer.lock 未变化，复用原 vendor: $name");
                    File::moveDirectory($vendorStash, "$pluginDir/backend/vendor");
                    $vendorStash = null;
                } else {
                    Log::info("[Plugin] 安装依赖（composer.lock 变化或 vendor 缺失）: $name");
                    $this->installPluginComposerDeps($name, $pluginDir);
                }
            }

            // 运行 migrate（增量迁移）
            $this->report('migrating', '正在运行插件迁移...');
            $migrationRecordsBefore = $this->pluginMigrationRecordNames($name);
            $migrationAttempted = true;
            $this->runPluginMigrations($name);
            $this->report('seeding', '正在运行插件初始化数据...');
            $this->runPluginSeeders($name);

            // 替换 nginx 占位符 + 清理缓存
            $this->replaceNginxPlaceholders("$pluginDir/nginx");
            $this->report('clearing_cache', '正在清理系统缓存...');
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

            // 流程成功终局：清理本次迁移 marker（失败路径的清理在 rollbackNewPluginMigrations 内）
            $this->cleanupMigrationMarker($name);

            return $result;
        } catch (\Throwable $e) {
            $migrationsClean = true;
            if ($migrationAttempted) {
                $migrationsClean = $this->rollbackNewPluginMigrations($name, $migrationRecordsBefore);
            }

            // 从备份恢复
            if ($backupDir && is_dir($backupDir)) {
                if (! $migrationsClean) {
                    $recoveryDir = $this->quarantinePluginDirectory($name, $pluginDir);
                    if ($recoveryDir !== null) {
                        if ($this->restorePluginBackup($backupDir, $pluginDir)) {
                            $backupDir = null;
                        }
                    }

                    Log::error("[Plugin] 更新失败且迁移回滚不干净，已隔离失败新目录并尽量恢复旧目录: $name", [
                        'error' => $this->safeError($e),
                        'recovery_dir' => $recoveryDir,
                        'backup_dir' => $backupDir,
                    ]);

                    throw $e;
                }

                Log::warning("[Plugin] 更新失败，恢复备份: $name", [
                    'error' => $this->safeError($e),
                    'migrations_clean' => $migrationsClean,
                ]);
                File::deleteDirectory($pluginDir);
                $this->restorePluginBackup($backupDir, $pluginDir);
            }

            throw $e;
        } finally {
            ApplicationBootstrapLock::release($bootstrapLock);
            $this->cleanupTemp($zipPath, $extractDir ?? null);
            // 清理未被复用的 vendor 暂存（lock 变化重装 / 异常恢复备份后，暂存即为冗余）
            if ($vendorStash && is_dir($vendorStash)) {
                File::deleteDirectory($vendorStash);
            }
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
        $this->validateReleaseUrl($baseUrl);
        $url = rtrim($baseUrl, '/').'/releases.json';

        try {
            $response = Http::timeout(10)
                ->withOptions([
                    'allow_redirects' => ['max' => 5, 'protocols' => ['https']],
                ])
                ->get($url);
            if ($response->successful()) {
                return $response->json()['releases'] ?? [];
            }

            throw new RuntimeException("HTTP {$response->status()}");
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new RuntimeException("获取版本信息失败: {$this->safeError($e)}");
        }
    }

    /**
     * 下载插件包
     */
    protected function downloadPlugin(string $url, string $savePath): void
    {
        // SSRF/传输层校验下沉到实际下载入口：releases.json 的 browser_download_url
        // 由 release 内容决定（可控/可被篡改），与基础 release_url 是不同的值，必须对
        // 最终下载 URL 再校验一次（对齐 ReleaseClient::downloadPackage 的入口校验），
        // 否则合法 https 基址返回的 release 可把下载地址指向 http://169.254.169.254 等内网。
        $this->validateReleaseUrl($url);

        $timeout = $this->progressReporter !== null
            ? (int) Config::get('plugin.download.timeout', 120)
            : (int) Config::get('upgrade.package.download_timeout', 300);
        $attemptTimeout = max(1, $timeout);

        // 优先使用 curl
        try {
            if ($this->downloadWithCurl($url, $savePath, $attemptTimeout)) {
                return;
            }
        } catch (RuntimeException $e) {
            Log::warning('curl 下载插件包失败，将回退到 HTTP 客户端', [
                'error' => $this->safeError($e),
            ]);
        }

        // curl 失败或超时可能留下半包，HTTP fallback 必须从空文件重新写入。
        File::delete($savePath);
        $this->downloadWithHttp($url, $savePath, $attemptTimeout);
    }

    /**
     * 使用 PHP HTTP 客户端下载
     */
    protected function downloadWithHttp(string $url, string $savePath, int $timeout): void
    {
        try {
            $response = Http::timeout($timeout)
                ->withOptions([
                    'sink' => $savePath,
                    // 与 curl 对称：重定向仅允许 https（防降级到 http 内网/元数据 SSRF），限 5 跳
                    'allow_redirects' => ['max' => 5, 'protocols' => ['https']],
                ])
                ->get($url);

            if ($response->successful() && file_exists($savePath) && filesize($savePath) > 0) {
                return;
            }

            throw new RuntimeException("HTTP {$response->status()}");
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new RuntimeException("下载失败: {$this->safeError($e)}");
        }
    }

    /**
     * 使用 curl 下载
     */
    protected function downloadWithCurl(string $url, string $savePath, int $timeout): bool
    {
        try {
            $curlPath = app(BinaryLocator::class)->curl();
        } catch (BinaryNotFoundException) {
            return false;
        }

        // -L 跟随重定向但收敛协议：初始仅 http/https，重定向仅允许 https，限 5 跳。
        // 防 file/gopher/dict 等 SSRF 协议，并堵“https 预校验通过 → 302 降级到 http 内网/元数据”绕过。
        $command = sprintf(
            '%s -sL --proto =http,https --proto-redir =https --max-redirs 5 --max-time %s -o %s %s 2>&1',
            escapeshellarg($curlPath),
            escapeshellarg((string) $timeout),
            escapeshellarg($savePath),
            escapeshellarg($url),
        );

        $process = Process::fromShellCommandline($command);
        // 让 curl 自己先按 --max-time 结束，Symfony 仅作为额外的失控保护。
        $process->setTimeout($timeout + 5);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $process->stop(1, 9);

            throw new RuntimeException('插件包下载超时，请检查服务器网络或调整 PLUGIN_DOWNLOAD_TIMEOUT');
        }

        return $process->isSuccessful() && file_exists($savePath) && filesize($savePath) > 0;
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

        // 解压前逐条目校验，防止路径遍历 / 符号链接攻击（与 BackupManager 共用 ArchiveGuard）
        try {
            ArchiveGuard::assertSafeEntries($zip);
        } catch (RuntimeException $e) {
            $zip->close();
            File::deleteDirectory($extractDir);
            throw $e;
        }

        $entryNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryNames[] = $zip->getNameIndex($i);
        }

        if (! $zip->extractTo($extractDir)) {
            $zip->close();
            File::deleteDirectory($extractDir);
            throw new RuntimeException('解压失败');
        }

        $zip->close();

        // 解压后断言产物落点仍在解压目录内（纵深兜底，含符号链接绕过）
        try {
            ArchiveGuard::assertExtractedWithin($extractDir, array_filter($entryNames, 'is_string'));
        } catch (RuntimeException $e) {
            File::deleteDirectory($extractDir);
            throw new RuntimeException('ZIP 包含非法路径');
        }

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

            foreach (File::directories($dir) as $nestedDir) {
                if (file_exists("$nestedDir/plugin.json")) {
                    return $nestedDir;
                }
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

        if (File::moveDirectory($from, $to)) {
            return;
        }

        if (File::copyDirectory($from, $to)) {
            File::deleteDirectory($from);

            return;
        }

        throw new RuntimeException("插件文件移动失败: $to");
    }

    /**
     * 安装插件 composer 依赖（首次安装路径）。
     *
     * 仅当插件自带 `backend/composer.json` 时执行；无 composer.json 的插件（easy/invoice/
     * notice/api-docs 等）整条 composer 路径跳过，完全不触碰 BinaryLocator——保证不破坏现有插件。
     * 失败抛 RuntimeException：被 install/installFromZip 的 catch 接住并清理本次落地的半装目录
     * （install 前置校验在 try 外、installFromZip 用 $applied 守卫，均只删本次新建目录），前端可见明确文案。
     */
    protected function installPluginComposerDeps(string $name, string $pluginDir): void
    {
        if (! $this->composerRunner->pluginHasComposer($pluginDir)) {
            return;
        }

        if ($this->composerRunner->bundledVendorMatchesLock($pluginDir)) {
            Log::info("[Plugin] 包内 vendor 已与 composer.lock 对齐: $name");

            return;
        }

        if (is_dir("$pluginDir/backend/vendor")) {
            throw new RuntimeException("插件 $name 的包内 vendor 与 composer.lock 不匹配");
        }

        if ($this->progressReporter === null) {
            $this->composerRunner->install($pluginDir, $name);

            return;
        }

        $this->composerRunner->install($pluginDir, $name, $this->progressReporter);
    }

    /**
     * 在安装目录被修改前校验插件包内的 Composer 依赖快照。
     */
    protected function validateBundledPluginVendor(string $name, string $pluginSourceDir): void
    {
        if (! $this->composerRunner->pluginHasComposer($pluginSourceDir)) {
            return;
        }

        $vendorDir = "$pluginSourceDir/backend/vendor";
        if (is_dir($vendorDir) && ! $this->composerRunner->bundledVendorMatchesLock($pluginSourceDir)) {
            throw new RuntimeException("插件 $name 的包内 vendor 与 composer.lock 不匹配");
        }
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
            $markerPath = $this->createMigrationMarkerPath($name);
            $this->runArtisanProcess([
                'plugin:migrate',
                $name,
                "--marker=$markerPath",
            ], '插件迁移');
            // marker 不在此清理，保留至安装/更新流程终局（三处成功 return 前）。迁移已提交
            // 但后续 seeder 等步骤失败时，marker 是精确回滚本次迁移的唯一凭据——外层调用方处于
            // 事务时，主连接快照看不到子进程已提交的 migrations 行，仅靠记录差集会漏回滚。
            Log::info("[Plugin] 迁移完成: $name");
        } catch (\Throwable $e) {
            Log::warning("[Plugin] 迁移失败: $name - {$this->safeError($e)}");
            throw new RuntimeException("插件 $name 迁移失败：{$e->getMessage()}");
        }
    }

    /**
     * 重置插件全部迁移。失败时必须中止卸载，避免删掉插件文件后留下无法清理的数据。
     */
    protected function rollbackPluginMigrations(string $name): void
    {
        $migrationsPath = "plugins/$name/backend/migrations";
        $fullPath = base_path("../$migrationsPath");

        if (! is_dir($fullPath)) {
            return;
        }

        try {
            $this->runArtisanProcess([
                'migrate:reset',
                "--path=../$migrationsPath",
                '--force',
            ], '插件迁移重置');
            Log::info("[Plugin] 重置迁移完成: $name");
        } catch (\Throwable $e) {
            $error = $this->safeError($e);
            Log::warning("[Plugin] 重置迁移失败: $name - $error");

            throw new RuntimeException("插件 $name 迁移重置失败：$error");
        }
    }

    /**
     * 当前已记录的插件迁移名。
     *
     * 用于安装/更新失败时只回滚本次新增的迁移，避免 seeder 失败但本次没有新迁移时
     * 误回滚该插件历史迁移。
     *
     * @return array<int, string>
     */
    protected function pluginMigrationRecordNames(string $name): array
    {
        $migrationNames = $this->pluginMigrationFileNames($name);
        if ($migrationNames === []) {
            return [];
        }

        return DB::table('migrations')
            ->whereIn('migration', $migrationNames)
            ->pluck('migration')
            ->all();
    }

    /**
     * 当前插件迁移文件名。
     *
     * @return array<int, string>
     */
    protected function pluginMigrationFileNames(string $name): array
    {
        $migrationsPath = base_path("../plugins/$name/backend/migrations");
        if (! is_dir($migrationsPath)) {
            return [];
        }

        return collect(File::files($migrationsPath))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->sort()
            ->values()
            ->all();
    }

    protected function createMigrationMarkerPath(string $name): string
    {
        $dir = storage_path('app/plugin-migration-markers');
        File::ensureDirectoryExists($dir);

        $path = $dir.'/'.$name.'-'.Str::uuid().'.json';
        $this->migrationMarkerPaths[$name] = $path;

        return $path;
    }

    /**
     * @return array<int, string>
     */
    protected function pluginMigrationAttemptedFiles(string $name): array
    {
        $path = $this->migrationMarkerPaths[$name] ?? null;
        if (! is_string($path) || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['attempted']) || ! is_array($data['attempted'])) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $data['attempted']),
            fn ($migration) => preg_match('/^[A-Za-z0-9_]+$/', $migration)
        ));
    }

    protected function cleanupMigrationMarker(string $name): void
    {
        $path = $this->migrationMarkerPaths[$name] ?? null;
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }

        unset($this->migrationMarkerPaths[$name]);
    }

    /**
     * 回滚安装/更新过程中本次新增的插件迁移。
     *
     * @param  array<int, string>  $before
     */
    protected function rollbackNewPluginMigrations(string $name, array $before): bool
    {
        $after = $this->pluginMigrationRecordNames($name);
        $newRecords = array_values(array_diff($after, $before));
        $attemptedMigrationFiles = $this->pluginMigrationAttemptedFiles($name);
        $unrecordedMigrationFiles = array_values(array_diff($attemptedMigrationFiles, $before, $newRecords));
        $rollbackTargets = $this->orderedMigrationRollbackTargets($name, array_merge($newRecords, $unrecordedMigrationFiles));
        $clean = true;

        if ($rollbackTargets !== []) {
            Log::warning("[Plugin] 操作失败，精确回滚本次迁移: $name", [
                'recorded' => $newRecords,
                'unrecorded' => $unrecordedMigrationFiles,
            ]);

            try {
                $this->runArtisanProcess(array_merge(
                    ['plugin:rollback-unrecorded-migrations', $name],
                    array_map(fn ($migration) => "--migration=$migration", $rollbackTargets),
                ), '插件迁移精确回滚');
            } catch (\Throwable $e) {
                $clean = false;
                Log::warning("[Plugin] 精确回滚本次迁移失败: $name - {$this->safeError($e)}");
            }
        }

        if ($clean) {
            $this->cleanupMigrationMarker($name);
        }

        return $clean;
    }

    /**
     * @param  array<int, string>  $migrationNames
     * @return array<int, string>
     */
    protected function orderedMigrationRollbackTargets(string $name, array $migrationNames): array
    {
        $targetSet = array_flip(array_unique($migrationNames));

        return array_values(array_filter(
            $this->pluginMigrationFileNames($name),
            fn ($migration) => isset($targetSet[$migration])
        ));
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
            $this->runArtisanProcess([
                'plugin:seed-transaction',
                $class,
            ], '插件 Seed');
            Log::info("[Plugin] Seed 完成: $name ($class)");
        } catch (\Throwable $e) {
            Log::warning("[Plugin] Seed 失败: $name - {$this->safeError($e)}");
            throw new RuntimeException("插件 $name Seed 失败：{$e->getMessage()}");
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
        } catch (\Throwable $e) {
            $error = $this->safeError($e);
            Log::warning("[Plugin] Seed 清理失败: $name - $error");

            throw new RuntimeException("插件 $name Seed 清理失败：$error");
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
        } catch (\Exception $e) {
            Log::warning("[Plugin] 清理缓存部分失败: {$this->safeError($e)}");
        }

        // opcache 与 route/config 分开记账：opcache.restrict_api 受限时只是字节码缓存清不了，
        // route/config 其实都成功了，合并成一条"清理缓存部分失败"会把排障带偏。
        // 但分开 ≠ 不记：这里是 FPM 进程内、少数能真清掉线上字节码的位置，清不成必须留痕，
        // 否则 restrict_api 的机器上更新插件后字节码没换、全系统零痕迹。
        $opcache = app(Opcache::class);
        $result = $opcache->reset();
        if ($result['status'] !== Opcache::OK) {
            Log::notice("[Plugin] opcache: {$result['status']}", $result);
        }
        $opcache->reportFailure($result, 'plugin lifecycle clearCaches');
    }

    protected function report(string $stage, string $message): void
    {
        if ($this->progressReporter === null) {
            return;
        }

        try {
            ($this->progressReporter)($stage, $message);
        } catch (\Throwable $e) {
            Log::warning('[Plugin] progress reporter 失败', [
                'stage' => $stage,
                'error' => $this->safeError($e),
            ]);
        }
    }

    protected function safeError(\Throwable $e): string
    {
        return PluginOutputSanitizer::sanitize($e->getMessage(), 1000);
    }

    /**
     * 在独立 PHP 子进程中运行插件迁移 / Seeder，避免外层 Job timeout 硬杀 worker 时
     * 绕过 PluginManager 的 catch/finally 清理路径。
     *
     * @param  array<int, string>  $arguments
     */
    protected function runArtisanProcess(array $arguments, string $context): string
    {
        try {
            $php = app(BinaryLocator::class)->php();
        } catch (BinaryNotFoundException $e) {
            throw new RuntimeException("{$context}失败：未找到可执行的 PHP CLI：{$e->getMessage()}");
        }

        $process = new Process(
            array_merge([$php, base_path('artisan')], $arguments),
            base_path(),
            ['LARAVEL_STORAGE_PATH' => storage_path()],
        );
        $process->setTimeout((float) config('plugin.operations.artisan_timeout', 15));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $process->stop(1, 9);

            throw new RuntimeException("{$context}超时，请检查插件迁移/Seeder 或调整 PLUGIN_OPERATION_ARTISAN_TIMEOUT");
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        if (! $process->isSuccessful()) {
            throw new RuntimeException($output !== '' ? $output : "{$context}退出码 ".($process->getExitCode() ?? 1));
        }

        return $output;
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
        File::ensureDirectoryExists($backupDir);
        if (! File::copyDirectory($pluginDir, $backupDir)) {
            throw new RuntimeException("插件备份失败: $name");
        }
        Log::info("[Plugin] 备份完成: $name → $backupDir");

        return $backupDir;
    }

    protected function restorePluginBackup(string $backupDir, string $pluginDir): bool
    {
        if (File::moveDirectory($backupDir, $pluginDir)) {
            return true;
        }

        if (File::copyDirectory($backupDir, $pluginDir)) {
            File::deleteDirectory($backupDir);

            return true;
        }

        Log::error('[Plugin] 恢复插件备份失败', [
            'backup_dir' => $backupDir,
            'plugin_dir' => $pluginDir,
        ]);

        return false;
    }

    protected function quarantinePluginDirectory(string $name, string $pluginDir): ?string
    {
        if (! is_dir($pluginDir)) {
            return null;
        }

        $baseDir = storage_path('app/plugin-recovery');
        File::ensureDirectoryExists($baseDir);
        $recoveryDir = $baseDir.'/'.$name.'-'.date('YmdHis').'-'.Str::random(8);

        if (File::moveDirectory($pluginDir, $recoveryDir)) {
            return $recoveryDir;
        }

        if (File::copyDirectory($pluginDir, $recoveryDir)) {
            File::deleteDirectory($pluginDir);

            return $recoveryDir;
        }

        Log::error("[Plugin] 隔离失败插件目录失败: $name", [
            'plugin_dir' => $pluginDir,
            'recovery_dir' => $recoveryDir,
        ]);

        return null;
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
     * 验证插件更新地址的传输层安全性。
     *
     * 插件包是可执行代码载荷（供应链面）。release_url 由 Admin 任意指定，需双重收敛：
     *   1. 传输层：公网必须 https，明文 http 仅放行私网/保留地址（内网离线部署），
     *      与主系统 ReleaseClient::validateReleaseUrl 同策略 —— 否则公网 http 可被
     *      中间人替换插件包 → 条件性 RCE。
     *   2. SSRF：公网 http 指向私网/保留 IP（如 http://169.254.169.254 元数据服务、
     *      http://10.x 内网）一律拒绝。这里的"http 仅私网放行"恰好双关地堵住了
     *      公网→私网的 SSRF 取回（任何解析到私网的 http 才放行、解析到公网的 http 才拒绝）。
     *
     * 与 ReleaseClient 语义一致：https 全放行（含指向公网/私网的 https，TLS 已防篡改）；
     * http 只对私网/保留段放行。本地路径（以 / 开头）是官方子目录回落场景，放行。
     *
     * @throws RuntimeException 不安全地址（非法 scheme / 公网 http / 主机无法解析）
     */
    protected function validateReleaseUrl(string $url): void
    {
        if (str_starts_with($url, '/')) {
            return; // 本地路径（官方子目录回落）
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return; // HTTPS：TLS 防篡改，一律放行
        }

        if ($scheme !== 'http') {
            throw new RuntimeException('不安全的更新地址，仅支持 HTTPS、HTTP 或本地路径');
        }

        // http：仅放行 RFC1918 私网与 loopback（内网离线部署），其余一律拒绝
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            throw new RuntimeException('无效的更新地址：无法解析主机');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        // gethostbyname 解析失败时原样返回主机名 → 非法 IP → 拒绝
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("无效的更新地址：主机无法解析为 IP: {$host}");
        }

        // 明文 http 仅放行 RFC1918 私网（10/172.16/192.168）与 loopback（127/8、::1）；
        // 显式拒绝 link-local 169.254.0.0/16（含云元数据 169.254.169.254）、CGNAT、保留/多播等
        // 危险段，避免被诱导对内网/元数据发起 SSRF 取回。
        if (! $this->isPrivateOrLoopbackIp($ip)) {
            throw new RuntimeException('不安全的更新地址：明文 HTTP 仅限 RFC1918 私网或本机地址');
        }
    }

    /**
     * IP 是否为 RFC1918 私网或 loopback —— 明文 http 的唯一放行集合。
     *
     * 排除 link-local（169.254.0.0/16，含云元数据 169.254.169.254）、CGNAT（100.64/10）、
     * 0.0.0.0/8、多播等危险保留段，防 SSRF。注意 loopback（127/8、::1）在 PHP filter_var
     * 里归类为 reserved 而非 private，故单独放行。
     */
    protected function isPrivateOrLoopbackIp(string $ip): bool
    {
        if ($ip === '::1' || str_starts_with($ip, '127.')) {
            return true;
        }

        // FILTER_FLAG_NO_PRIV_RANGE 命中私网段时返回 false（被过滤），取反即“是私网”
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
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
     * 从 release 中提取插件包 asset 的 sha256（hex）。
     *
     * 选取与 resolveAssetUrl 完全一致的 asset（第一个 .zip），保证"校验的哈希"对应
     * "下载的文件"。缺失返回空字符串 —— 由调用方决定是否 fail-closed（当前为
     * verify-if-present：发布端尚未产出 sha256，缺失时不阻断安装，见 verifyPluginPackageHash）。
     */
    protected function findPluginAssetSha256(array $release): string
    {
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (str_ends_with($name, '.zip')) {
                return (string) ($asset['sha256'] ?? '');
            }
        }

        return '';
    }

    /**
     * 校验已下载插件包的 sha256（verify-if-present 语义）。
     *
     * 线协议与主系统 ReleaseClient::verifyPackageHash 一致：releases.json 的
     * assets[].sha256 为 hex 小写（python hashlib.hexdigest）；比对大小写无关、用
     * hash_equals 抗时序。不匹配即删文件并抛 RuntimeException（fail-closed）。
     *
     * 与 ReleaseClient 的差异：期望值为空时此处 **不** fail-closed，而是跳过校验并
     * 记 warning。原因：插件发布端（plugins/release-plugin.sh）历史上未在 releases.json
     * 写 sha256，强制 fail-closed 会让所有存量插件安装失败。一旦发布端补齐 sha256，
     * 本方法立即对其强校验 —— 即"有则强校验、无则放行并告警"，平滑收敛不破坏存量。
     *
     * @param  string  $expectedSha256  release asset 的 sha256（空 = 发布端未提供）
     *
     * @throws RuntimeException sha256 不匹配（已删除下载文件）
     */
    protected function verifyPluginPackageHash(string $path, string $expectedSha256): void
    {
        $expected = strtolower(trim($expectedSha256));

        if ($expected === '') {
            // 发布端未提供 sha256：放行但告警，提示供应链校验缺失（待发布端补齐）
            Log::warning('[Plugin] releases.json 未提供插件包 sha256，已跳过完整性校验（建议升级发布端以启用强校验）');

            return;
        }

        if (! file_exists($path)) {
            throw new RuntimeException("插件包不存在，无法校验 sha256: $path");
        }

        $actual = strtolower(hash_file('sha256', $path));

        if (! hash_equals($expected, $actual)) {
            @unlink($path);
            throw new RuntimeException(
                "插件包 sha256 校验不匹配，已中止安装并删除文件。期望: {$expected}，实际: {$actual}。".
                '下载内容可能在传输中被篡改。'
            );
        }

        Log::info("[Plugin] 插件包 sha256 校验通过: $expected");
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

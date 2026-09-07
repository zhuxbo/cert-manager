<?php

namespace App\Services\Upgrade;

use App\Services\Composer\ComposerVendorBundle;
use App\Services\Nginx\NginxRenderer;
use App\Support\ApplicationBootstrapLock;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

class PackageExtractor
{
    protected bool $appliedBundledVendor = false;

    private const RUNTIME_RELATIVE_DIRECTORIES = [
        'backend/bootstrap/cache',
        'backend/storage',
        'backend/storage/logs',
        'backend/storage/framework',
        'backend/storage/framework/cache/data',
        'backend/storage/framework/runtime-cache/data',
        'backend/storage/framework/sessions',
        'backend/storage/framework/views',
        'backend/storage/app/public',
        'backend/storage/app/private',
        'backups/upgrades',
    ];

    protected string $downloadPath;

    public function __construct()
    {
        $this->downloadPath = Config::get('upgrade.package.download_path', storage_path('upgrades'));

        // 确保下载目录存在
        if (! File::isDirectory($this->downloadPath)) {
            File::makeDirectory($this->downloadPath, 0755, true);
        }
    }

    /**
     * 解压升级包
     *
     * @return string 解压后的目录路径
     */
    public function extract(string $packagePath): string
    {
        if (! File::exists($packagePath)) {
            throw new RuntimeException("升级包不存在: $packagePath");
        }

        $extractDir = $this->downloadPath.'/extract_'.uniqid();
        File::makeDirectory($extractDir, 0755, true);

        $zip = new ZipArchive;
        $result = $zip->open($packagePath);

        if ($result !== true) {
            File::deleteDirectory($extractDir);
            throw new RuntimeException("无法打开升级包: 错误码 $result");
        }

        // 解压前逐条目校验，防止路径遍历 / 符号链接攻击（与 BackupManager/PluginManager 共用 ArchiveGuard）。
        // 升级包来自 release 站，结构为 version.json + backend/...，无 `..` 条目，故不传 allowExact。
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
            throw new RuntimeException('解压升级包失败');
        }

        $zip->close();

        // 解压后断言产物落点仍在解压目录内（纵深兜底，含符号链接绕过）
        try {
            ArchiveGuard::assertExtractedWithin($extractDir, array_filter($entryNames, 'is_string'));
        } catch (RuntimeException $e) {
            File::deleteDirectory($extractDir);
            throw new RuntimeException('升级包包含非法路径');
        }

        Log::info("升级包已解压到: $extractDir");

        return $extractDir;
    }

    /**
     * 验证升级包结构
     *
     * 注意：manifest.json 已弃用（build/scripts/package.sh 注释修订改用 release
     * 站 releases.json），版本/SHA256 来自 release 站；包内只保留 version.json 用于
     * applyUpgrade 的 updateVersionJsonWithPreservedFields。
     */
    public function validatePackage(string $extractedPath): bool
    {
        // 1. 必须能定位 backend 目录（升级核心载荷）
        $backendDir = $this->findBackendDir($extractedPath);
        if (! $backendDir) {
            throw new RuntimeException('升级包无效：缺少 backend 目录');
        }

        // 2. backend 关键子目录齐全
        $requiredDirs = ['app', 'config'];
        foreach ($requiredDirs as $dir) {
            if (! File::isDirectory("$backendDir/$dir")) {
                throw new RuntimeException("升级包无效：缺少 backend/$dir 目录");
            }
        }

        if (File::isDirectory("$backendDir/vendor")) {
            ComposerVendorBundle::assertMatchesLock($backendDir);
        }

        // 3. version.json 必须存在且含 version 字段（applyUpgrade 依赖）
        $versionFile = $this->findVersionConfig($extractedPath);
        if (! $versionFile) {
            throw new RuntimeException('升级包无效：缺少 version.json');
        }

        $versionConfig = json_decode(File::get($versionFile), true);
        if (! $versionConfig) {
            throw new RuntimeException('升级包无效：version.json 格式错误');
        }

        if (empty($versionConfig['version'])) {
            throw new RuntimeException('升级包无效：version.json 缺少 version 字段');
        }

        return true;
    }

    /**
     * 应用升级
     */
    public function applyUpgrade(string $extractedPath): bool
    {
        $this->validatePackage($extractedPath);

        // 升级前检查目标目录权限
        $this->checkWritableBeforeApply();

        try {
            // 应用后端更新
            $backendDir = $this->findBackendDir($extractedPath);
            if ($backendDir) {
                $this->applyBackendUpgrade($backendDir);
            }

            // 应用前端更新（管理端）
            $frontendAdminDir = $this->findFrontendDir($extractedPath, 'admin');
            if ($frontendAdminDir) {
                $this->applyFrontendUpgrade($frontendAdminDir, 'admin');
            }

            // 应用前端更新（用户端）
            $frontendUserDir = $this->findFrontendDir($extractedPath, 'user');
            if ($frontendUserDir) {
                $this->applyFrontendUpgrade($frontendUserDir, 'user');
            }

            // 应用 nginx 配置更新
            $nginxDir = $this->findNginxDir($extractedPath);
            if ($nginxDir) {
                $this->applyNginxUpgrade($nginxDir);
            }

            // 合并版本配置，但版本号保留到 UpgradeService 的最终更新步骤。
            $versionFile = $this->findVersionConfig($extractedPath);
            if ($versionFile) {
                $this->updateVersionJsonWithPreservedFields($versionFile);
            }

            // 清理缓存和临时文件
            $this->cleanupCacheFiles();

            Log::info('升级应用成功');

            return true;
        } catch (\Exception $e) {
            Log::error("升级应用失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 应用后端升级
     */
    protected function applyBackendUpgrade(string $sourceDir): void
    {
        $targetDir = base_path();
        $this->appliedBundledVendor = false;

        $vendorSource = "$sourceDir/vendor";
        $vendorTarget = "$targetDir/vendor";
        $stagedVendor = null;
        $bootstrapLock = null;
        try {
            if (File::isDirectory($vendorSource)) {
                ComposerVendorBundle::assertMatchesLock($sourceDir);

                if (ComposerVendorBundle::vendorMatchesLock($vendorTarget, "$sourceDir/composer.lock")) {
                    // 目标 lock 未变化时保留当前 vendor，避免无意义的大目录复制和切换窗口。
                    $this->appliedBundledVendor = true;
                    Log::info('[Upgrade] 当前 vendor 已与目标 composer.lock 对齐，跳过目录切换');
                } else {
                    // 大体量 vendor 必须在覆盖任何运行代码前完成复制和二次校验。
                    // 磁盘满、权限变化等失败应保持旧代码与旧 vendor 原样可用。
                    $stagedVendor = $this->stageBundledVendor($vendorSource, $vendorTarget, $sourceDir);
                }
            }

            $sourceIndex = "$sourceDir/public/index.php";
            $targetIndex = "$targetDir/public/index.php";
            if (File::exists($sourceIndex) && File::exists($targetIndex)) {
                ApplicationBootstrapLock::prepareLegacyHttpEntry(
                    $sourceIndex,
                    $targetIndex,
                    (int) config('plugin.upgrade.legacy_request_drain_timeout', 300)
                );
            }
            $bootstrapLock = ApplicationBootstrapLock::acquireExclusive();
            ApplicationBootstrapLock::completeLegacyHttpEntryPreparation($targetIndex);
            RedisDatabaseConfig::preserve(true);

            // 保护自定义 API 适配器：先备份
            $preservedApiAdapters = $this->preserveCustomApiAdapters($targetDir);

            // 动态发现 source 顶层目录逐个同步，替代硬编码白名单 —— 新增目录永不再漏
            // （曾因白名单漏 resources 导致对外 API 文档 yaml 不随升级更新 → 404）。
            // skip storage（运行时数据 + 升级状态 upgrade.lock/status，绝不能碰，且
            // syncDirectory 末尾的 removeEmptyDirectories 会误删其空目录）；vendor 单独处理。
            // 仍只覆盖不删除：本服务在被升级的代码内运行、不能全量删自身；旧版删除的文件
            // 残留无害（路由是显式白名单不扫目录），需彻底清理时用 upgrade.sh 全量升级。
            $skipDirs = ['storage', 'vendor'];
            foreach (File::directories($sourceDir) as $sourcePath) {
                $name = basename($sourcePath);
                if (in_array($name, $skipDirs, true)) {
                    continue;
                }
                $this->syncDirectory($sourcePath, "$targetDir/$name");
            }

            // bootstrap 同步会清理空目录；同时补齐存量安装可能缺失的 storage/backups 核心目录。
            // 只创建和验写目录，不清理或覆盖任何运行数据。
            $this->ensureRuntimeDirectories($targetDir);

            // 包内 vendor 已在覆盖代码前完整复制并校验，此处只做同文件系统目录切换。
            if ($stagedVendor !== null) {
                $this->activateStagedVendor($stagedVendor, $vendorTarget);
                $this->appliedBundledVendor = true;
            }

            // 同步根目录文件（artisan 之前遗漏，补齐；version.json 由 updateVersionJsonWithPreservedFields 单独处理）
            $rootFiles = ['artisan', 'composer.json', 'composer.lock', 'php-requirements.json'];
            foreach ($rootFiles as $file) {
                $sourceFile = "$sourceDir/$file";
                $targetFile = "$targetDir/$file";
                if (File::exists($sourceFile)) {
                    File::copy($sourceFile, $targetFile);
                }
            }

            // 恢复自定义 API 适配器
            $this->restoreCustomApiAdapters($targetDir, $preservedApiAdapters);
        } finally {
            if ($stagedVendor !== null && File::isDirectory($stagedVendor)) {
                File::deleteDirectory($stagedVendor);
            }
            ApplicationBootstrapLock::release($bootstrapLock);
        }
    }

    public function appliedBundledVendor(): bool
    {
        return $this->appliedBundledVendor;
    }

    protected function stageBundledVendor(string $source, string $target, string $sourceBackend): string
    {
        $suffix = bin2hex(random_bytes(6));
        $staged = dirname($target).'/.vendor-next-'.$suffix;
        File::deleteDirectory($staged);

        try {
            if (! File::copyDirectory($source, $staged)) {
                throw new RuntimeException('发布包 vendor 复制失败');
            }

            ComposerVendorBundle::assertVendorMatchesLock($staged, "$sourceBackend/composer.lock");

            return $staged;
        } catch (\Throwable $e) {
            File::deleteDirectory($staged);
            throw $e;
        }
    }

    protected function activateStagedVendor(string $staged, string $target): void
    {
        $suffix = bin2hex(random_bytes(6));
        $previous = dirname($target).'/.vendor-previous-'.$suffix;
        File::deleteDirectory($previous);
        try {
            if (File::isDirectory($target) && ! rename($target, $previous)) {
                throw new RuntimeException('旧 vendor 备份失败');
            }

            if (! rename($staged, $target)) {
                if (File::isDirectory($previous)) {
                    rename($previous, $target);
                }
                throw new RuntimeException('新 vendor 启用失败');
            }

            File::deleteDirectory($previous);
        } finally {
            File::deleteDirectory($staged);
            if (! File::isDirectory($target) && File::isDirectory($previous)) {
                rename($previous, $target);
            }
        }
    }

    /**
     * 需要保护的前端静态回落资源
     * 这些文件在升级时会被保留，不会被覆盖
     */
    protected array $protectedFrontendAssets = [
        'user' => ['logo.svg', 'qrcode.png', 'login.svg'],
    ];

    /**
     * API 适配器目录中的核心文件（升级时覆盖，不保留）
     * 包含核心入口、默认实现、接口契约文件 — 新增接口时显式登记到此处
     */
    protected array $coreApiAdapterFiles = [
        'Api.php',
        'default',
        'OrderSourceApiInterface.php',
        'AcmeSourceApiInterface.php',
    ];

    /**
     * 自定义 API 适配器扫描的目录列表（Order / Acme 对称）
     * key 用于备份归档命名空间，避免两个目录内同名子目录冲突
     */
    protected array $apiAdapterDirs = [
        'order' => 'app/Services/Order/Api',
        'acme' => 'app/Services/Acme/Api',
    ];

    /**
     * 判断是否为核心 API 适配器文件（升级时覆盖，不保留）
     */
    protected function isCoreApiAdapterFile(string $name): bool
    {
        return in_array($name, $this->coreApiAdapterFiles, true);
    }

    /**
     * 应用前端升级
     */
    protected function applyFrontendUpgrade(string $sourceDir, string $type): void
    {
        // 前端静态文件目标目录
        $targetDir = base_path("../frontend/$type");

        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        // 存量 platform-config.json 一次性暂存到 storage，供 SettingSeeder 导入历史定制值
        // （Beian/Title/Brands）；seed 成功后由 UpgradeService 统一清理，不还原到前端。
        // 仅当源文件含迁移键时才暂存（新版配置已不含，后续升级自然不再暂存）；
        // 已存在的暂存不覆盖：升级中断重试时前端已是新包配置，覆盖会冲掉首跑幸存的旧值
        $legacyConfig = "$targetDir/platform-config.json";
        $legacyStash = storage_path("app/legacy-platform-config/$type.json");
        if (File::exists($legacyConfig) && ! File::exists($legacyStash)) {
            $legacyContents = File::get($legacyConfig);
            if (preg_match('/"(Title|Beian|Brands)"/', $legacyContents) === 1) {
                File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
                File::put($legacyStash, $legacyContents);
            }
        }

        // 保护静态回落资源：先备份
        $preserved = $this->preserveFrontendAssets($targetDir, $type);

        // 清空旧前端文件（构建产物带 hash，不清理会越积越多）
        File::deleteDirectory($targetDir);
        File::makeDirectory($targetDir, 0755, true);

        // 同步目录
        $this->syncDirectory($sourceDir, $targetDir);

        // 恢复静态回落资源
        $this->restoreFrontendAssets($targetDir, $preserved, $type);
    }

    /**
     * 保留前端静态回落资源
     */
    protected function preserveFrontendAssets(string $targetDir, string $type): array
    {
        $preserved = [];
        $files = $this->protectedFrontendAssets[$type] ?? [];

        foreach ($files as $file) {
            $filePath = "$targetDir/$file";
            if (File::exists($filePath)) {
                $preserved[$file] = File::get($filePath);
                Log::info("保留前端静态资源: $type/$file");
            }
        }

        return $preserved;
    }

    /**
     * 恢复前端静态回落资源
     */
    protected function restoreFrontendAssets(string $targetDir, array $preserved, string $type): void
    {
        foreach ($preserved as $file => $content) {
            $filePath = "$targetDir/$file";
            File::put($filePath, $content);
            Log::info("恢复前端静态资源: $type/$file");
        }
    }

    /**
     * 补齐 Composer、Artisan 和主要运行功能依赖的可写目录。
     */
    protected function ensureRuntimeDirectories(string $targetDir): void
    {
        foreach (self::RUNTIME_RELATIVE_DIRECTORIES as $relativeDirectory) {
            $directory = str_starts_with($relativeDirectory, 'backend/')
                ? $targetDir.'/'.substr($relativeDirectory, strlen('backend/'))
                : dirname($targetDir)."/$relativeDirectory";
            File::ensureDirectoryExists($directory, 0755, true);
            if (! $this->isWritableDirectory($directory)) {
                $webUser = $this->detectWebUser();

                throw new RuntimeException(
                    "运行目录不可写: {$directory}。".
                    "请确保 Web 服务用户 ($webUser) 对该目录有写权限。"
                );
            }
        }
    }

    /**
     * 单独封装，便于在 root 测试容器中可靠覆盖不可写分支。
     */
    protected function isWritableDirectory(string $directory): bool
    {
        return is_dir($directory) && is_writable($directory);
    }

    /**
     * 保留自定义 API 适配器
     * 排除核心文件（Api.php, default/），保留用户自定义的适配器目录
     */
    protected function preserveCustomApiAdapters(string $targetDir): array
    {
        $preserved = [];

        foreach ($this->apiAdapterDirs as $bucket => $relDir) {
            $apiAdapterDir = "$targetDir/$relDir";
            if (! File::isDirectory($apiAdapterDir)) {
                continue;
            }

            $bucketItems = [];
            $items = array_merge(
                File::files($apiAdapterDir),
                File::directories($apiAdapterDir)
            );

            foreach ($items as $item) {
                $name = is_string($item) ? basename($item) : $item->getFilename();

                if ($this->isCoreApiAdapterFile($name)) {
                    continue;
                }

                $itemPath = is_string($item) ? $item : $item->getRealPath();

                if (File::isDirectory($itemPath)) {
                    $bucketItems[$name] = [
                        'type' => 'directory',
                        'files' => $this->getDirectoryContents($itemPath),
                    ];
                    Log::info("保留自定义 API 适配器目录: $bucket/$name");
                } else {
                    $bucketItems[$name] = [
                        'type' => 'file',
                        'content' => File::get($itemPath),
                    ];
                    Log::info("保留自定义 API 适配器文件: $bucket/$name");
                }
            }

            if (! empty($bucketItems)) {
                $preserved[$bucket] = $bucketItems;
            }
        }

        return $preserved;
    }

    /**
     * 获取目录内容（递归）
     */
    protected function getDirectoryContents(string $dir): array
    {
        $contents = [];
        $files = File::allFiles($dir);

        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $contents[$relativePath] = File::get($file->getRealPath());
        }

        return $contents;
    }

    /**
     * 恢复自定义 API 适配器
     */
    protected function restoreCustomApiAdapters(string $targetDir, array $preserved): void
    {
        if (empty($preserved)) {
            return;
        }

        foreach ($preserved as $bucket => $bucketItems) {
            $relDir = $this->apiAdapterDirs[$bucket] ?? null;
            if ($relDir === null) {
                Log::warning("未知 API 适配器 bucket，跳过: $bucket");

                continue;
            }
            $apiAdapterDir = "$targetDir/$relDir";

            if (! File::isDirectory($apiAdapterDir)) {
                File::makeDirectory($apiAdapterDir, 0755, true);
            }

            foreach ($bucketItems as $name => $data) {
                $targetPath = "$apiAdapterDir/$name";

                if ($data['type'] === 'directory') {
                    foreach ($data['files'] as $relativePath => $content) {
                        $filePath = "$targetPath/$relativePath";
                        $fileDir = dirname($filePath);

                        if (! File::isDirectory($fileDir)) {
                            File::makeDirectory($fileDir, 0755, true);
                        }

                        File::put($filePath, $content);
                    }
                    Log::info("恢复自定义 API 适配器目录: $bucket/$name");
                } else {
                    File::put($targetPath, $data['content']);
                    Log::info("恢复自定义 API 适配器文件: $bucket/$name");
                }
            }
        }
    }

    /**
     * 应用 nginx 配置升级
     *
     * default 全受管：覆盖前清空防残留路由（K2），然后 sync，最后调 render.sh 渲染 enabled/。
     */
    protected function applyNginxUpgrade(string $sourceDir): void
    {
        $targetDir = base_path('../nginx');

        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        // default 全受管，覆盖前清空防残留路由（K2：防已删路由被旧文件复活）
        File::deleteDirectory("$targetDir/default");
        $this->syncDirectory($sourceDir, $targetDir);

        // 渲染 enabled/（占位替换含 manager.conf + web.conf 播种 + default/custom 解析）；后台不 reload
        $this->renderNginx($targetDir);

        Log::info('已更新 nginx 配置');
    }

    /**
     * 调 nginx/render.sh 渲染 enabled/（纯文件操作；后台不 reload）。
     * 委托给 NginxRenderer，失败非致命。
     */
    protected function renderNginx(string $targetDir): void
    {
        app(NginxRenderer::class)->render($this->getProjectRoot());
    }

    /**
     * 查找 nginx 配置目录
     */
    protected function findNginxDir(string $extractedPath): ?string
    {
        $possiblePaths = ["$extractedPath/nginx"];

        // 在子目录中查找
        $dirs = File::directories($extractedPath);
        foreach ($dirs as $dir) {
            $possiblePaths[] = "$dir/nginx";
        }

        foreach ($possiblePaths as $path) {
            if (File::isDirectory($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 获取项目根目录路径（用于 nginx 配置占位符替换）
     *
     * 仅支持宝塔部署，使用实际安装目录（backend 的上级目录）。
     */
    protected function getProjectRoot(): string
    {
        return dirname(base_path());
    }

    /**
     * 同步目录（只覆盖，不删除目标中的多余文件）
     */
    protected function syncDirectory(string $source, string $target): void
    {
        if (! File::isDirectory($target)) {
            File::makeDirectory($target, 0755, true);
        }

        $files = File::allFiles($source);
        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $targetFile = "$target/$relativePath";
            $targetFileDir = dirname($targetFile);

            if (! File::isDirectory($targetFileDir)) {
                File::makeDirectory($targetFileDir, 0755, true);
            }

            File::copy($file->getRealPath(), $targetFile);
        }

        $this->removeEmptyDirectories($target);
    }

    /**
     * 删除空目录
     */
    protected function removeEmptyDirectories(string $path): void
    {
        $dirs = File::directories($path);
        foreach ($dirs as $dir) {
            $this->removeEmptyDirectories($dir);
            if (count(File::files($dir)) === 0 && count(File::directories($dir)) === 0) {
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * 查找 php-requirements.json（与 findBackendDir 同样的两层兼容策略）。
     * 解压形态可能是 $extractedPath/php-requirements.json 或 $extractedPath/{upgrade,full}/php-requirements.json。
     * 找不到返回 null，由 EnvironmentChecker 走 skipped 路径（向后兼容旧版本不含清单的升级包）。
     */
    public function findRequirementsJson(string $extractedPath): ?string
    {
        $candidate = "$extractedPath/php-requirements.json";
        if (File::isFile($candidate)) {
            return $candidate;
        }

        foreach (File::directories($extractedPath) as $dir) {
            $candidate = "$dir/php-requirements.json";
            if (File::isFile($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 查找后端目录
     */
    protected function findBackendDir(string $extractedPath): ?string
    {
        // 直接在解压目录下
        if (File::isDirectory("$extractedPath/backend")) {
            return "$extractedPath/backend";
        }

        // 解压目录本身就是后端
        if (File::isDirectory("$extractedPath/app")) {
            return $extractedPath;
        }

        // 在子目录中查找
        $dirs = File::directories($extractedPath);
        foreach ($dirs as $dir) {
            if (File::isDirectory("$dir/backend")) {
                return "$dir/backend";
            }
            if (File::isDirectory("$dir/app")) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * 查找前端目录
     */
    protected function findFrontendDir(string $extractedPath, string $type): ?string
    {
        $possiblePaths = [
            "$extractedPath/frontend/$type",
            "$extractedPath/$type",
        ];

        // 在子目录中查找
        $dirs = File::directories($extractedPath);
        foreach ($dirs as $dir) {
            $possiblePaths[] = "$dir/frontend/$type";
            $possiblePaths[] = "$dir/$type";
        }

        foreach ($possiblePaths as $path) {
            if (File::isDirectory($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 查找版本配置文件（version.json）
     */
    protected function findVersionConfig(string $extractedPath): ?string
    {
        $possiblePaths = ["$extractedPath/version.json"];

        // 在子目录中查找（升级包可能有根目录）
        $dirs = File::directories($extractedPath);
        foreach ($dirs as $dir) {
            $possiblePaths[] = "$dir/version.json";
        }

        foreach ($possiblePaths as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 更新 version.json 并保留用户自定义字段（如 release_url）
     */
    protected function updateVersionJsonWithPreservedFields(string $newVersionFile): void
    {
        $versionManager = new VersionManager;
        $targetFile = $versionManager->getVersionPath();

        // 读取现有配置中需要保留的字段
        $existingConfig = [];
        if (File::exists($targetFile)) {
            $content = File::get($targetFile);
            $existingConfig = json_decode($content, true) ?: [];
        }

        // 需要保留的用户自定义字段（安装时配置的 release_url 和 network）
        $preservedFields = ['release_url', 'network'];
        // apply 后仍可能迁移或清理失败，不能提前标记为目标版本。
        $preserved = ['version' => $versionManager->getVersionString()];
        foreach ($preservedFields as $field) {
            if (isset($existingConfig[$field])) {
                $preserved[$field] = $existingConfig[$field];
            }
        }

        // 读取新版本配置
        $newConfig = json_decode(File::get($newVersionFile), true) ?: [];

        // 合并：保留用户自定义字段
        foreach ($preserved as $field => $value) {
            $newConfig[$field] = $value;
        }

        // 写入合并后的配置
        File::put($targetFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        Log::info('已更新项目根目录 version.json', ['preserved' => array_keys($preserved)]);
    }

    /**
     * 清理缓存和临时文件
     */
    protected function cleanupCacheFiles(): void
    {
        $baseDir = base_path();
        $cacheDirs = [
            "$baseDir/bootstrap/cache",
            "$baseDir/storage/framework/cache/data",
            "$baseDir/storage/framework/views",
        ];

        foreach ($cacheDirs as $dir) {
            if (File::isDirectory($dir)) {
                // 删除目录内的文件，保留 .gitkeep
                $files = File::files($dir);
                foreach ($files as $file) {
                    if ($file->getFilename() !== '.gitkeep') {
                        File::delete($file->getRealPath());
                    }
                }

                // 递归删除子目录
                $subDirs = File::directories($dir);
                foreach ($subDirs as $subDir) {
                    File::deleteDirectory($subDir);
                }

                Log::info("已清理缓存目录: $dir");
            }
        }
    }

    /**
     * 清理解压的临时文件
     */
    public function cleanup(string $extractedPath): void
    {
        if (File::isDirectory($extractedPath) && str_contains($extractedPath, 'extract_')) {
            File::deleteDirectory($extractedPath);
            Log::info("已清理临时目录: $extractedPath");
        }
    }

    /**
     * 获取下载路径
     */
    public function getDownloadPath(): string
    {
        return $this->downloadPath;
    }

    /**
     * 清理旧的升级包
     */
    public function cleanupOldPackages(): int
    {
        $autoCleanup = Config::get('upgrade.package.auto_cleanup', true);
        if (! $autoCleanup) {
            return 0;
        }

        $retentionDays = Config::get('upgrade.package.retention_days', 30);
        $deleted = 0;
        $files = File::files($this->downloadPath);

        foreach ($files as $file) {
            // 删除超过保留期限的文件
            if ($file->getMTime() < time() - $retentionDays * 24 * 3600) {
                File::delete($file->getRealPath());
                $deleted++;
                Log::info("清理过期升级包: {$file->getFilename()}");
            }
        }

        // 清理解压目录
        $dirs = File::directories($this->downloadPath);
        foreach ($dirs as $dir) {
            if (str_contains($dir, 'extract_')) {
                File::deleteDirectory($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 升级前检查目标目录是否可写
     *
     * @throws RuntimeException 如果目录不可写
     */
    protected function checkWritableBeforeApply(): void
    {
        $targetDir = base_path();

        // base_path 自身可写性 —— applyBackendUpgrade 会往根目录写 artisan/composer.json/
        // composer.lock/php-requirements.json（含首次升级时新增的文件），根不可写则这些写入
        // 必败。旧预检只查子目录会漏判，导致预检通过但 apply 时报错、且文案误导用户去查子目录。
        if (! is_writable($targetDir)) {
            $webUser = $this->detectWebUser();

            throw new RuntimeException(
                "安装根目录不可写: {$targetDir}。".
                '升级需在此目录写入 artisan / composer.json / php-requirements.json 等文件。'.
                "请确保 Web 服务用户 ($webUser) 对该目录有写权限。".
                "可以尝试运行: chown -R $webUser:$webUser $targetDir"
            );
        }

        $notWritable = [];

        // 动态发现 base_path 顶层目录检查可写性，与 applyBackendUpgrade 的动态同步范围对齐
        // —— 避免白名单漏目录（resources/public 等被同步的目录也必须预检可写）。
        // skip storage：它在下面单独检查，给更具体的错误消息。
        foreach (File::directories($targetDir) as $path) {
            if (basename($path) === 'storage') {
                continue;
            }
            if (! is_writable($path)) {
                $notWritable[] = $path;
            }
        }

        if (! empty($notWritable)) {
            $webUser = $this->detectWebUser();
            $dirsStr = implode(', ', $notWritable);

            throw new RuntimeException(
                "以下目录不可写: {$dirsStr}。".
                "请检查文件权限，确保 Web 服务用户 ($webUser) 有写权限。".
                "可以尝试运行: chown -R $webUser:$webUser $targetDir"
            );
        }

        // 检查 storage 目录
        $storagePath = "$targetDir/storage";
        if (is_dir($storagePath) && ! is_writable($storagePath)) {
            throw new RuntimeException(
                "storage 目录不可写: {$storagePath}。".
                '请确保 storage 目录及其子目录有写权限。'
            );
        }

        Log::info('[Upgrade] 目录权限检查通过');
    }

    /**
     * 检测 Web 服务用户
     */
    protected function detectWebUser(): string
    {
        // 检测宝塔环境
        if (is_dir('/www/server')) {
            return 'www';
        }

        // 检查安装目录
        $basePath = base_path();
        if (str_starts_with($basePath, '/www/wwwroot/')) {
            return 'www';
        }

        return 'www-data';
    }
}

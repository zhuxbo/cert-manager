<?php

namespace App\Services\Upgrade;

use App\Exceptions\PhpEnvironmentException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpgradeService
{
    public function __construct(
        protected VersionManager $versionManager,
        protected ReleaseClient $releaseClient,
        protected BackupManager $backupManager,
        protected PackageExtractor $packageExtractor,
        protected DatabaseStructureService $databaseStructureService,
        protected EnvironmentChecker $environmentChecker,
    ) {}

    /**
     * 检查更新
     */
    public function checkForUpdate(): array
    {
        $currentVersion = $this->versionManager->getCurrentVersion();
        $channel = $currentVersion['channel'];

        $latestRelease = $this->releaseClient->getLatestRelease($channel);

        if (! $latestRelease) {
            return [
                'has_update' => false,
                'current_version' => $currentVersion['version'],
                'latest_version' => null,
                'message' => '无法获取最新版本信息',
            ];
        }

        $comparison = $this->versionManager->compareVersions(
            $latestRelease['version'],
            $currentVersion['version']
        );

        $hasUpdate = $comparison > 0;

        return [
            'has_update' => $hasUpdate,
            'current_version' => $currentVersion['version'],
            'latest_version' => $latestRelease['version'],
            'changelog' => $latestRelease['body'] ?? '',
            'download_url' => $this->releaseClient->findUpgradePackageUrl($latestRelease),
            'package_size' => $this->formatPackageSize($latestRelease),
            'release_date' => $latestRelease['published_at'] ?? '',
            'channel' => $channel,
        ];
    }

    /**
     * 执行升级（带状态管理，用于后台任务）
     */
    public function performUpgradeWithStatus(string $version, UpgradeStatusManager $statusManager): array
    {
        $currentVersion = $this->versionManager->getVersionString();
        $inMaintenanceMode = false;

        try {
            // 步骤 1: 获取目标版本信息
            $statusManager->startStep('fetch_release');

            if ($version === 'latest') {
                $channel = $this->versionManager->getChannel();
                $release = $this->releaseClient->getLatestRelease($channel);
            } else {
                $tag = str_starts_with($version, 'v') ? $version : "v$version";
                $release = $this->releaseClient->getReleaseByTag($tag);
            }

            if (! $release) {
                throw new RuntimeException("无法获取版本 $version 的信息");
            }

            $statusManager->completeStep('fetch_release');
            $targetVersion = $release['version'];

            // 步骤 2: 检查版本
            $statusManager->startStep('check_version');

            if ($targetVersion === $currentVersion) {
                throw new RuntimeException("当前已是最新版本 {$currentVersion}，无需升级");
            }

            if (! $this->versionManager->isUpgradeAllowed($targetVersion)) {
                throw new RuntimeException("不允许从 $currentVersion 升级到 {$targetVersion}（目标版本低于当前版本）");
            }

            // 检查 PHP 版本
            if (! $this->versionManager->checkPhpVersion()) {
                $minVersion = $this->versionManager->getMinPhpVersion();
                throw new RuntimeException("PHP 版本不满足要求，需要 PHP >= $minVersion");
            }

            $statusManager->completeStep('check_version');

            // 步骤 3: 创建备份
            $forceBackup = Config::get('upgrade.behavior.force_backup', true);
            $backupId = null;

            if ($forceBackup) {
                $statusManager->startStep('backup');
                $backupId = $this->backupManager->createBackup();
                $statusManager->completeStep('backup');
            }

            // 步骤 4: 进入维护模式
            $maintenanceMode = Config::get('upgrade.behavior.maintenance_mode', true);

            if ($maintenanceMode) {
                $statusManager->startStep('maintenance_on');
                Artisan::call('down', ['--retry' => 60]);
                $inMaintenanceMode = true;
                $statusManager->completeStep('maintenance_on');
            }

            // 步骤 5: 下载升级包
            $statusManager->startStep('download');
            $packagePath = $this->packageExtractor->getDownloadPath()."/upgrade-$targetVersion.zip";

            if (! $this->releaseClient->downloadUpgradePackage($release, $packagePath)) {
                throw new RuntimeException('下载升级包失败');
            }
            $statusManager->completeStep('download');

            // 步骤 6: 解压并验证
            $statusManager->startStep('extract');
            $extractedPath = $this->packageExtractor->extract($packagePath);
            $this->packageExtractor->validatePackage($extractedPath);
            $statusManager->completeStep('extract');

            // 步骤 6.5: PHP 环境检测（仅检测，不修复；不通过提示用 upgrade.sh）
            // 重要：必须在 packageExtractor->applyUpgrade 之前完成；该方法会切换代码目录
            // ——破坏现场之前先拦下不满足环境的升级
            $statusManager->startStep('check_environment');
            $requirementsPath = $this->packageExtractor->findRequirementsJson($extractedPath)
                ?? "$extractedPath/php-requirements.json"; // 兜底：让 check() 命中 file_missing 走 skipped
            $envReport = $this->environmentChecker->check($requirementsPath);
            if (! $envReport['ok']) {
                $details = $this->environmentChecker->summarize($envReport);
                $statusManager->failStep('check_environment', $details['message']);
                throw new PhpEnvironmentException($details['message'], $details);
            }
            if (! empty($envReport['skipped'])) {
                Log::info('[Upgrade] check_environment skipped: '.($envReport['reason'] ?? 'unknown'));
            }
            $statusManager->completeStep('check_environment');

            // 记录当前 composer 文件的 hash（用于检测变化）
            $oldComposerHashes = $this->getComposerHashes(base_path());
            Log::info('[Upgrade] Current composer hashes', $oldComposerHashes);

            // 步骤 7: 应用升级
            $statusManager->startStep('apply');
            $this->packageExtractor->applyUpgrade($extractedPath);
            $statusManager->completeStep('apply');

            // 步骤 8: 安装 Composer 依赖（比较 hash 决定是否需要安装）
            $newComposerHashes = $this->getComposerHashes(base_path());
            Log::info('[Upgrade] New composer hashes', $newComposerHashes);

            $needComposerInstall = $this->hasComposerChanges($oldComposerHashes, $newComposerHashes);

            if ($needComposerInstall) {
                $statusManager->startStep('composer_install');
                Log::info('[Upgrade] Detected composer changes, running composer install');
                if (! $this->runComposerInstall()) {
                    throw new RuntimeException('Composer 依赖安装失败');
                }
                $statusManager->completeStep('composer_install');
            } else {
                Log::info('[Upgrade] No composer changes detected, skipping composer install');
            }

            // 无条件重建 autoload（修复跨小版本升级时 classmap 漂移；对齐 upgrade.sh 策略）
            if (! $this->runDumpAutoload()) {
                throw new RuntimeException('Composer autoload 重建失败');
            }

            // 步骤 9: 清理 opcache
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            // 步骤 10: 运行迁移
            if (Config::get('upgrade.behavior.auto_migrate', true)) {
                $statusManager->startStep('migrate');
                Artisan::call('migrate', ['--force' => true]);
                $statusManager->completeStep('migrate');
            }

            // 步骤 11: 数据库结构校验
            $structureCheckResult = $this->checkAndFixDatabaseStructure($statusManager);

            // 步骤 12: 运行种子
            if (Config::get('upgrade.behavior.auto_seed', true)) {
                $statusManager->startStep('seed');
                $seedClass = Config::get('upgrade.behavior.seed_class');
                $seedOptions = ['--force' => true];
                if ($seedClass) {
                    $seedOptions['--class'] = $seedClass;
                }
                Artisan::call('db:seed', $seedOptions);
                $statusManager->completeStep('seed');
            }

            // 步骤 13: 清理缓存
            // 重建缓存必须在全新子进程中执行：当前进程的 RouteServiceProvider/路由文件/类定义
            // 都是文件覆盖前加载的，Artisan::call 内的 getFreshApplication() 也无法卸载已加载的 class，
            // 会导致 routes-v7.php 内容陈旧，FPM 命中后路由 404，必须手工清缓存才生效。
            if (Config::get('upgrade.behavior.clear_cache', true)) {
                $statusManager->startStep('clear_cache');

                $clearResult = $this->runArtisanInSubprocess('optimize:clear', ['--except' => 'view']);
                Log::info('[Upgrade] optimize:clear (subprocess) exit='.$clearResult['exit_code'].' output: '.$clearResult['output']);

                $configResult = $this->runArtisanInSubprocess('config:cache');
                Log::info('[Upgrade] config:cache (subprocess) exit='.$configResult['exit_code'].' output: '.$configResult['output']);

                // route:cache 可能因插件闭包路由等原因失败，不应阻断升级
                $routeResult = $this->runArtisanInSubprocess('route:cache');
                if ($routeResult['exit_code'] !== 0) {
                    Log::warning('[Upgrade] route:cache 失败（不影响升级）exit='.$routeResult['exit_code'].' output: '.$routeResult['output']);
                } else {
                    Log::info('[Upgrade] route:cache (subprocess) ok output: '.$routeResult['output']);
                }

                $statusManager->completeStep('clear_cache');
            }

            // 步骤 14: 更新版本号
            $statusManager->startStep('update_version');
            $this->updateEnvVersion($targetVersion);
            $statusManager->completeStep('update_version');

            // 步骤 15: 清理临时文件
            $statusManager->startStep('cleanup');
            $this->packageExtractor->cleanup($extractedPath);
            $this->packageExtractor->cleanupOldPackages();
            $statusManager->completeStep('cleanup');

            // 步骤 16: 退出维护模式
            if ($inMaintenanceMode) {
                $statusManager->startStep('maintenance_off');
                Artisan::call('up');
                $inMaintenanceMode = false;
                $statusManager->completeStep('maintenance_off');
            }

            // 重启队列 worker（让常驻 worker 跑完当前 job 后退出，加载新代码）
            try {
                Artisan::call('queue:restart');
                Log::info('[Upgrade] queue:restart 信号已发送');
            } catch (\Exception $e) {
                Log::warning('[Upgrade] queue:restart 失败: '.$e->getMessage());
            }

            // 最终清理
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            Log::info("升级完成: $currentVersion -> $targetVersion");
            $statusManager->complete($currentVersion, $targetVersion, $structureCheckResult);

            return [
                'success' => true,
                'from_version' => $currentVersion,
                'to_version' => $targetVersion,
                'backup_id' => $backupId,
                'structure_check' => $structureCheckResult,
            ];

        } catch (\Exception $e) {
            // 如果在维护模式中，尝试退出
            if ($inMaintenanceMode) {
                try {
                    Artisan::call('up');
                    Log::info('[Upgrade] 升级失败后已退出维护模式');
                } catch (\Exception $upError) {
                    Log::error("退出维护模式失败: {$upError->getMessage()}");
                }
            }

            Log::error("升级失败: {$e->getMessage()}");
            // PHP 环境检测失败时，把结构化 details 一并写入 status.json，前端据此引导用户用 upgrade.sh
            $details = $e instanceof PhpEnvironmentException ? $e->getDetails() : null;
            $statusManager->fail($e->getMessage(), $details);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_details' => $details,
            ];
        }
    }

    /**
     * 回滚到指定备份
     */
    public function rollback(string $backupId): array
    {
        try {
            // 检查备份是否存在
            $backup = $this->backupManager->getBackup($backupId);
            if (! $backup) {
                throw new RuntimeException("备份不存在: $backupId");
            }

            // 进入维护模式
            Artisan::call('down', ['--retry' => 60]);

            // 恢复备份
            $this->backupManager->restoreBackup($backupId);

            // 清理 opcache 以加载恢复的代码
            if (function_exists('opcache_reset')) {
                opcache_reset();
                Log::info('[Rollback] opcache_reset called after restore');
            }

            // 清理并重建缓存（必须用全新子进程，原因见 performUpgradeWithStatus 同段注释）
            $clearResult = $this->runArtisanInSubprocess('optimize:clear', ['--except' => 'view']);
            Log::info('[Rollback] optimize:clear (subprocess) exit='.$clearResult['exit_code'].' output: '.$clearResult['output']);

            $configResult = $this->runArtisanInSubprocess('config:cache');
            Log::info('[Rollback] config:cache (subprocess) exit='.$configResult['exit_code'].' output: '.$configResult['output']);

            $routeResult = $this->runArtisanInSubprocess('route:cache');
            if ($routeResult['exit_code'] !== 0) {
                Log::warning('[Rollback] route:cache 失败 exit='.$routeResult['exit_code'].' output: '.$routeResult['output']);
            } else {
                Log::info('[Rollback] route:cache (subprocess) ok output: '.$routeResult['output']);
            }

            // 最终清理 opcache
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            // 退出维护模式
            Artisan::call('up');

            Log::info("回滚完成: $backupId");

            return [
                'success' => true,
                'backup_id' => $backupId,
                'restored_version' => $backup['version'] ?? 'unknown',
            ];

        } catch (\Exception $e) {
            // 尝试退出维护模式
            try {
                Artisan::call('up');
            } catch (\Exception $upError) {
                // 忽略
            }

            Log::error("回滚失败: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取版本历史
     */
    public function getReleaseHistory(int $limit = 5): array
    {
        $channel = $this->versionManager->getChannel();

        return $this->releaseClient->getReleaseHistory($limit, $channel);
    }

    /**
     * 获取备份列表
     */
    public function getBackups(): array
    {
        return $this->backupManager->listBackups();
    }

    /**
     * 删除备份
     */
    public function deleteBackup(string $backupId): bool
    {
        return $this->backupManager->deleteBackup($backupId);
    }

    /**
     * 格式化包大小
     */
    protected function formatPackageSize(array $release): string
    {
        $assets = $release['assets'] ?? [];

        // 优先查找 upgrade 包大小
        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, 'upgrade') && str_ends_with($name, '.zip')) {
                $size = $asset['size'] ?? 0;
                if ($size > 0) {
                    return $this->formatBytes($size);
                }
            }
        }

        // 回退到 full 包
        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, 'full') && str_ends_with($name, '.zip')) {
                $size = $asset['size'] ?? 0;
                if ($size > 0) {
                    return $this->formatBytes($size);
                }
            }
        }

        return '未知';
    }

    /**
     * 更新版本号（version.json）
     */
    protected function updateEnvVersion(string $version): void
    {
        // 使用 VersionManager 获取正确的 version.json 路径
        $versionPath = $this->versionManager->getVersionPath();

        // 读取现有配置
        $config = [];
        if (file_exists($versionPath)) {
            $config = json_decode(file_get_contents($versionPath), true) ?? [];
        }

        // 更新版本信息
        $config['version'] = $version;
        $config['updated_at'] = date('Y-m-d H:i:s');

        $result = file_put_contents(
            $versionPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($result === false) {
            throw new RuntimeException("更新版本号失败: {$versionPath}。请检查文件权限。");
        }

        Log::info("已更新 version.json: $versionPath -> $version");
    }

    /**
     * 格式化字节数
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2).' '.$units[$index];
    }

    /**
     * 在升级包中查找后端目录
     */
    protected function findBackendDirInPackage(string $extractedPath): ?string
    {
        // 直接在解压目录下
        if (is_dir("$extractedPath/backend")) {
            return "$extractedPath/backend";
        }

        // 解压目录本身就是后端
        if (file_exists("$extractedPath/composer.json")) {
            return $extractedPath;
        }

        // 在子目录中查找（压缩包可能包含根目录）
        $dirs = glob("$extractedPath/*", GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (is_dir("$dir/backend")) {
                return "$dir/backend";
            }
            if (file_exists("$dir/composer.json")) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * 执行 Composer Install
     */
    protected function runComposerInstall(): bool
    {
        $basePath = base_path();

        // 尝试查找 composer 命令
        $composerCmd = $this->findComposerCommand();
        if (! $composerCmd) {
            Log::error('[Upgrade] Composer not found');

            return false;
        }

        // 自动检测并切换镜像
        $mirrorConfigured = $this->configureComposerMirror($basePath, $composerCmd);

        // 关键：composer install 加 --no-scripts 防止 post-autoload-dump 触发 package:discover
        // 旧 vendor 残留时跑 package:discover 会加载老代码导致 fatal，进而把 vendor 写成半成品
        // 改为：composer install 仅装包 → 单独跑 dump-autoload 重建 autoload → 再跑 package:discover
        //
        // 与 deploy/upgrade.sh 不对称：shell 入口走 root SSH，流量已隔离/维护模式生效，fatal 只
        // 影响终端不会死锁前端，故保留 scripts 让 composer 跑完默认流程。本路径走 PHP-FPM www
        // 用户，必须 --no-scripts 兜底，避免 vendor 半成品后请求轮询全员瘫痪。
        $command = sprintf(
            'cd %s && %s install --no-dev --no-scripts --no-interaction 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );

        Log::info("[Upgrade] Running: $command");

        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        Log::info("[Upgrade] Composer output: $outputStr");

        // 如果配置了镜像，安装完成后恢复默认配置
        if ($mirrorConfigured) {
            $this->resetComposerMirror($basePath, $composerCmd);
        }

        if ($returnCode !== 0) {
            Log::error("[Upgrade] Composer install failed with code: $returnCode");

            return false;
        }

        Log::info('[Upgrade] Composer install completed successfully');

        return true;
    }

    /**
     * 无条件重建 autoload + package:discover。
     *
     * 由主流程在 applyUpgrade 之后调用，不依赖 composer.json 是否变化 —— 对齐
     * deploy/upgrade.sh 的"无条件 dump-autoload"策略，修复跨小版本升级时 PSR-4
     * 映射 / classmap 漂移导致的 ClassNotFoundException（如 Laravel 13.7→13.8
     * 内部文件路径调整但 composer 依赖未变）。
     *
     * dump-autoload 失败必须 fail-fast：autoload 不一致会让后续 migrate / seed
     * 加载到不存在的类。package:discover 失败仅 warn（缓存可在 clear_cache 时重生）。
     */
    protected function runDumpAutoload(): bool
    {
        $basePath = base_path();

        $composerCmd = $this->findComposerCommand();
        if (! $composerCmd) {
            Log::error('[Upgrade] Composer not found (runDumpAutoload)');

            return false;
        }

        $dumpCommand = sprintf(
            'cd %s && %s dump-autoload --optimize --no-scripts --no-interaction 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );
        Log::info("[Upgrade] Running: $dumpCommand");
        exec($dumpCommand, $dumpOutput, $dumpReturnCode);
        Log::info('[Upgrade] dump-autoload output: '.implode("\n", $dumpOutput));
        if ($dumpReturnCode !== 0) {
            Log::error("[Upgrade] dump-autoload failed with code: $dumpReturnCode");

            return false;
        }

        // package:discover 生成 bootstrap/cache/packages.php（新版代码 + 新 autoload 已就绪）
        // 失败仅警告不阻断（package 缓存可在下一次清缓存时重生成）
        $discoverResult = $this->runArtisanInSubprocess('package:discover', ['--ansi']);
        if ($discoverResult['exit_code'] !== 0) {
            Log::warning("[Upgrade] package:discover failed (non-blocking) exit={$discoverResult['exit_code']} output: ".$discoverResult['output']);
        } else {
            Log::info('[Upgrade] package:discover ok output: '.$discoverResult['output']);
        }

        return true;
    }

    /**
     * 检测网络并配置 Composer 镜像
     */
    protected function configureComposerMirror(string $basePath, string $composerCmd): bool
    {
        // 检查环境变量强制指定（使用 getenv 而非 env，因为这是运行时检查）
        $forceMirror = getenv('FORCE_CHINA_MIRROR');
        if ($forceMirror !== false) {
            if ($forceMirror === '0') {
                Log::info('[Upgrade] FORCE_CHINA_MIRROR=0, using default source');

                return false;
            }
            if ($forceMirror === '1') {
                Log::info('[Upgrade] FORCE_CHINA_MIRROR=1, forcing Aliyun mirror');

                return $this->setAliyunMirror($basePath, $composerCmd);
            }
        }

        // 检测是否能快速访问 GitHub API（composer.lock 中的 dist URL）
        $canAccessGithub = $this->checkNetworkAccess('https://api.github.com', 3);

        if ($canAccessGithub) {
            Log::info('[Upgrade] GitHub API accessible, using default source');

            return false;
        }

        return $this->setAliyunMirror($basePath, $composerCmd);
    }

    /**
     * 设置阿里云镜像
     */
    protected function setAliyunMirror(string $basePath, string $composerCmd): bool
    {
        $configCmd = sprintf(
            'cd %s && %s config repo.packagist composer https://mirrors.aliyun.com/composer/ 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );

        exec($configCmd, $output, $returnCode);

        if ($returnCode === 0) {
            Log::info('[Upgrade] Configured Aliyun composer mirror');

            return true;
        }

        Log::warning('[Upgrade] Failed to configure mirror, will use default');

        return false;
    }

    /**
     * 重置 Composer 镜像配置
     */
    protected function resetComposerMirror(string $basePath, string $composerCmd): void
    {
        $resetCmd = sprintf(
            'cd %s && %s config --unset repo.packagist 2>&1',
            escapeshellarg($basePath),
            $composerCmd
        );

        exec($resetCmd, $output, $returnCode);

        if ($returnCode === 0) {
            Log::info('[Upgrade] Reset composer mirror configuration');
        }
    }

    /**
     * 检测网络访问
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

    /**
     * 在全新子进程中运行 artisan 命令
     *
     * 用于升级/回滚后重建缓存。当前长驻 PHP 进程是在文件覆盖之前 bootstrap 的，
     * Artisan::call('route:cache') 即便走 getFreshApplication() 仍可能使用 进程内已加载的旧
     * class 定义 / CLI opcache 缓存的旧路由文件，导致 routes-v7.php 内容陈旧；
     * FPM worker 在 opcache.validate_timestamps=0 场景下会持续返回 404。
     * 改用全新子进程则能从干净状态读取磁盘文件构建缓存。
     */
    protected function runArtisanInSubprocess(string $command, array $args = []): array
    {
        $phpBinary = $this->findPhpBinary();
        $artisan = base_path('artisan');

        // 三种合法形态：
        //   1. 位置参数 ['--ansi', '--force']                  → numeric key, value 即 flag
        //   2. flag 形态 ['--force' => true]                   → key 即 flag
        //   3. key=value 形态 ['--except' => 'view']           → "key=value"
        // 历史代码漏了形态 1，导致 ['--ansi'] 被拼成 '0=--ansi' 让 artisan 报"参数不识别"
        $argString = '';
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                $argString .= ' '.escapeshellarg((string) $value);
            } elseif ($value === true) {
                $argString .= ' '.escapeshellarg($key);
            } else {
                $argString .= ' '.escapeshellarg("$key=$value");
            }
        }

        $cmd = sprintf(
            '%s %s %s%s 2>&1',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            escapeshellarg($command),
            $argString
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'command' => $cmd,
        ];
    }

    /**
     * 查找 PHP CLI 二进制
     *
     * HTTP 入口（如回滚）下 PHP_BINARY 是 php-fpm，不能直接 exec，需要查找真实 CLI。
     * CLI 入口（upgrade:run 子进程）下 PHP_BINARY 即 php，直接返回。
     */
    protected function findPhpBinary(): string
    {
        if (! str_contains(PHP_BINARY, 'fpm')) {
            return PHP_BINARY;
        }

        // 从 php-fpm 路径推断 php 路径（宝塔/aapanel 通常在 open_basedir 允许范围内）
        $phpFpmPath = PHP_BINARY;
        $phpPath = str_replace(['php-fpm', 'sbin'], ['php', 'bin'], $phpFpmPath);
        if ($phpPath !== $phpFpmPath && @file_exists($phpPath) && @is_executable($phpPath)) {
            return $phpPath;
        }

        // 常见路径兜底（最低 PHP 8.3）
        $candidates = [
            '/www/server/php/84/bin/php',
            '/www/server/php/83/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
        ];
        foreach ($candidates as $path) {
            if (@file_exists($path) && @is_executable($path)) {
                return $path;
            }
        }

        // PATH 中查找
        $output = [];
        @exec('which php 2>/dev/null', $output);
        if (! empty($output[0]) && @file_exists($output[0])) {
            return $output[0];
        }

        return 'php';
    }

    /**
     * 查找 Composer 命令
     */
    protected function findComposerCommand(): ?string
    {
        // 检查常见的 composer 命令
        $commands = ['composer', 'composer.phar', '/usr/local/bin/composer', '/usr/bin/composer'];

        foreach ($commands as $cmd) {
            exec("which $cmd 2>/dev/null", $output, $returnCode);
            if ($returnCode === 0 && ! empty($output)) {
                return $cmd;
            }
        }

        // 检查当前目录是否有 composer.phar
        $pharPath = base_path('composer.phar');
        if (file_exists($pharPath)) {
            return "php $pharPath";
        }

        return null;
    }

    /**
     * 检查并修复数据库结构
     */
    protected function checkAndFixDatabaseStructure(?UpgradeStatusManager $statusManager = null): array
    {
        $autoCheck = Config::get('upgrade.behavior.auto_structure_check', true);
        $autoFix = Config::get('upgrade.behavior.auto_structure_fix', true);

        if (! $autoCheck) {
            Log::info('[Upgrade] 数据库结构校验已禁用');

            return ['skipped' => true];
        }

        $statusManager?->startStep('structure_check');

        $checkResult = $this->databaseStructureService->check();

        if (! $checkResult['has_diff']) {
            Log::info('[Upgrade] 数据库结构校验通过，无差异');
            $statusManager?->completeStep('structure_check');

            return [
                'has_diff' => false,
                'message' => '数据库结构一致',
            ];
        }

        // 有差异，记录日志
        $summary = $checkResult['summary'];
        Log::warning('[Upgrade] 数据库结构存在差异', $summary);

        // 尝试自动修复（仅 ADD 类型）
        if ($autoFix && $summary['can_auto_fix']) {
            Log::info('[Upgrade] 尝试自动修复数据库结构');
            $fixResult = $this->databaseStructureService->fix();

            if ($fixResult['success']) {
                Log::info('[Upgrade] 数据库结构自动修复成功', [
                    'executed' => count($fixResult['executed']),
                ]);
                $statusManager?->completeStep('structure_check');

                return [
                    'has_diff' => true,
                    'auto_fixed' => true,
                    'executed_count' => count($fixResult['executed']),
                    'message' => '数据库结构差异已自动修复',
                ];
            } else {
                Log::error('[Upgrade] 数据库结构自动修复失败', $fixResult['errors']);
            }
        }

        // 无法自动修复，记录警告
        $warningMessage = $this->buildStructureWarningMessage($summary);
        Log::warning('[Upgrade] 数据库结构需要手动处理: '.$warningMessage);

        $statusManager?->completeStep('structure_check');

        return [
            'has_diff' => true,
            'auto_fixed' => false,
            'summary' => $summary,
            'message' => $warningMessage,
        ];
    }

    /**
     * 构建结构警告消息
     */
    protected function buildStructureWarningMessage(array $summary): string
    {
        $parts = [];

        if (! empty($summary['missing_tables'])) {
            $parts[] = '缺失表: '.implode(', ', $summary['missing_tables']);
        }
        if (! empty($summary['missing_columns'])) {
            $parts[] = '缺失列: '.implode(', ', array_slice($summary['missing_columns'], 0, 5));
            if (count($summary['missing_columns']) > 5) {
                $parts[count($parts) - 1] .= ' 等'.count($summary['missing_columns']).'个';
            }
        }
        if (! empty($summary['modified_columns'])) {
            $parts[] = '需修改列: '.implode(', ', array_slice($summary['modified_columns'], 0, 3));
        }
        if (! empty($summary['extra_tables'])) {
            $parts[] = '多余表: '.implode(', ', $summary['extra_tables']);
        }
        if (! empty($summary['extra_indexes'])) {
            $parts[] = '多余索引: '.implode(', ', array_slice($summary['extra_indexes'], 0, 3));
            if (count($summary['extra_indexes']) > 3) {
                $parts[count($parts) - 1] .= ' 等'.count($summary['extra_indexes']).'个';
            }
        }
        if (! empty($summary['missing_foreign_keys'])) {
            $parts[] = '缺失外键: '.count($summary['missing_foreign_keys']).'个';
        }
        if (! empty($summary['extra_foreign_keys'])) {
            $parts[] = '多余外键: '.count($summary['extra_foreign_keys']).'个';
        }

        return implode('; ', $parts) ?: '存在结构差异';
    }

    /**
     * 获取 composer 文件的 hash
     */
    protected function getComposerHashes(string $basePath): array
    {
        $hashes = [
            'composer_json' => null,
            'composer_lock' => null,
        ];

        $composerJsonPath = "$basePath/composer.json";
        $composerLockPath = "$basePath/composer.lock";

        if (file_exists($composerJsonPath)) {
            $hashes['composer_json'] = hash_file('sha256', $composerJsonPath);
        }

        if (file_exists($composerLockPath)) {
            $hashes['composer_lock'] = hash_file('sha256', $composerLockPath);
        }

        return $hashes;
    }

    /**
     * 检测 composer 文件是否有变化
     */
    protected function hasComposerChanges(array $oldHashes, array $newHashes): bool
    {
        // 如果旧文件不存在但新文件存在，需要安装
        if (empty($oldHashes['composer_json']) && ! empty($newHashes['composer_json'])) {
            Log::info('[Upgrade] composer.json is new, need install');

            return true;
        }

        if (empty($oldHashes['composer_lock']) && ! empty($newHashes['composer_lock'])) {
            Log::info('[Upgrade] composer.lock is new, need install');

            return true;
        }

        // 如果 composer.json hash 变化
        if ($oldHashes['composer_json'] !== $newHashes['composer_json']) {
            Log::info('[Upgrade] composer.json changed');

            return true;
        }

        // 如果 composer.lock hash 变化
        if ($oldHashes['composer_lock'] !== $newHashes['composer_lock']) {
            Log::info('[Upgrade] composer.lock changed');

            return true;
        }

        return false;
    }
}

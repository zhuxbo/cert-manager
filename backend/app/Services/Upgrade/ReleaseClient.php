<?php

namespace App\Services\Upgrade;

use App\Traits\ResolvesExecutablePath;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Release 客户端
 * 仅支持自建 release 服务
 */
class ReleaseClient
{
    use ResolvesExecutablePath;

    protected ?string $baseUrl = null;

    public function __construct()
    {
        $versionManager = new VersionManager;
        $releaseUrl = $versionManager->getReleaseUrl();

        if ($releaseUrl) {
            // Docker 环境下，将 localhost/127.0.0.1 转换为宿主机可访问地址
            if ($versionManager->isDockerEnvironment()) {
                $releaseUrl = $this->convertLocalhostForDocker($releaseUrl);
            }
            $this->baseUrl = rtrim($releaseUrl, '/');
        }
    }

    /**
     * Docker 环境下转换 localhost 地址为宿主机可访问地址
     */
    protected function convertLocalhostForDocker(string $url): string
    {
        // 替换 localhost 或 127.0.0.1 为 Docker 宿主机网关
        $patterns = [
            '/^(https?:\/\/)localhost(:\d+)?/i',
            '/^(https?:\/\/)127\.0\.0\.1(:\d+)?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                $dockerHost = $this->getDockerHostAddress();

                return preg_replace($pattern, '${1}'.$dockerHost.'${2}', $url);
            }
        }

        return $url;
    }

    /**
     * 获取 Docker 宿主机地址
     * 优先尝试 host.docker.internal，回退到默认网关
     */
    protected function getDockerHostAddress(): string
    {
        // 检查 /etc/hosts 是否有 host.docker.internal 条目（Docker Desktop 会添加）
        if (file_exists('/etc/hosts')) {
            $hosts = @file_get_contents('/etc/hosts');
            if ($hosts && preg_match('/^\s*[\d.]+\s+host\.docker\.internal\b/m', $hosts)) {
                return 'host.docker.internal';
            }
        }

        // Linux Docker 使用默认网关 172.17.0.1
        return '172.17.0.1';
    }

    /**
     * 确保已配置 release_url
     */
    protected function ensureConfigured(): void
    {
        if (! $this->baseUrl) {
            throw new RuntimeException('未配置 release_url，请在 version.json 中配置');
        }
    }

    /**
     * 获取最新 Release
     */
    public function getLatestRelease(?string $channel = null): ?array
    {
        $this->ensureConfigured();
        $channel = $channel ?? Config::get('version.channel', 'main');

        try {
            $releases = $this->fetchReleases();

            // 根据通道过滤并找到最高版本
            $latestRelease = null;
            $latestVersion = '0.0.0';

            foreach ($releases as $release) {
                $tagName = $release['tag_name'] ?? '';
                if ($this->matchChannel($tagName, $channel)) {
                    $version = ltrim($tagName, 'vV');
                    $compareVersion = $this->stripPreReleaseSuffix($version);
                    $compareLatest = $this->stripPreReleaseSuffix($latestVersion);

                    if (version_compare($compareVersion, $compareLatest, '>')) {
                        $latestVersion = $version;
                        $latestRelease = $release;
                    }
                }
            }

            return $latestRelease ? $this->normalizeRelease($latestRelease) : null;
        } catch (\Exception $e) {
            Log::error("获取最新 Release 失败: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * 获取指定版本的 Release
     */
    public function getReleaseByTag(string $tag): ?array
    {
        $this->ensureConfigured();
        try {
            $releases = $this->fetchReleases();

            foreach ($releases as $release) {
                if (($release['tag_name'] ?? '') === $tag) {
                    return $this->normalizeRelease($release);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error("获取 Release $tag 失败: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * 获取历史版本列表
     */
    public function getReleaseHistory(int $limit = 5, ?string $channel = null): array
    {
        $this->ensureConfigured();
        $channel = $channel ?? Config::get('version.channel', 'main');

        try {
            $releases = $this->fetchReleases();
            $filtered = [];

            // 按版本号降序排序
            usort($releases, function ($a, $b) {
                $va = ltrim($a['tag_name'] ?? '', 'vV');
                $vb = ltrim($b['tag_name'] ?? '', 'vV');

                return version_compare($vb, $va);
            });

            foreach ($releases as $release) {
                $tagName = $release['tag_name'] ?? '';
                if ($this->matchChannel($tagName, $channel)) {
                    $filtered[] = $this->normalizeRelease($release);
                    if (count($filtered) >= $limit) {
                        break;
                    }
                }
            }

            return $filtered;
        } catch (\Exception $e) {
            Log::error("获取 Release 历史失败: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * 下载升级包
     */
    public function downloadPackage(string $url, string $savePath): bool
    {
        $timeout = Config::get('upgrade.package.download_timeout', 300);

        // 优先使用 curl 命令
        if ($this->downloadWithCurl($url, $savePath, $timeout)) {
            return true;
        }

        // 回退到 PHP HTTP 客户端
        try {
            $response = Http::timeout($timeout)
                ->withOptions(['sink' => $savePath])
                ->get($url);

            if ($response->successful() && file_exists($savePath)) {
                return true;
            }

            Log::error("下载升级包失败: HTTP {$response->status()}");

            return false;
        } catch (\Exception $e) {
            Log::error("下载升级包失败: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * 下载升级包（从自建服务）
     */
    public function downloadPackageWithFallback(string $filename, string $tag, string $savePath): bool
    {
        $this->ensureConfigured();
        $url = "$this->baseUrl/$tag/$filename";
        Log::info("下载: $url");

        if ($this->downloadPackage($url, $savePath)) {
            Log::info('下载成功');

            return true;
        }

        // 清理可能的部分下载文件
        if (file_exists($savePath)) {
            @unlink($savePath);
        }

        Log::error("下载失败: $filename");

        return false;
    }

    /**
     * 根据 Release 下载升级包
     */
    public function downloadUpgradePackage(array $release, string $savePath): bool
    {
        $version = $release['version'] ?? '';
        $tagName = $release['tag_name'] ?? "v$version";

        // 尝试从 assets 中获取文件名和 URL
        $filename = null;
        $assetUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, 'upgrade') && str_ends_with($name, '.zip')) {
                $filename = $name;
                $assetUrl = $asset['browser_download_url'] ?? null;
                break;
            }
        }

        // 如果找到 asset URL，直接使用
        if ($assetUrl) {
            Log::info("下载: $assetUrl");

            return $this->downloadPackage($assetUrl, $savePath);
        }

        // 否则构造标准文件名
        if (! $filename) {
            $filename = "ssl-manager-upgrade-$version.zip";
        }

        return $this->downloadPackageWithFallback($filename, $tagName, $savePath);
    }

    /**
     * 根据 Release 下载完整包
     */
    public function downloadFullPackage(array $release, string $savePath): bool
    {
        $version = $release['version'] ?? '';
        $tagName = $release['tag_name'] ?? "v$version";

        // 尝试从 assets 中获取文件名和 URL
        $filename = null;
        $assetUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, 'full') && str_ends_with($name, '.zip')) {
                $filename = $name;
                $assetUrl = $asset['browser_download_url'] ?? null;
                break;
            }
        }

        // 如果找到 asset URL，直接使用
        if ($assetUrl) {
            Log::info("下载: $assetUrl");

            return $this->downloadPackage($assetUrl, $savePath);
        }

        // 否则构造标准文件名
        if (! $filename) {
            $filename = "ssl-manager-full-$version.zip";
        }

        return $this->downloadPackageWithFallback($filename, $tagName, $savePath);
    }

    /**
     * 使用 curl 命令下载
     */
    protected function downloadWithCurl(string $url, string $savePath, int $timeout): bool
    {
        $curlPath = $this->resolveExecutablePath('curl');
        if ($curlPath === null) {
            return false;
        }

        $args = [
            escapeshellarg($curlPath),
            '-fsL',
            '--connect-timeout',
            '10',
            '--max-time',
            escapeshellarg((string) $timeout),
            '-o',
            escapeshellarg($savePath),
            escapeshellarg($url),
            '2>&1',
        ];

        $command = implode(' ', $args);
        exec($command, $output, $exitCode);

        if ($exitCode === 0 && file_exists($savePath) && filesize($savePath) > 0) {
            Log::info("使用 curl 下载成功: $url");

            return true;
        }

        Log::warning("curl 下载失败 (exit: $exitCode): ".implode("\n", $output));

        return false;
    }

    /**
     * 从 Release 中查找升级包下载地址
     */
    public function findUpgradePackageUrl(array $release): ?string
    {
        $assets = $release['assets'] ?? [];

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, 'upgrade') && str_ends_with($name, '.zip')) {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    /**
     * 从 Release 中查找完整包下载地址
     */
    public function findFullPackageUrl(array $release): ?string
    {
        $assets = $release['assets'] ?? [];

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, 'full') && str_ends_with($name, '.zip')) {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    /**
     * 获取所有 Release
     */
    protected function fetchReleases(): array
    {
        $indexUrl = "$this->baseUrl/releases.json";

        try {
            $response = Http::timeout(10)->get($indexUrl);
            if ($response->successful()) {
                return $response->json()['releases'] ?? [];
            }
        } catch (\Exception $e) {
            Log::warning("获取 releases.json 失败: {$e->getMessage()}");
        }

        return [];
    }

    /**
     * 检查 tag 是否匹配通道
     */
    protected function matchChannel(string $tagName, string $channel): bool
    {
        // 过滤 latest tag
        if (strtolower($tagName) === 'latest') {
            return false;
        }

        // main 通道: v1.0.0（纯版本号，无预发布后缀）
        // dev 通道: v1.0.0-dev, v1.0.0-alpha, v1.0.0-beta, v1.0.0-rc.1 等
        $preReleaseSuffixes = ['-dev', '-alpha', '-beta', '-rc'];
        $isPreRelease = false;

        foreach ($preReleaseSuffixes as $suffix) {
            if (str_contains($tagName, $suffix)) {
                $isPreRelease = true;
                break;
            }
        }

        return ($channel === 'dev') === $isPreRelease;
    }

    /**
     * 移除预发布后缀用于版本比较
     */
    protected function stripPreReleaseSuffix(string $version): string
    {
        return preg_replace('/-(dev|alpha|beta|rc)(\.\d+)?$/', '', $version);
    }

    /**
     * 标准化 Release 数据
     */
    protected function normalizeRelease(array $release): array
    {
        $tagName = $release['tag_name'] ?? '';
        $version = ltrim($tagName, 'vV');

        // 解析资源文件
        $assets = [];
        foreach ($release['assets'] ?? [] as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            // 相对路径转换为完整 URL
            if ($url && ! str_starts_with($url, 'http')) {
                $url = "$this->baseUrl/$url";
            }
            $assets[] = [
                'name' => $asset['name'] ?? '',
                'size' => $asset['size'] ?? 0,
                'browser_download_url' => $url,
            ];
        }

        return [
            'version' => $version,
            'tag_name' => $tagName,
            'name' => $release['name'] ?? $tagName,
            'body' => $release['body'] ?? '',
            'prerelease' => $release['prerelease'] ?? str_contains($tagName, '-dev'),
            'created_at' => $release['created_at'] ?? date('c'),
            'published_at' => $release['published_at'] ?? $release['created_at'] ?? date('c'),
            'assets' => $assets,
        ];
    }
}

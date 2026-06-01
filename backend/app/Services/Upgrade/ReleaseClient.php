<?php

namespace App\Services\Upgrade;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
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
    protected ?string $baseUrl = null;

    public function __construct()
    {
        $versionManager = new VersionManager;
        $releaseUrl = $versionManager->getReleaseUrl();

        if ($releaseUrl) {
            $this->baseUrl = rtrim($releaseUrl, '/');
        }
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
                    // 直接对完整版本号比较；PHP 原生 version_compare 能正确处理
                    // dev 通道下 beta.10 > beta.9 / rc > beta > alpha > dev 等场景
                    // strtolower 与 VersionManager::compareVersions 对齐（大写关键字标准化）
                    $version = ltrim($tagName, 'vV');
                    if (version_compare(strtolower($version), strtolower($latestVersion), '>')) {
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

            // 按版本号降序排序（strtolower 与 VersionManager::compareVersions 对齐）
            usort($releases, function ($a, $b) {
                $va = strtolower(ltrim($a['tag_name'] ?? '', 'vV'));
                $vb = strtolower(ltrim($b['tag_name'] ?? '', 'vV'));

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
        // 传输层安全：公网必须 https，仅内网/私网地址放开 http（兼容内网离线部署）
        if (! $this->validateReleaseUrl($url)) {
            Log::error("下载升级包失败: 不安全的下载地址（公网必须使用 HTTPS）: $url");

            return false;
        }

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
     * 校验下载地址的传输层安全性。
     *
     * 升级包是可执行代码载荷，明文 http 下载可被中间人替换 → 条件性 RCE。
     * 策略（与 deploy/install.sh 允许 `--url http://内网` 的部署语义兼容）：
     *   - https：一律放行
     *   - http：仅当主机解析为私有/保留 IP 段时放行（内网离线部署）；公网 http 拒绝
     *   - 其他 scheme / 无法解析：拒绝
     *
     * 注意与 SSRF 防护（ActionCallbackTrait::isPrivateUrl）语义相反：那里禁内网，
     * 这里恰恰只对内网放宽 http。
     */
    public function validateReleaseUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme !== 'http') {
            return false;
        }

        // http：只允许内网/私网/保留地址
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        // gethostbyname 解析失败时原样返回主机名
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 公网地址（非私有、非保留）走 http → 拒绝
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * 下载升级包并强校验 sha256。
     *
     * fail-closed：releases.json 未提供该 asset 的 sha256，或哈希不匹配，
     * 一律视为失败并删除已下载文件，绝不放行未验证的可执行包。
     *
     * @param  string  $expectedSha256  releases.json 中对应 asset 的 sha256（hex，大小写无关）
     *
     * @throws RuntimeException 校验失败时抛出（引导用户改用 upgrade.sh）
     */
    public function downloadAndVerify(string $url, string $savePath, string $expectedSha256): bool
    {
        if (! $this->downloadPackage($url, $savePath)) {
            return false;
        }

        $this->verifyPackageHash($savePath, $expectedSha256);

        return true;
    }

    /**
     * 校验已下载文件的 sha256，不匹配或缺失期望值时删除文件并抛异常。
     *
     * @throws RuntimeException
     */
    public function verifyPackageHash(string $path, string $expectedSha256): void
    {
        $expected = strtolower(trim($expectedSha256));

        if ($expected === '') {
            @unlink($path);
            throw new RuntimeException(
                'releases.json 缺少升级包的 sha256 校验值，已中止升级。'.
                '为防止下载内容被篡改（条件性 RCE），不校验的升级包不会被应用。'.
                '请确认 release 站 releases.json 的 assets[].sha256 已生成，或改用 upgrade.sh 升级。'
            );
        }

        if (! file_exists($path)) {
            throw new RuntimeException("升级包不存在，无法校验 sha256: $path");
        }

        $actual = strtolower(hash_file('sha256', $path));

        if (! hash_equals($expected, $actual)) {
            @unlink($path);
            throw new RuntimeException(
                "升级包 sha256 校验不匹配，已中止升级并删除文件。期望: {$expected}，实际: {$actual}。".
                '下载内容可能在传输中被篡改，请改用 upgrade.sh 升级。'
            );
        }

        Log::info("升级包 sha256 校验通过: {$expected}");
    }

    /**
     * 提取 Release 中指定 asset 的 sha256（hex）。
     *
     * @param  string  $type  'upgrade' | 'full'，匹配 asset 文件名关键字
     */
    public function findPackageSha256(array $release, string $type): string
    {
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, $type) && str_ends_with($name, '.zip')) {
                return (string) ($asset['sha256'] ?? '');
            }
        }

        return '';
    }

    /**
     * 下载升级包（从自建服务）。
     *
     * @param  string  $expectedSha256  非空时下载后强校验 sha256（fail-closed）；
     *                                  空字符串保留旧行为（仅供无 sha256 上下文的兼容调用）
     *
     * @throws RuntimeException sha256 校验失败（$expectedSha256 非空时）
     */
    public function downloadPackageWithFallback(string $filename, string $tag, string $savePath, string $expectedSha256 = ''): bool
    {
        $this->ensureConfigured();
        $url = "$this->baseUrl/$tag/$filename";
        Log::info("下载: $url");

        if ($this->downloadPackage($url, $savePath)) {
            Log::info('下载成功');

            if ($expectedSha256 !== '') {
                $this->verifyPackageHash($savePath, $expectedSha256);
            }

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
     * 根据 Release 下载升级包并强校验 sha256（fail-closed）。
     *
     * @throws RuntimeException sha256 缺失或不匹配
     */
    public function downloadUpgradePackage(array $release, string $savePath): bool
    {
        return $this->downloadReleaseAsset($release, $savePath, 'upgrade');
    }

    /**
     * 根据 Release 下载完整包并强校验 sha256（fail-closed）。
     *
     * @throws RuntimeException sha256 缺失或不匹配
     */
    public function downloadFullPackage(array $release, string $savePath): bool
    {
        return $this->downloadReleaseAsset($release, $savePath, 'full');
    }

    /**
     * 根据 Release 下载指定类型的 asset 并强校验 sha256。
     *
     * sha256 来自 releases.json 对应 asset 条目（fail-closed：缺失即视为校验失败）。
     * URL 解析与历史一致：优先用 asset 的 browser_download_url，否则按版本目录构造 fallback。
     *
     * fail-closed（覆盖所有下载路径）：releases.json 未提供匹配 asset 的 sha256 时，
     * 在任何下载发生之前直接拒绝，绝不退化到无校验的 fallback 下载。
     * 否则攻击者只要影响 releases.json（省略/改名 asset → sha256 取空），即可迫使走
     * downloadPackageWithFallback 的空 sha256 分支跳过校验，下载未经验证的可执行包 → 条件性 RCE。
     *
     * @param  string  $type  'upgrade' | 'full'
     *
     * @throws RuntimeException sha256 缺失或不匹配（已删除下载文件）
     */
    protected function downloadReleaseAsset(array $release, string $savePath, string $type): bool
    {
        $version = $release['version'] ?? '';
        $tagName = $release['tag_name'] ?? "v$version";

        // sha256 在 normalizeRelease 阶段从 releases.json 保留下来
        $expectedSha256 = $this->findPackageSha256($release, $type);

        // fail-closed：缺少 sha256（asset 缺失/改名/无 sha256 字段）一律拒绝，
        // 不下载、不走无校验 fallback。文案与 verifyPackageHash 一致，引导改用 upgrade.sh。
        if (trim($expectedSha256) === '') {
            throw new RuntimeException(
                'releases.json 缺少升级包的 sha256 校验值，已中止升级。'.
                '为防止下载内容被篡改（条件性 RCE），不校验的升级包不会被应用。'.
                '请确认 release 站 releases.json 的 assets[].sha256 已生成，或改用 upgrade.sh 升级。'
            );
        }

        // 尝试从 assets 中获取文件名和 URL
        $filename = null;
        $assetUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (str_contains($name, $type) && str_ends_with($name, '.zip')) {
                $filename = $name;
                $assetUrl = $asset['browser_download_url'] ?? null;
                break;
            }
        }

        // 如果找到 asset URL，直接使用
        if ($assetUrl) {
            Log::info("下载: $assetUrl");

            return $this->downloadAndVerify($assetUrl, $savePath, $expectedSha256);
        }

        // 否则构造标准文件名
        if (! $filename) {
            $filename = "ssl-manager-$type-$version.zip";
        }

        return $this->downloadPackageWithFallback($filename, $tagName, $savePath, $expectedSha256);
    }

    /**
     * 使用 curl 命令下载
     */
    protected function downloadWithCurl(string $url, string $savePath, int $timeout): bool
    {
        try {
            $curlPath = app(BinaryLocator::class)->curl();
        } catch (BinaryNotFoundException) {
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
                // 保留发布端写入的 sha256（hex 小写）——下载后强校验依赖，
                // 字段名/编码与 build/scripts/release-common.sh 及 deploy/scripts/common.sh
                // 的线协议一致；缺失则置空，由 downloadAndVerify fail-closed 拦截
                'sha256' => $asset['sha256'] ?? '',
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

<?php

use App\Services\Upgrade\ReleaseClient;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->client = new ReleaseClient;
    $this->testDir = storage_path('upgrades/release_test_'.uniqid());
    File::makeDirectory($this->testDir, 0755, true);
});

afterEach(function () {
    if (File::isDirectory($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

// ==================== normalizeRelease 保留 sha256 ====================

test('normalizeRelease 保留 asset 的 sha256 字段', function () {
    $reflection = new ReflectionClass($this->client);
    $method = $reflection->getMethod('normalizeRelease');

    $raw = [
        'tag_name' => 'v1.2.3',
        'assets' => [
            [
                'name' => 'ssl-manager-upgrade-1.2.3.zip',
                'size' => 1024,
                'sha256' => 'abc123def456',
                'browser_download_url' => 'https://release.example.com/v1.2.3/ssl-manager-upgrade-1.2.3.zip',
            ],
        ],
    ];

    $normalized = $method->invoke($this->client, $raw);

    expect($normalized['assets'][0]['sha256'])->toBe('abc123def456');
    expect($normalized['version'])->toBe('1.2.3');
});

test('normalizeRelease 缺 sha256 时置空字符串', function () {
    $reflection = new ReflectionClass($this->client);
    $method = $reflection->getMethod('normalizeRelease');

    $raw = [
        'tag_name' => 'v1.0.0',
        'assets' => [
            ['name' => 'ssl-manager-upgrade-1.0.0.zip', 'browser_download_url' => 'https://example.com/x.zip'],
        ],
    ];

    $normalized = $method->invoke($this->client, $raw);

    expect($normalized['assets'][0]['sha256'])->toBe('');
});

// ==================== findPackageSha256 ====================

test('findPackageSha256 按类型提取 sha256', function () {
    $release = [
        'assets' => [
            ['name' => 'ssl-manager-full-1.0.0.zip', 'sha256' => 'fullhash'],
            ['name' => 'ssl-manager-upgrade-1.0.0.zip', 'sha256' => 'upgradehash'],
        ],
    ];

    expect($this->client->findPackageSha256($release, 'upgrade'))->toBe('upgradehash');
    expect($this->client->findPackageSha256($release, 'full'))->toBe('fullhash');
});

test('findPackageSha256 找不到时返回空字符串', function () {
    expect($this->client->findPackageSha256(['assets' => []], 'upgrade'))->toBe('');
});

// ==================== verifyPackageHash：匹配通过 ====================

test('verifyPackageHash 匹配时通过且不删除文件', function () {
    $file = "$this->testDir/package.zip";
    File::put($file, 'fake package content');
    $sha = hash('sha256', 'fake package content');

    $this->client->verifyPackageHash($file, $sha);

    expect($file)->toBeFile();
});

test('verifyPackageHash 大小写无关比对', function () {
    $file = "$this->testDir/package.zip";
    File::put($file, 'payload');
    $sha = strtoupper(hash('sha256', 'payload'));

    $this->client->verifyPackageHash($file, $sha);

    expect($file)->toBeFile();
});

// ==================== verifyPackageHash：不匹配中止 + 删文件 ====================

test('verifyPackageHash 不匹配时抛异常并删除文件', function () {
    $file = "$this->testDir/tampered.zip";
    File::put($file, 'malicious payload');
    $wrongSha = hash('sha256', 'expected legit content');

    try {
        $this->client->verifyPackageHash($file, $wrongSha);
        $this->fail('应当抛出 RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('sha256 校验不匹配');
        // fail-closed：被篡改的包必须被删除，绝不残留供后续 extract
        expect($file)->not->toBeFile();
    }
});

// ==================== verifyPackageHash：缺 sha256 fail-closed ====================

test('verifyPackageHash 期望值为空时 fail-closed 抛异常并删除文件', function () {
    $file = "$this->testDir/unverified.zip";
    File::put($file, 'content');

    try {
        $this->client->verifyPackageHash($file, '');
        $this->fail('应当抛出 RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('缺少升级包的 sha256');
        expect($file)->not->toBeFile();
    }
});

test('verifyPackageHash 期望值仅空白时同样 fail-closed', function () {
    $file = "$this->testDir/unverified2.zip";
    File::put($file, 'content');

    expect(fn () => $this->client->verifyPackageHash($file, "   \n"))
        ->toThrow(RuntimeException::class, '缺少升级包的 sha256');
});

// ==================== validateReleaseUrl：传输层安全 ====================

test('validateReleaseUrl 放行 https', function () {
    expect($this->client->validateReleaseUrl('https://release.example.com/v1/pkg.zip'))->toBeTrue();
});

test('validateReleaseUrl 拒绝公网 http', function () {
    // 8.8.8.8 是公网地址，http 明文必须拒绝（防中间人替换升级包 → RCE）
    expect($this->client->validateReleaseUrl('http://8.8.8.8/pkg.zip'))->toBeFalse();
});

test('validateReleaseUrl 放行内网 http', function (string $url) {
    // 内网/私网/保留地址走 http 是合法离线部署场景（与 deploy/install.sh --url http://内网 对齐）
    expect($this->client->validateReleaseUrl($url))->toBeTrue();
})->with([
    'http://192.168.1.10/pkg.zip',
    'http://10.0.0.5/pkg.zip',
    'http://172.16.0.1/pkg.zip',
    'http://127.0.0.1/pkg.zip',
]);

test('validateReleaseUrl 拒绝非 http(s) scheme', function (string $url) {
    expect($this->client->validateReleaseUrl($url))->toBeFalse();
})->with([
    'ftp://example.com/pkg.zip',
    'file:///etc/passwd',
    'pkg.zip',
]);

// ==================== downloadReleaseAsset：无 sha256 fail-closed（不退化到无校验 fallback）====================
//
// HIGH-4 回归：releases.json 没有匹配 asset（或 asset 无 sha256）时，findPackageSha256 返回空。
// 必须在任何下载发生前抛 RuntimeException（fail-closed），绝不走 downloadPackageWithFallback 的空
// sha256 分支静默下载未校验可执行包。攻击者影响 releases.json 即可触发该 fallback → 条件性 RCE。

test('downloadUpgradePackage：releases.json 无匹配 asset（无 sha256）时升级失败而非静默下载', function () {
    // 完全没有匹配 upgrade 的 asset → findPackageSha256 取空 → 必须 fail-closed
    $release = [
        'version' => '1.2.3',
        'tag_name' => 'v1.2.3',
        'assets' => [],
    ];
    $savePath = "$this->testDir/should-not-exist.zip";

    expect(fn () => $this->client->downloadUpgradePackage($release, $savePath))
        ->toThrow(RuntimeException::class, '缺少升级包的 sha256');

    // fail-closed 在下载前生效：savePath 不应被创建（没有任何未校验包落地）
    expect($savePath)->not->toBeFile();
});

test('downloadFullPackage：asset 存在但缺 sha256 字段时同样 fail-closed', function () {
    // asset 名匹配 full+.zip，但没有 sha256 字段 → findPackageSha256 取空 → 必须 fail-closed
    $release = [
        'version' => '1.2.3',
        'tag_name' => 'v1.2.3',
        'assets' => [
            [
                'name' => 'ssl-manager-full-1.2.3.zip',
                'browser_download_url' => 'https://release.example.com/v1.2.3/ssl-manager-full-1.2.3.zip',
            ],
        ],
    ];
    $savePath = "$this->testDir/should-not-exist-full.zip";

    expect(fn () => $this->client->downloadFullPackage($release, $savePath))
        ->toThrow(RuntimeException::class, '缺少升级包的 sha256');

    expect($savePath)->not->toBeFile();
});

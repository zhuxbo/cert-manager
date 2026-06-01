<?php

use App\Services\Upgrade\PackageExtractor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->extractor = new PackageExtractor;
    $this->testDir = storage_path('upgrades/test_'.uniqid());
    File::makeDirectory($this->testDir, 0755, true);
});

afterEach(function () {
    // 清理测试目录
    if (File::isDirectory($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

test('extract throws exception for missing package', function () {
    $this->extractor->extract('/nonexistent/package.zip');
})->throws(RuntimeException::class, '升级包不存在');

test('extract throws exception for invalid zip', function () {
    // 创建一个无效的 zip 文件
    $invalidZip = "$this->testDir/invalid.zip";
    File::put($invalidZip, 'not a zip file');

    $this->extractor->extract($invalidZip);
})->throws(RuntimeException::class, '无法打开升级包');

test('extract valid package', function () {
    // 创建一个有效的 zip 文件
    $zipPath = createTestPackage($this->testDir);

    $extractedPath = $this->extractor->extract($zipPath);

    expect($extractedPath)->toBeDirectory();
    expect("$extractedPath/version.json")->toBeFile();

    // 清理
    File::deleteDirectory($extractedPath);
});

test('validate package throws for missing backend', function () {
    // 创建没有 backend 目录的包
    $packageDir = "$this->testDir/package";
    File::makeDirectory($packageDir, 0755, true);
    File::put("$packageDir/version.json", json_encode(['version' => '1.0.0']));

    $this->extractor->validatePackage($packageDir);
})->throws(RuntimeException::class, '缺少 backend 目录');

test('validate package throws for missing version json', function () {
    // 创建有 backend 但没 version.json
    $packageDir = "$this->testDir/package";
    File::makeDirectory("$packageDir/backend/app", 0755, true);
    File::makeDirectory("$packageDir/backend/config", 0755, true);

    $this->extractor->validatePackage($packageDir);
})->throws(RuntimeException::class, '缺少 version.json');

test('validate package throws for invalid version json', function () {
    $packageDir = "$this->testDir/package";
    File::makeDirectory("$packageDir/backend/app", 0755, true);
    File::makeDirectory("$packageDir/backend/config", 0755, true);
    File::put("$packageDir/version.json", 'invalid json');

    $this->extractor->validatePackage($packageDir);
})->throws(RuntimeException::class, 'version.json 格式错误');

test('validate package throws for missing version field', function () {
    $packageDir = "$this->testDir/package";
    File::makeDirectory("$packageDir/backend/app", 0755, true);
    File::makeDirectory("$packageDir/backend/config", 0755, true);
    File::put("$packageDir/version.json", json_encode(['name' => 'test']));

    $this->extractor->validatePackage($packageDir);
})->throws(RuntimeException::class, 'version.json 缺少 version 字段');

test('validate package success', function () {
    $packageDir = createValidPackageDir($this->testDir);

    $result = $this->extractor->validatePackage($packageDir);

    expect($result)->toBeTrue();
});

test('detect web user returns www for baota', function () {
    $reflection = new ReflectionClass($this->extractor);
    $method = $reflection->getMethod('detectWebUser');

    $result = $method->invoke($this->extractor);

    // 当前环境是宝塔，应该返回 www
    if (is_dir('/www/server') || str_starts_with(base_path(), '/www/wwwroot/')) {
        expect($result)->toBe('www');
    } else {
        expect($result)->toBe('www-data');
    }
});

test('find version config in root', function () {
    $packageDir = "$this->testDir/package";
    File::makeDirectory($packageDir, 0755, true);
    File::put("$packageDir/version.json", '{}');

    $reflection = new ReflectionClass($this->extractor);
    $method = $reflection->getMethod('findVersionConfig');

    $result = $method->invoke($this->extractor, $packageDir);

    expect($result)->toBe("$packageDir/version.json");
});

test('find version config in subdirectory', function () {
    $packageDir = "$this->testDir/package";
    $subDir = "$packageDir/ssl-manager-1.0.0";
    File::makeDirectory($subDir, 0755, true);
    File::put("$subDir/version.json", '{}');

    $reflection = new ReflectionClass($this->extractor);
    $method = $reflection->getMethod('findVersionConfig');

    $result = $method->invoke($this->extractor, $packageDir);

    expect($result)->toBe("$subDir/version.json");
});

test('find backend dir direct', function () {
    $packageDir = "$this->testDir/package";
    File::makeDirectory("$packageDir/backend/app", 0755, true);

    $reflection = new ReflectionClass($this->extractor);
    $method = $reflection->getMethod('findBackendDir');

    $result = $method->invoke($this->extractor, $packageDir);

    expect($result)->toBe("$packageDir/backend");
});

test('find backend dir with app', function () {
    $packageDir = "$this->testDir/package";
    File::makeDirectory("$packageDir/app", 0755, true);

    $reflection = new ReflectionClass($this->extractor);
    $method = $reflection->getMethod('findBackendDir');

    $result = $method->invoke($this->extractor, $packageDir);

    expect($result)->toBe($packageDir);
});

test('find requirements json in root', function () {
    // 形态 1: 解压目录根直接有 php-requirements.json
    // 例如 script 包解压后 $extractDir/php-requirements.json
    $packageDir = "$this->testDir/pkg_root";
    File::makeDirectory($packageDir, 0755, true);
    File::put("$packageDir/php-requirements.json", '{"php_min":"8.3.0"}');

    $result = $this->extractor->findRequirementsJson($packageDir);

    expect($result)->toBe("$packageDir/php-requirements.json");
});

test('find requirements json in subdirectory', function () {
    // 形态 2: zip 解压后顶层带 upgrade/ 目录（package.sh 默认产出形态）
    // 例如 $extractDir/upgrade/php-requirements.json
    $packageDir = "$this->testDir/pkg_sub";
    File::makeDirectory("$packageDir/upgrade", 0755, true);
    File::put("$packageDir/upgrade/php-requirements.json", '{"php_min":"8.3.0"}');

    $result = $this->extractor->findRequirementsJson($packageDir);

    expect($result)->toBe("$packageDir/upgrade/php-requirements.json");
});

test('find requirements json returns null when missing', function () {
    // 形态 3: 老版本升级包不带清单 — 必须返回 null 让 EnvironmentChecker 走 skipped 路径（向后兼容）
    $packageDir = "$this->testDir/pkg_empty";
    File::makeDirectory("$packageDir/backend", 0755, true);

    $result = $this->extractor->findRequirementsJson($packageDir);

    expect($result)->toBeNull();
});

test('find requirements json prefers root over subdirectory', function () {
    // 优先级：根目录命中优先于子目录（避免子目录内的旧清单覆盖根的新清单）
    $packageDir = "$this->testDir/pkg_both";
    File::makeDirectory("$packageDir/upgrade", 0755, true);
    File::put("$packageDir/php-requirements.json", '{"php_min":"9.0.0"}');
    File::put("$packageDir/upgrade/php-requirements.json", '{"php_min":"8.0.0"}');

    $result = $this->extractor->findRequirementsJson($packageDir);

    expect($result)->toBe("$packageDir/php-requirements.json");
});

test('cleanup removes extract directory', function () {
    $extractDir = "$this->testDir/extract_test123";
    File::makeDirectory($extractDir, 0755, true);
    File::put("$extractDir/test.txt", 'test');

    $this->extractor->cleanup($extractDir);

    expect($extractDir)->not->toBeDirectory();
});

test('cleanup ignores non extract directory', function () {
    $normalDir = "$this->testDir/normal_dir";
    File::makeDirectory($normalDir, 0755, true);

    $this->extractor->cleanup($normalDir);

    // 非 extract_ 开头的目录不应该被删除
    expect($normalDir)->toBeDirectory();
});

test('get download path', function () {
    $path = $this->extractor->getDownloadPath();

    expect($path)->not->toBeEmpty();
    expect($path)->toBeDirectory();
});

// ==================== zip-slip / 符号链接 安全校验（ArchiveGuard 接入） ====================

test('extract 拒绝含 .. 路径遍历条目的升级包', function () {
    $zipPath = "$this->testDir/traversal.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // 合法载荷 + 一个穿越条目
    $zip->addFromString('version.json', json_encode(['version' => '1.0.0']));
    $zip->addFromString('../../../etc/passwd', 'root::0:0');
    $zip->close();

    // 校验在 extractTo 之前发生，拒绝后清理临时解压目录，不残留
    $before = count(File::directories($this->extractor->getDownloadPath()));

    expect(fn () => $this->extractor->extract($zipPath))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');

    expect(count(File::directories($this->extractor->getDownloadPath())))->toBe($before);
});

test('extract 拒绝含绝对路径条目的升级包', function () {
    $zipPath = "$this->testDir/absolute.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('version.json', json_encode(['version' => '1.0.0']));
    $zip->addFromString('/etc/cron.d/evil', 'x');
    $zip->close();

    expect(fn () => $this->extractor->extract($zipPath))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
});

test('extract 拒绝含反斜杠条目的升级包（Windows 风格绕过）', function () {
    $zipPath = "$this->testDir/backslash.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('version.json', json_encode(['version' => '1.0.0']));
    $zip->addFromString('app\\..\\..\\escape.php', 'x');
    $zip->close();

    expect(fn () => $this->extractor->extract($zipPath))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
});

test('extract 拒绝含符号链接条目的升级包', function () {
    $zipPath = "$this->testDir/symlink.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('version.json', json_encode(['version' => '1.0.0']));
    $zip->addFromString('link', '/etc/passwd');
    // 设 Unix 符号链接模式位（S_IFLNK 0xA000 | 0777），external attr 高 16 位为 mode
    $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0xA000 | 0777) << 16);
    $zip->close();

    expect(fn () => $this->extractor->extract($zipPath))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
});

test('extract 合法升级包结构（含 Unix 普通文件属性）正常通过', function () {
    $zipPath = "$this->testDir/legit.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('version.json', json_encode(['version' => '1.0.0']));
    $zip->addFromString('backend/app/test.php', '<?php // test');
    $zip->addFromString('backend/config/test.php', '<?php return [];');
    // 普通文件 S_IFREG 0x8000 | 0644，确保不被误判为符号链接
    $zip->setExternalAttributesName('backend/app/test.php', ZipArchive::OPSYS_UNIX, (0x8000 | 0644) << 16);
    $zip->close();

    $extractedPath = $this->extractor->extract($zipPath);

    expect($extractedPath)->toBeDirectory();
    expect("$extractedPath/version.json")->toBeFile();
    expect("$extractedPath/backend/app/test.php")->toBeFile();

    File::deleteDirectory($extractedPath);
});

/**
 * 创建测试用的有效升级包 ZIP
 */
function createTestPackage(string $testDir): string
{
    $packageDir = "$testDir/package_source";
    File::makeDirectory("$packageDir/backend/app", 0755, true);
    File::makeDirectory("$packageDir/backend/config", 0755, true);

    File::put("$packageDir/version.json", json_encode([
        'version' => '1.0.0',
        'name' => 'Test Package',
    ]));
    File::put("$packageDir/backend/app/test.php", '<?php // test');
    File::put("$packageDir/backend/config/test.php", '<?php return [];');

    $zipPath = "$testDir/test_package.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);

    addDirToZipHelper($zip, $packageDir, '');

    $zip->close();

    // 清理临时目录
    File::deleteDirectory($packageDir);

    return $zipPath;
}

/**
 * 递归添加目录到 ZIP
 */
function addDirToZipHelper(ZipArchive $zip, string $dir, string $prefix): void
{
    $files = File::files($dir);
    foreach ($files as $file) {
        $relativePath = $prefix ? "$prefix/{$file->getFilename()}" : $file->getFilename();
        $zip->addFile($file->getRealPath(), $relativePath);
    }

    $dirs = File::directories($dir);
    foreach ($dirs as $subDir) {
        $dirName = basename($subDir);
        $newPrefix = $prefix ? "$prefix/$dirName" : $dirName;
        addDirToZipHelper($zip, $subDir, $newPrefix);
    }
}

/**
 * 创建有效的升级包目录结构
 */
function createValidPackageDir(string $testDir): string
{
    $packageDir = "$testDir/valid_package";
    File::makeDirectory("$packageDir/backend/app", 0755, true);
    File::makeDirectory("$packageDir/backend/config", 0755, true);

    File::put("$packageDir/version.json", json_encode([
        'version' => '1.0.0',
        'name' => 'Test Package',
        'build_time' => date('Y-m-d H:i:s'),
    ]));

    return $packageDir;
}

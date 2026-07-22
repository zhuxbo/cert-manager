<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Upgrade\PackageExtractor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->extractor = new PackageExtractor;
    $this->originalStoragePath = storage_path();
    $this->testDir = storage_path('upgrades/test_'.uniqid());
    File::makeDirectory($this->testDir, 0755, true);
    app()->useStoragePath("$this->testDir/storage");
});

afterEach(function () {
    app()->useStoragePath($this->originalStoragePath);

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

test('applyBackendUpgrade 同步 resources 目录到安装目录（回归：对外 API 文档 yaml 不再丢失）', function () {
    // 升级包 source：backend/ 下含 app（基线）+ resources/docs/api/v2.yaml（曾因不在白名单被漏掉）
    $sourceDir = "$this->testDir/pkg/backend";
    File::makeDirectory("$sourceDir/app", 0755, true);
    File::makeDirectory("$sourceDir/resources/docs/api", 0755, true);
    File::put("$sourceDir/app/Marker.php", '<?php // marker');
    File::put("$sourceDir/resources/docs/api/v2.yaml", "openapi: 3.1.0\ninfo:\n  title: V2\n");

    // base_path 临时指向空安装目录，避免污染真实项目；finally 恢复（applyBackendUpgrade 写 base_path()）
    $installDir = "$this->testDir/install";
    File::makeDirectory($installDir, 0755, true);
    $originalBase = base_path();
    app()->setBasePath($installDir);

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('applyBackendUpgrade');
        $method->invoke($this->extractor, $sourceDir);

        // 回归断言：resources/docs/api/v2.yaml 必须随升级落地（否则 MetaController::apiDoc 读不到 → 404）
        expect("$installDir/resources/docs/api/v2.yaml")->toBeFile();
        expect(File::get("$installDir/resources/docs/api/v2.yaml"))->toContain('openapi: 3.1.0');
        // 基线：app 目录照常同步
        expect("$installDir/app/Marker.php")->toBeFile();
    } finally {
        app()->setBasePath($originalBase);
    }
});

test('applyBackendUpgrade 动态发现顶层目录（新增目录不再被白名单漏掉）', function () {
    // source 含一个不在旧硬编码白名单（app/config/database/routes/bootstrap/public/resources）里的新顶层目录
    $sourceDir = "$this->testDir/pkg/backend";
    File::makeDirectory("$sourceDir/app", 0755, true);
    File::makeDirectory("$sourceDir/newmodule/sub", 0755, true);
    File::put("$sourceDir/app/Marker.php", '<?php // marker');
    File::put("$sourceDir/newmodule/sub/Feature.php", '<?php // new module');

    $installDir = "$this->testDir/install";
    File::makeDirectory($installDir, 0755, true);
    $originalBase = base_path();
    app()->setBasePath($installDir);

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('applyBackendUpgrade');
        $method->invoke($this->extractor, $sourceDir);

        // 动态发现：新目录也被同步（硬编码白名单不含 newmodule，会漏）
        expect("$installDir/newmodule/sub/Feature.php")->toBeFile();
        expect("$installDir/app/Marker.php")->toBeFile();
    } finally {
        app()->setBasePath($originalBase);
    }
});

test('applyBackendUpgrade 跳过 storage（保护运行时数据、升级状态、空目录）', function () {
    $sourceDir = "$this->testDir/pkg/backend";
    File::makeDirectory("$sourceDir/app", 0755, true);
    File::makeDirectory("$sourceDir/storage/framework", 0755, true);
    File::put("$sourceDir/app/Marker.php", '<?php // marker');
    // 包内 storage 带“污染”文件：若被同步会落到安装目录
    File::put("$sourceDir/storage/framework/poison.txt", 'from-package');

    $installDir = "$this->testDir/install";
    File::makeDirectory("$installDir/storage/framework", 0755, true);
    File::makeDirectory("$installDir/storage/app/public", 0755, true); // 标准空目录，不能被 removeEmptyDirectories 误删
    // 安装目录预存升级自身状态（模拟 upgrade.lock / status.json），绝不能被覆盖
    File::put("$installDir/storage/framework/state.json", 'RUNNING');
    $originalBase = base_path();
    app()->setBasePath($installDir);

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('applyBackendUpgrade');
        $method->invoke($this->extractor, $sourceDir);

        // storage 完全不被碰 —— 三条覆盖不同失败模式（skip 失效时各自独立 FAIL）
        expect("$installDir/storage/app/public")->toBeDirectory();                      // removeEmptyDirectories 不误删 storage 空目录
        expect("$installDir/storage/framework/poison.txt")->not->toBeFile();            // 包内 storage 内容不落地
        expect(File::get("$installDir/storage/framework/state.json"))->toBe('RUNNING'); // 已有运行时状态不被覆盖
        // 其他目录正常同步
        expect("$installDir/app/Marker.php")->toBeFile();
    } finally {
        app()->setBasePath($originalBase);
    }
});

test('applyFrontendUpgrade 更新 platform config 且不保留 admin logo', function () {
    $sourceDir = "$this->testDir/pkg/frontend/admin";
    File::makeDirectory($sourceDir, 0755, true);
    File::put("$sourceDir/platform-config.json", '{"source":"new"}');

    $installDir = "$this->testDir/install";
    $targetDir = "$installDir/frontend/admin";
    File::makeDirectory("$installDir/backend", 0755, true);
    File::makeDirectory($targetDir, 0755, true);
    File::put("$targetDir/platform-config.json", '{"source":"old"}');
    File::put("$targetDir/logo.svg", 'OLD-LOGO');

    $originalBase = base_path();
    app()->setBasePath("$installDir/backend");

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('applyFrontendUpgrade');
        $method->invoke($this->extractor, $sourceDir, 'admin');

        expect(File::get("$targetDir/platform-config.json"))->toBe('{"source":"new"}')
            ->and("$targetDir/logo.svg")->not->toBeFile()
            // 不含迁移键（Title/Beian/Brands）的配置不暂存——新版配置形态，后续升级不再产生暂存
            ->and(storage_path('app/legacy-platform-config/admin.json'))->not->toBeFile();
    } finally {
        app()->setBasePath($originalBase);
        File::deleteDirectory(storage_path('app/legacy-platform-config'));
    }
});

test('applyFrontendUpgrade 暂存旧 platform config 供 SettingSeeder 导入', function () {
    $sourceDir = "$this->testDir/pkg/frontend/user";
    File::makeDirectory($sourceDir, 0755, true);
    File::put("$sourceDir/platform-config.json", '{"source":"new"}');

    $installDir = "$this->testDir/install";
    $targetDir = "$installDir/frontend/user";
    File::makeDirectory("$installDir/backend", 0755, true);
    File::makeDirectory($targetDir, 0755, true);
    File::put("$targetDir/platform-config.json", '{"Beian":"真实备案号","Brands":["certum"]}');

    $originalBase = base_path();
    app()->setBasePath("$installDir/backend");

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('applyFrontendUpgrade');
        $method->invoke($this->extractor, $sourceDir, 'user');

        // 旧配置在被新包覆盖前暂存到 storage（storage_path 在测试中被按 worker 隔离钉死，
        // 与实现同源取值），迁移据此导入历史定制值
        expect(File::get(storage_path('app/legacy-platform-config/user.json')))
            ->toBe('{"Beian":"真实备案号","Brands":["certum"]}')
            ->and(File::get("$targetDir/platform-config.json"))->toBe('{"source":"new"}');

        // 中断重跑：即使当前文件仍含迁移键，已存在的暂存也不得被覆盖（首跑旧值优先）
        File::put("$targetDir/platform-config.json", '{"Beian":"重跑时的新值"}');
        $method->invoke($this->extractor, $sourceDir, 'user');
        expect(File::get(storage_path('app/legacy-platform-config/user.json')))
            ->toBe('{"Beian":"真实备案号","Brands":["certum"]}');
    } finally {
        app()->setBasePath($originalBase);
        File::deleteDirectory(storage_path('app/legacy-platform-config'));
    }
});

// ==================== 升级前可写性预检（base_path 自身 + 子目录 + storage）====================

test('checkWritableBeforeApply 当 base_path 自身不可写时报错（即便所有子目录可写）', function () {
    // root 绕过文件权限位，is_writable 恒真，无法构造不可写场景 → 跳过
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        expect(true)->toBeTrue(); // 占位避免 risky

        return;
    }

    // 安装目录：自身置为只读 0555，但其下子目录与 storage 全部可写
    // —— applyBackendUpgrade 会往 base_path() 根写 artisan/composer.json/php-requirements.json，
    //    根不可写则写入必败；旧预检只查子目录会漏判，故障延后到 apply 且文案误导
    $installDir = "$this->testDir/ro_install";
    File::makeDirectory("$installDir/app", 0755, true);
    File::makeDirectory("$installDir/storage", 0755, true);

    $originalBase = base_path();
    app()->setBasePath($installDir);
    chmod($installDir, 0555); // 根只读：不能在其中创建/覆盖文件

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('checkWritableBeforeApply');
        expect(fn () => $method->invoke($this->extractor))
            ->toThrow(RuntimeException::class);
    } finally {
        chmod($installDir, 0755); // 恢复以便 afterEach 能删除
        app()->setBasePath($originalBase);
    }
});

test('checkWritableBeforeApply 全部可写时通过', function () {
    $installDir = "$this->testDir/rw_install";
    File::makeDirectory("$installDir/app", 0755, true);
    File::makeDirectory("$installDir/storage", 0755, true);

    $originalBase = base_path();
    app()->setBasePath($installDir);

    try {
        $method = (new ReflectionClass($this->extractor))->getMethod('checkWritableBeforeApply');
        // 不抛异常即通过
        $method->invoke($this->extractor);
        expect(true)->toBeTrue();
    } finally {
        app()->setBasePath($originalBase);
    }
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

// ==================== nginx render.sh 集成（Task 5: default 清空 + proc_open 调 render） ====================

test('BinaryLocator::bash 返回可执行的 bash 路径', function () {
    $locator = app(BinaryLocator::class);
    $path = $locator->bash();

    expect($path)->toContain('bash');
    expect(is_executable($path))->toBeTrue();
});

test('renderNginx wiring: stub render.sh 被 proc_open 正确调用并传入 PROJECT_ROOT', function () {
    // 搭建最小安装根（base_path → installDir/backend，使 base_path('../nginx') = installDir/nginx）
    $installDir = "$this->testDir/install_render";
    File::makeDirectory("$installDir/nginx", 0755, true);
    File::makeDirectory("$installDir/backend", 0755, true);

    // 最小 stub：仅验证 proc_open 传了正确 $1，产出一个哨兵文件
    $stub = "#!/usr/bin/env bash\nmkdir -p \"\$1/nginx/enabled/routes\"\nprintf 'ok' > \"\$1/nginx/enabled/routes/admin.conf\"\nexit 0\n";
    File::put("$installDir/nginx/render.sh", $stub);
    chmod("$installDir/nginx/render.sh", 0755);

    $originalBase = base_path();
    app()->setBasePath("$installDir/backend");

    try {
        $extractor = Mockery::mock(PackageExtractor::class)->makePartial();
        $extractor->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('getProjectRoot')->andReturn($installDir);

        $method = (new ReflectionClass($extractor))->getMethod('renderNginx');
        $method->invoke($extractor, "$installDir/nginx");

        // stub 写了哨兵文件 → 证明 proc_open 以正确的 PROJECT_ROOT=$installDir 调用了 bash render.sh
        expect("$installDir/nginx/enabled/routes/admin.conf")->toBeFile();
        expect(File::get("$installDir/nginx/enabled/routes/admin.conf"))->toBe('ok');
    } finally {
        app()->setBasePath($originalBase);
        Mockery::close();
    }
});

test('renderNginx 非致命：stub render.sh 退出非零不抛异常', function () {
    $installDir = "$this->testDir/install_nonzero";
    File::makeDirectory("$installDir/nginx", 0755, true);
    File::makeDirectory("$installDir/backend", 0755, true);

    // stub 直接 exit 1
    File::put("$installDir/nginx/render.sh", "#!/usr/bin/env bash\nexit 1\n");
    chmod("$installDir/nginx/render.sh", 0755);

    $originalBase = base_path();
    app()->setBasePath("$installDir/backend");

    try {
        $extractor = Mockery::mock(PackageExtractor::class)->makePartial();
        $extractor->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('getProjectRoot')->andReturn($installDir);

        $method = (new ReflectionClass($extractor))->getMethod('renderNginx');
        // 不应抛异常
        $method->invoke($extractor, "$installDir/nginx");

        expect(true)->toBeTrue(); // 能执行到此处即为通过
    } finally {
        app()->setBasePath($originalBase);
        Mockery::close();
    }
});

test('renderNginx 非致命：render.sh 不存在时不抛异常', function () {
    $installDir = "$this->testDir/install_missing";
    File::makeDirectory("$installDir/nginx", 0755, true);
    File::makeDirectory("$installDir/backend", 0755, true);
    // 故意不写 render.sh

    $originalBase = base_path();
    app()->setBasePath("$installDir/backend");

    try {
        $extractor = Mockery::mock(PackageExtractor::class)->makePartial();
        $extractor->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('getProjectRoot')->andReturn($installDir);

        $method = (new ReflectionClass($extractor))->getMethod('renderNginx');
        // 不应抛异常
        $method->invoke($extractor, "$installDir/nginx");

        expect(true)->toBeTrue();
    } finally {
        app()->setBasePath($originalBase);
        Mockery::close();
    }
});

test('applyNginxUpgrade 先清 default 再 sync 再调 render（旧残留 zzz_stale.conf 被清除、admin.conf 同步落地）', function () {
    $installDir = "$this->testDir/install_wipe";
    File::makeDirectory("$installDir/nginx/default/routes", 0755, true);
    File::makeDirectory("$installDir/backend", 0755, true);

    // 预置旧残留文件（旧版才有、新包不包含）
    File::put("$installDir/nginx/default/routes/zzz_stale.conf", "location /stale { return 404; }\n");

    // 升级包 source nginx 目录：含新 admin.conf + stub render.sh（exit 0 即可，不需要真渲染）
    $sourceDir = "$this->testDir/source_nginx";
    File::makeDirectory("$sourceDir/default/routes", 0755, true);
    File::put("$sourceDir/default/routes/admin.conf", "location ^~ /admin { }\n");
    File::put("$sourceDir/render.sh", "#!/usr/bin/env bash\nexit 0\n");
    chmod("$sourceDir/render.sh", 0755);

    $originalBase = base_path();
    app()->setBasePath("$installDir/backend");

    try {
        $extractor = Mockery::mock(PackageExtractor::class)->makePartial();
        $extractor->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('getProjectRoot')->andReturn($installDir);

        $method = (new ReflectionClass($extractor))->getMethod('applyNginxUpgrade');
        $method->invoke($extractor, $sourceDir);

        // deleteDirectory(default) 清除旧残留（K2）
        expect("$installDir/nginx/default/routes/zzz_stale.conf")->not->toBeFile();
        // sync 把新包 admin.conf 落地
        expect("$installDir/nginx/default/routes/admin.conf")->toBeFile();
    } finally {
        app()->setBasePath($originalBase);
        Mockery::close();
    }
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

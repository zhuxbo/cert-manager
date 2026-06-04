<?php

use App\Services\Upgrade\ArchiveGuard;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmp = storage_path('test-archive-guard-'.uniqid());
    File::makeDirectory($this->tmp, 0755, true);
});

afterEach(function () {
    if (File::isDirectory($this->tmp)) {
        File::deleteDirectory($this->tmp);
    }
});

/**
 * 构造一个含指定条目的 ZIP，返回 path。
 *
 * @param  array<string, string>  $entries  条目名 => 内容
 */
function buildZip(string $path, array $entries): string
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

function openZip(string $path): ZipArchive
{
    $zip = new ZipArchive;
    $zip->open($path);

    return $zip;
}

test('assertSafeEntries 放行正常条目', function () {
    $zip = openZip(buildZip("$this->tmp/ok.zip", [
        'app/Foo.php' => '<?php',
        'config/app.php' => '<?php',
        '.env' => 'KEY=1',
    ]));

    ArchiveGuard::assertSafeEntries($zip);
    $zip->close();

    expect(true)->toBeTrue();
});

test('assertSafeEntries 拒绝 .. 路径遍历条目', function () {
    $zip = openZip(buildZip("$this->tmp/evil.zip", [
        'app/Foo.php' => '<?php',
        '../../../etc/passwd' => 'root::0:0',
    ]));

    expect(fn () => ArchiveGuard::assertSafeEntries($zip))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
    $zip->close();
});

test('assertSafeEntries 拒绝任意含 .. 段的条目', function () {
    $zip = openZip(buildZip("$this->tmp/evil2.zip", [
        'app/../../escape.php' => 'x',
    ]));

    expect(fn () => ArchiveGuard::assertSafeEntries($zip))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
    $zip->close();
});

test('assertSafeEntries 拒绝绝对路径条目', function () {
    $zip = openZip(buildZip("$this->tmp/abs.zip", [
        '/etc/cron.d/evil' => 'x',
    ]));

    expect(fn () => ArchiveGuard::assertSafeEntries($zip))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
    $zip->close();
});

test('assertSafeEntries 拒绝反斜杠条目（Windows 风格绕过）', function () {
    $zip = openZip(buildZip("$this->tmp/bs.zip", [
        'app\\..\\..\\escape.php' => 'x',
    ]));

    expect(fn () => ArchiveGuard::assertSafeEntries($zip))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
    $zip->close();
});

test('assertSafeEntries 白名单精确放行 ../version.json', function () {
    $zip = openZip(buildZip("$this->tmp/wl.zip", [
        'app/Foo.php' => '<?php',
        '../version.json' => '{"version":"1.0.0"}',
    ]));

    // 不带白名单 → 拒绝
    expect(fn () => ArchiveGuard::assertSafeEntries($zip))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');

    // 带白名单 → 放行
    ArchiveGuard::assertSafeEntries($zip, ['../version.json']);
    $zip->close();

    expect(true)->toBeTrue();
});

test('assertSafeEntries 白名单不放行其他 .. 条目', function () {
    $zip = openZip(buildZip("$this->tmp/wl2.zip", [
        '../version.json' => '{}',
        '../../etc/passwd' => 'x',
    ]));

    expect(fn () => ArchiveGuard::assertSafeEntries($zip, ['../version.json']))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
    $zip->close();
});

test('assertSafeEntries 拒绝符号链接条目', function () {
    $path = "$this->tmp/symlink.zip";
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('link', '/etc/passwd');
    // 设 Unix 符号链接模式位（S_IFLNK 0xA000 | 0777），external attr 高 16 位为 mode
    $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0xA000 | 0777) << 16);
    $zip->close();

    $opened = openZip($path);
    expect(fn () => ArchiveGuard::assertSafeEntries($opened))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');
    $opened->close();
});

test('assertSafeEntries 普通 Unix 文件不误判为符号链接', function () {
    $path = "$this->tmp/regular.zip";
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('app/Foo.php', '<?php');
    // 普通文件 S_IFREG 0x8000 | 0644
    $zip->setExternalAttributesName('app/Foo.php', ZipArchive::OPSYS_UNIX, (0x8000 | 0644) << 16);
    $zip->close();

    $opened = openZip($path);
    ArchiveGuard::assertSafeEntries($opened);
    $opened->close();

    expect(true)->toBeTrue();
});

test('assertExtractedWithin 放行落在目标内的产物', function () {
    $base = "$this->tmp/base";
    File::makeDirectory("$base/app", 0755, true);
    File::put("$base/app/Foo.php", '<?php');

    ArchiveGuard::assertExtractedWithin($base, ['app/Foo.php', 'app/']);

    expect(true)->toBeTrue();
});

test('assertExtractedWithin 检测越界产物（软链接指向目标外）', function () {
    $base = "$this->tmp/base2";
    $outside = "$this->tmp/outside";
    File::makeDirectory($base, 0755, true);
    File::makeDirectory($outside, 0755, true);
    File::put("$outside/secret", 'x');

    // 在 base 内建一个指向外部目录的软链接，realpath 解析后越界
    symlink($outside, "$base/escape");

    expect(fn () => ArchiveGuard::assertExtractedWithin($base, ['escape']))
        ->toThrow(RuntimeException::class, 'ZIP 解压产物越界');
});

test('assertExtractedWithin 忽略不存在的产物与白名单条目', function () {
    $base = "$this->tmp/base3";
    File::makeDirectory($base, 0755, true);

    // 不存在的产物（解压失败）不报错；白名单条目跳过
    ArchiveGuard::assertExtractedWithin($base, ['missing.php', '../version.json'], ['../version.json']);

    expect(true)->toBeTrue();
});

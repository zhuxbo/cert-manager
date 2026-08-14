<?php

use App\Services\Composer\ComposerVendorBundle;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/composer-vendor-bundle-*') as $directory) {
        File::deleteDirectory($directory);
    }
});

function makeComposerVendorBundleDirectory(): string
{
    $directory = sys_get_temp_dir().'/composer-vendor-bundle-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("$directory/vendor/composer");
    File::put("$directory/composer.lock", 'LOCK-A');
    File::put("$directory/vendor/autoload.php", '<?php');

    return $directory;
}

test('writeMarker 原子写入当前 composer.lock 哈希并可通过校验', function () {
    $directory = makeComposerVendorBundleDirectory();
    File::put("$directory/vendor/composer/.ssl-manager-lock.sha256", 'STALE');

    ComposerVendorBundle::writeMarker($directory);

    expect(trim(File::get("$directory/vendor/composer/.ssl-manager-lock.sha256")))
        ->toBe(hash('sha256', 'LOCK-A'))
        ->and(ComposerVendorBundle::matchesLock($directory))->toBeTrue()
        ->and(glob("$directory/vendor/composer/.ssl-manager-lock.sha256.tmp-*"))->toBe([]);
});

test('writeMarker 缺少 lock 或 autoload 时失败关闭且不留下临时文件', function () {
    $directory = makeComposerVendorBundleDirectory();
    File::delete("$directory/vendor/autoload.php");

    expect(fn () => ComposerVendorBundle::writeMarker($directory))
        ->toThrow(RuntimeException::class, 'composer.lock 或 vendor/autoload.php 不存在');
    expect(glob("$directory/vendor/composer/.ssl-manager-lock.sha256.tmp-*"))->toBe([]);
});

<?php

use App\Services\Plugin\PluginZipInspector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/pzi-*.zip') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob(storage_path('app/plugin-zip-inspect-*')) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

function makeInspectorZip(array $entries): string
{
    $zipPath = sys_get_temp_dir().'/pzi-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $zipPath;
}

test('inspect 只读取嵌套 plugin.json 元数据', function () {
    $zip = makeInspectorZip([
        'demo-plugin/plugin.json' => json_encode(['name' => 'demo-plugin', 'version' => '1.2.3']),
        'demo-plugin/large.bin' => str_repeat('x', 1024),
    ]);

    $meta = app(PluginZipInspector::class)->inspect($zip);

    expect($meta)->toBe(['name' => 'demo-plugin', 'version' => '1.2.3']);
});

test('inspect 拒绝非法 ZIP 路径且不残留临时解压目录', function () {
    $zip = makeInspectorZip([
        '../evil.txt' => 'evil',
        'demo-plugin/plugin.json' => json_encode(['name' => 'demo-plugin']),
    ]);

    expect(fn () => app(PluginZipInspector::class)->inspect($zip))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');

    expect(glob(storage_path('app/plugin-zip-inspect-*')) ?: [])->toBe([]);
});

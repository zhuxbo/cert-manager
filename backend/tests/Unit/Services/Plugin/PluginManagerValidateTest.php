<?php

use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;

uses(Tests\TestCase::class);

/**
 * PluginManager::validatePlugin 的 php_ext 校验
 *
 * 用 anonymous subclass 暴露 protected validatePlugin 给测试调用。
 */
function makeValidator(): object
{
    return new class(app(VersionManager::class)) extends PluginManager
    {
        public function callValidate(string $path, ?string $expectedName = null): void
        {
            // 测试用：把临时插件目录的父目录设为 downloadPath，使 realpath 检查通过
            $this->downloadPath = dirname($path);
            $this->validatePlugin($path, $expectedName);
        }
    };
}

function writePluginJson(array $manifest): string
{
    $tmp = sys_get_temp_dir().'/plugin-validate-'.uniqid();
    mkdir($tmp);
    file_put_contents($tmp.'/plugin.json', json_encode($manifest));

    return $tmp;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/plugin-validate-*') as $dir) {
        @unlink($dir.'/plugin.json');
        @rmdir($dir);
    }
});

test('php_ext 为空数组时不校验', function () {
    $path = writePluginJson([
        'name' => 'no_ext_plugin',
        'requires' => '>=0.4.0',
        'php_ext' => [],
    ]);

    $validator = makeValidator();

    expect(fn () => $validator->callValidate($path, 'no_ext_plugin'))->not->toThrow(Exception::class);
});

test('php_ext 含已加载扩展时通过', function () {
    $path = writePluginJson([
        'name' => 'json_plugin',
        'requires' => '>=0.4.0',
        'php_ext' => ['json'], // PHP 内置必加载
    ]);

    $validator = makeValidator();

    expect(fn () => $validator->callValidate($path, 'json_plugin'))->not->toThrow(Exception::class);
});

test('php_ext 含未加载扩展时拒绝并报缺失列表', function () {
    $path = writePluginJson([
        'name' => 'fake_ext_plugin',
        'requires' => '>=0.4.0',
        'php_ext' => ['definitely_not_loaded_extension', 'another_fake_ext'],
    ]);

    $validator = makeValidator();

    expect(fn () => $validator->callValidate($path, 'fake_ext_plugin'))
        ->toThrow(RuntimeException::class, '插件依赖的 PHP 扩展未加载');
});

test('php_ext 字段非数组报错', function () {
    $path = writePluginJson([
        'name' => 'broken_ext_plugin',
        'requires' => '>=0.4.0',
        'php_ext' => 'redis', // 应该是数组
    ]);

    $validator = makeValidator();

    expect(fn () => $validator->callValidate($path, 'broken_ext_plugin'))
        ->toThrow(RuntimeException::class, 'php_ext 字段必须是数组');
});

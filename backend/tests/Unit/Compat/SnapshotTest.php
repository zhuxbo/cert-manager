<?php

declare(strict_types=1);

use Tests\Compat\Helpers;

/**
 * Snapshot 测试自检（meta test）。
 *
 * 真正的"对照"运行在 compare 模式下 — 由 Tests\TestCase setUp/tearDown 注册的
 * SnapshotListener 在所有 Feature/Http/Controllers 测试跑过 API 时自动比对 fixture。
 *
 * 本文件做"基础设施健康检查"：
 * 1. fixtures 目录存在
 * 2. fixtures 不为空（至少 50 个，避免 fixture 全丢失但 CI 假绿）
 * 3. fixture JSON 格式合法
 *
 * 这些检查跑得很快（仅静态文件 IO），不需 DB。
 */
test('Compat fixture 目录存在', function () {
    $dir = __DIR__.'/../../Compat/fixtures';
    expect(is_dir($dir))->toBeTrue('fixtures 目录应存在');
});

test('Compat fixture 数量 >= 50', function () {
    $dir = __DIR__.'/../../Compat/fixtures';
    if (! is_dir($dir)) {
        $this->markTestSkipped('fixtures 目录不存在，capture 模式从未跑过'); // @phpstan-ignore-line
    }
    $count = count(glob($dir.'/*.json') ?: []);
    expect($count)->toBeGreaterThanOrEqual(50, "fixture 数量异常少：{$count}（预期 ≥ 50）");
});

test('Compat fixture JSON 格式合法', function () {
    $dir = __DIR__.'/../../Compat/fixtures';
    $files = glob($dir.'/*.json') ?: [];
    if (count($files) === 0) {
        $this->markTestSkipped('fixtures 目录为空，跳过'); // @phpstan-ignore-line
    }
    $invalid = [];
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            $invalid[] = "无法读取：$file";

            continue;
        }
        $decoded = json_decode($content, true);
        if (! is_array($decoded)
            || ! isset($decoded['test'])
            || ! isset($decoded['calls'])
            || ! is_array($decoded['calls'])
        ) {
            $invalid[] = "结构非法：$file";
        }
    }
    expect($invalid)->toBe([], "存在非法 fixture：\n".implode("\n", array_slice($invalid, 0, 5)));
});

test('SnapshotListener 已加载', function () {
    expect(class_exists(\Tests\Compat\SnapshotListener::class))->toBeTrue();
    expect(class_exists(\Tests\Compat\SchemaDiffer::class))->toBeTrue();
    expect(function_exists('expectsBreakingChange'))->toBeTrue('expectsBreakingChange() 全局函数应存在');
});

test('Compat Helpers 工作模式判定正常', function () {
    // 模式状态由 env 控制；这里仅断言函数可调用、返回 bool
    expect(Helpers::isCaptureMode())->toBeBool();
    expect(Helpers::isCompareMode())->toBeBool();
});

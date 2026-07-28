<?php

declare(strict_types=1);

/**
 * 孤儿 compat fixture 检测（静态反查，无副作用）
 *
 * 孤儿 = fixture 文件存在，但它记录的那个测试已被删除或改名。
 * 这类文件永远不参与 compare 比对（compare 只查「测试有没有 fixture」，不查反向），
 * 既不会报错也不会被清理，改一次测试名就沉积一个。
 *
 * 为什么不用 capture + mtime 差集：那需要实跑一次全量 capture（慢、且会重复追加
 * BREAKING_CHANGES.md 与抖动 Metrics 键序两处副作用需回滚），且只能覆盖 capture 的目标目录
 * `tests/Feature/Http/Controllers/`。本脚本纯静态比对，无副作用，且覆盖 fixtures 全集。
 *
 * 判定方法：fixture 的 `test` 字段形如
 *   P\Tests\Feature\Http\Controllers\Xxx\YyyTest::__pest_evaluable_<名字>
 *   P\Tests\...::__pest_evaluable_<名字>@dataset "key" with data (...)   ← 数据集用例
 * 取 `::` 前的类名反推 PHP 文件，取 `__pest_evaluable_` 后、`@dataset` 前的部分作为
 * 期望的 evaluable 名，再把该文件里所有 test()/it() 的名字过一遍 Pest 的
 * `Str::evaluable()` 求交集。零匹配即孤儿。
 *
 * 用 Pest 自己的 Str::evaluable 而非手写转换：它是 fixture 名的唯一真相源
 * （注意不是 Illuminate\Support\Str，那个类没有 evaluable 方法）。
 *
 * 退出码：0 = 无孤儿；1 = 发现孤儿（清单打到 stdout）。
 */

use Pest\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$fixtureDir = __DIR__.'/fixtures';
$testsRoot = dirname(__DIR__);          // .../backend/tests
$projectRoot = dirname($testsRoot);     // .../backend

/** @var array<string, list<string>> 每个测试文件的 evaluable 名字缓存 */
$evaluableCache = [];

/**
 * 提取一个测试文件里所有 test()/it() 的名字，转成 evaluable 形态。
 *
 * @return list<string>
 */
function evaluableNamesOf(string $phpPath, array &$cache): array
{
    if (isset($cache[$phpPath])) {
        return $cache[$phpPath];
    }

    $src = @file_get_contents($phpPath);
    if ($src === false) {
        return $cache[$phpPath] = [];
    }

    // 匹配 test('...') / it("...")，允许调用前有缩进（describe() 块内嵌套）。
    // 反斜杠转义的同类引号用 (?:[^'\\]|\\.)* 吃掉，避免在名字含 \' 时截断。
    preg_match_all(
        '/(?:^|\s)(test|it)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/m',
        $src,
        $m,
        PREG_SET_ORDER
    );

    $names = [];
    foreach ($m as $set) {
        $fn = $set[1];
        $raw = ($set[2] ?? '') !== '' ? $set[2] : ($set[3] ?? '');
        if ($raw === '') {
            continue;
        }
        // 还原 PHP 单/双引号字面量里的转义，否则含 \' 的名字算出的 evaluable 会错
        $literal = stripcslashes($raw);
        // it('foo') 的方法名是 `it foo` 的 evaluable（Pest 给 it() 补 "it " 描述前缀），
        // test('foo') 则直接用原名 —— 不区分会把所有 it() 用例误判成孤儿
        $names[] = Str::evaluable($fn === 'it' ? 'it '.$literal : $literal);
    }

    return $cache[$phpPath] = $names;
}

$files = glob($fixtureDir.'/*.json') ?: [];
sort($files);

$orphans = [];
$unparsable = [];
$checked = 0;

foreach ($files as $file) {
    $decoded = json_decode((string) file_get_contents($file), true);
    $testName = is_array($decoded) ? ($decoded['test'] ?? null) : null;

    if (! is_string($testName) || ! str_contains($testName, '::')) {
        $unparsable[] = basename($file);

        continue;
    }

    $checked++;

    [$class, $method] = explode('::', $testName, 2);
    $class = preg_replace('/^P\\\\/', '', $class) ?? $class;

    // Tests\Feature\Http\... → <backend>/tests/Feature/Http/....php
    $relative = str_replace('\\', '/', preg_replace('/^Tests\\\\/', 'tests/', $class) ?? '');
    $phpPath = $projectRoot.'/'.$relative.'.php';

    if (! is_file($phpPath)) {
        $orphans[] = [basename($file), '测试类文件已不存在: '.$relative.'.php'];

        continue;
    }

    // 不剥 `__pest_evaluable_` 前缀：Str::evaluable() 的返回值本身就带这个前缀，
    // 两边必须同为完整方法名才能比对（剥掉会永不匹配、把所有 fixture 误判成孤儿）。
    // 数据集用例：`<方法名>@dataset "key" with data (...)`，截到 @dataset 之前
    $expected = $method;
    if (($pos = strpos($expected, '@dataset')) !== false) {
        $expected = substr($expected, 0, $pos);
    }

    if (! in_array($expected, evaluableNamesOf($phpPath, $evaluableCache), true)) {
        $orphans[] = [basename($file), '测试已删除或改名: '.$class.'::'.$expected];
    }
}

foreach ($unparsable as $name) {
    echo "WARN: fixture 无法解析 test 字段（已跳过）: $name\n";
}

foreach ($orphans as [$name, $why]) {
    echo "ORPHAN: $name\n";
    echo "        └─ $why\n";
}

echo "\n";
echo '检查 '.$checked.' 个 fixture / 孤儿 '.count($orphans).' 个'
    .($unparsable ? ' / 无法解析 '.count($unparsable).' 个' : '')."\n";

if ($orphans) {
    echo "\n修复：确认对应测试是被改名还是删除——改名则新名的 fixture 通常已存在（capture 会生成），\n";
    echo "直接删掉旧的；删除则同样删掉。删完跑 COMPAT_COMPARE=true 确认无 fixture_missing。\n";

    exit(1);
}

exit(0);

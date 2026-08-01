<?php

use function Tests\Compat\withoutPestDatasetSuffix;

require_once __DIR__.'/../../Compat/OrphanFixtureMethod.php';

test('测试名自身包含 dataset 或 with data 文字时不剥离', function (string $method) {
    expect(withoutPestDatasetSuffix($method))->toBe($method);
})->with([
    'dataset 紧接中文' => ['__pest_evaluable_合法测试@dataset文字'],
    'dataset 缺少数据说明' => ['__pest_evaluable_合法测试@dataset "key"'],
    'dataset 数据说明不是完整括号后缀' => ['__pest_evaluable_合法测试@dataset "key" with data 文字'],
    'with data 不是完整后缀' => ['__pest_evaluable_合法测试@标记 with data 文字'],
    '直接数组缺少数据说明' => ["__pest_evaluable_合法测试@('value') with data 文字"],
]);

test('命名 dataset 的完整 Pest 数据后缀会剥离', function () {
    expect(withoutPestDatasetSuffix(
        '__pest_evaluable_合法测试@dataset "key" with data (\'value\')'
    ))->toBe('__pest_evaluable_合法测试');
});

test('直接数组 dataset 的完整 Pest 数据后缀会剥离', function () {
    expect(withoutPestDatasetSuffix(
        "__pest_evaluable_合法测试@('value') with data ('value')"
    ))->toBe('__pest_evaluable_合法测试');
});

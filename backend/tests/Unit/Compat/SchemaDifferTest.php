<?php

declare(strict_types=1);

use Tests\Compat\SchemaDiffer;

require_once __DIR__.'/../../Compat/SchemaDiffer.php';

// =================== extractSchema ===================

test('extractSchema 标量类型', function () {
    expect(SchemaDiffer::extractSchema(null))->toBe('null');
    expect(SchemaDiffer::extractSchema(true))->toBe('boolean');
    expect(SchemaDiffer::extractSchema(false))->toBe('boolean');
    expect(SchemaDiffer::extractSchema(42))->toBe('integer');
    expect(SchemaDiffer::extractSchema(3.14))->toBe('float');
    expect(SchemaDiffer::extractSchema('hello'))->toBe('string');
});

test('extractSchema 关联数组', function () {
    $schema = SchemaDiffer::extractSchema([
        'id' => 1,
        'name' => 'foo',
        'enabled' => true,
        'meta' => null,
    ]);

    expect($schema)->toBe([
        'id' => 'integer',
        'name' => 'string',
        'enabled' => 'boolean',
        'meta' => 'null',
    ]);
});

test('extractSchema 标量列表', function () {
    expect(SchemaDiffer::extractSchema([1, 2, 3]))->toBe('array<integer>');
    expect(SchemaDiffer::extractSchema(['a', 'b']))->toBe('array<string>');
    expect(SchemaDiffer::extractSchema([]))->toBe('array<unknown>');
});

test('extractSchema 对象列表合并 keys', function () {
    $schema = SchemaDiffer::extractSchema([
        ['id' => 1, 'name' => 'a'],
        ['id' => 2, 'name' => 'b', 'extra' => 'c'],  // 第 2 条多个 extra
        ['id' => 3, 'name' => null],                  // 第 3 条 name 是 null
    ]);

    expect($schema)->toHaveKey('__list_of__');
    expect($schema['__list_of__'])->toHaveKey('id');
    expect($schema['__list_of__'])->toHaveKey('name');
    expect($schema['__list_of__'])->toHaveKey('extra');
    expect($schema['__list_of__']['id'])->toBe('integer');
    expect($schema['__list_of__']['name'])->toBe('string');
    expect($schema['__list_of__']['extra'])->toBe('string');
});

test('extractSchema 嵌套结构', function () {
    $schema = SchemaDiffer::extractSchema([
        'data' => [
            'list' => [
                ['id' => 1, 'tags' => ['a', 'b']],
            ],
            'page' => 1,
            'total' => 100,
        ],
        'code' => 1,
    ]);

    expect($schema['code'])->toBe('integer');
    expect($schema['data']['page'])->toBe('integer');
    expect($schema['data']['total'])->toBe('integer');
    expect($schema['data']['list'])->toHaveKey('__list_of__');
    expect($schema['data']['list']['__list_of__']['id'])->toBe('integer');
    expect($schema['data']['list']['__list_of__']['tags'])->toBe('array<string>');
});

// =================== diff ===================

test('diff 完全相同返回空', function () {
    $a = ['code' => 'integer', 'data' => ['list' => 'array<integer>']];
    expect(SchemaDiffer::diff($a, $a))->toBe([]);
});

test('diff 检测 missing 字段', function () {
    $expected = ['id' => 'integer', 'name' => 'string', 'email' => 'string'];
    $actual = ['id' => 'integer', 'name' => 'string'];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('missing');
    expect($diffs[0]['path'])->toBe('$.email');
});

test('diff 检测 added 字段', function () {
    $expected = ['id' => 'integer'];
    $actual = ['id' => 'integer', 'new_field' => 'string'];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('added');
    expect($diffs[0]['path'])->toBe('$.new_field');
});

test('diff 检测类型变化', function () {
    $expected = ['id' => 'integer', 'name' => 'string'];
    $actual = ['id' => 'string', 'name' => 'string'];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('type_changed');
    expect($diffs[0]['path'])->toBe('$.id');
    expect($diffs[0]['expected'])->toBe('integer');
    expect($diffs[0]['actual'])->toBe('string');
});

test('diff 嵌套路径正确', function () {
    $expected = ['data' => ['user' => ['id' => 'integer', 'email' => 'string']]];
    $actual = ['data' => ['user' => ['id' => 'integer']]];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('missing');
    expect($diffs[0]['path'])->toBe('$.data.user.email');
});

test('diff 列表内字段变化', function () {
    $expected = ['list' => ['__list_of__' => ['id' => 'integer', 'name' => 'string']]];
    $actual = ['list' => ['__list_of__' => ['id' => 'integer']]];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('missing');
    expect($diffs[0]['path'])->toBe('$.list.[].name');
});

test('diff null↔scalar 严格报告（不再容忍）', function () {
    // 设计变更（reviewer 问题 3）：diff 阶段不再做 null↔scalar 双向容忍。
    // null 在 fixture 里几乎不会出现 — extractSchema 的对象列表合并阶段优先非 null 覆盖。
    // 真出现 expected='null' / actual='integer' 时应报 type_changed，提示用户重新 capture。
    $expected = ['count' => 'integer'];
    $actual = ['count' => 'null'];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('type_changed');
    expect($diffs[0]['expected'])->toBe('integer');
    expect($diffs[0]['actual'])->toBe('null');

    $expected2 = ['count' => 'null'];
    $actual2 = ['count' => 'integer'];
    $diffs2 = SchemaDiffer::diff($expected2, $actual2);
    expect($diffs2)->toHaveCount(1);
    expect($diffs2[0]['kind'])->toBe('type_changed');
});

test('extractSchema 列表合并优先非 null（修复 fixture null 占位）', function () {
    // 列表里首条记录某字段为 null，后续记录该字段有值；
    // extractSchema 应把字段 schema 设为非 null（避免 fixture 把 null 当作字段类型固化）。
    $value = [
        ['id' => 1, 'name' => null],
        ['id' => 2, 'name' => 'Alice'],
        ['id' => 3, 'name' => null],
    ];
    $schema = SchemaDiffer::extractSchema($value);
    expect($schema)->toBe([
        '__list_of__' => [
            'id' => 'integer',
            'name' => 'string',  // 非 null 优先（首条为 null 但被覆盖）
        ],
    ]);
});

test('diff 列表内对象 vs 标量列表 报变化', function () {
    $expected = ['data' => ['__list_of__' => ['id' => 'integer']]];
    $actual = ['data' => 'array<string>'];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect($diffs)->not->toBe([]);
    // 对象 schema vs 字符串 schema → type_changed
    expect($diffs[0]['kind'])->toBe('type_changed');
});

test('diff null 与 null 视为相等（fixture 缺响应 body 场景）', function () {
    expect(SchemaDiffer::diff(null, null))->toBe([]);
});

test('diff null vs 非 null 报 missing/added', function () {
    $actual = ['id' => 'integer'];
    $diffs = SchemaDiffer::diff(null, $actual);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['kind'])->toBe('added');

    $diffs2 = SchemaDiffer::diff($actual, null);
    expect($diffs2)->toHaveCount(1);
    expect($diffs2[0]['kind'])->toBe('missing');
});

test('diff 多差异同时报', function () {
    $expected = [
        'id' => 'integer',
        'name' => 'string',
        'old_field' => 'string',
    ];
    $actual = [
        'id' => 'string',  // type_changed
        'name' => 'string',
        'new_field' => 'integer',  // added
        // old_field 被删 → missing
    ];
    $diffs = SchemaDiffer::diff($expected, $actual);
    expect(count($diffs))->toBe(3);
    $kinds = array_column($diffs, 'kind');
    sort($kinds);
    expect($kinds)->toBe(['added', 'missing', 'type_changed']);
});

// =================== diffRequestKeys ===================

test('diffRequestKeys 相同 key 列表无差异', function () {
    expect(SchemaDiffer::diffRequestKeys(['email', 'id'], ['email', 'id']))->toBe([]);
});

test('diffRequestKeys 两侧均无入参无差异', function () {
    expect(SchemaDiffer::diffRequestKeys(null, null))->toBe([]);
});

test('diffRequestKeys 新增入参 key 报 request_keys_changed', function () {
    $diffs = SchemaDiffer::diffRequestKeys(['id'], ['id', 'mode']);
    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]['kind'])->toBe('request_keys_changed')
        ->and($diffs[0]['path'])->toBe('$.request_keys')
        ->and($diffs[0]['expected'])->toBe(['id'])
        ->and($diffs[0]['actual'])->toBe(['id', 'mode']);
});

test('diffRequestKeys 丢失入参 key 报 request_keys_changed', function () {
    $diffs = SchemaDiffer::diffRequestKeys(['email', 'id'], ['id']);
    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]['kind'])->toBe('request_keys_changed');
});

test('diffRequestKeys 从无入参变成有入参报差异', function () {
    $diffs = SchemaDiffer::diffRequestKeys(null, ['mode']);
    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]['expected'])->toBeNull()
        ->and($diffs[0]['actual'])->toBe(['mode']);
});

test('diffRequestKeys 入参全部消失报差异', function () {
    $diffs = SchemaDiffer::diffRequestKeys(['mode'], null);
    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]['actual'])->toBeNull();
});

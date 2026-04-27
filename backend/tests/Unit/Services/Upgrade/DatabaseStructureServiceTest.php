<?php

use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Support\Facades\Config;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->service = new DatabaseStructureService;
});

test('is column different detects type change', function () {
    $standard = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];
    $current = [
        'type' => 'text',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isColumnDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is column different detects nullable change', function () {
    $standard = [
        'type' => 'varchar(255)',
        'nullable' => true,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];
    $current = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isColumnDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is column different detects extra change', function () {
    $standard = [
        'type' => 'bigint unsigned',
        'nullable' => false,
        'default' => null,
        'extra' => 'auto_increment',
        'comment' => '',
    ];
    $current = [
        'type' => 'bigint unsigned',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isColumnDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is column different ignores comment by default', function () {
    Config::set('upgrade.behavior.strict_comment_check', false);

    $standard = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '用户名',
    ];
    $current = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => 'Username',
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isColumnDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeFalse();
});

test('is column different checks comment when strict', function () {
    Config::set('upgrade.behavior.strict_comment_check', true);

    $standard = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '用户名',
    ];
    $current = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => 'Username',
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isColumnDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is index different detects column change', function () {
    $standard = [
        'unique' => true,
        'type' => 'BTREE',
        'columns' => ['email'],
        'sub_parts' => [null],
    ];
    $current = [
        'unique' => true,
        'type' => 'BTREE',
        'columns' => ['username'],
        'sub_parts' => [null],
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isIndexDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is index different detects unique change', function () {
    $standard = [
        'unique' => true,
        'type' => 'BTREE',
        'columns' => ['email'],
        'sub_parts' => [null],
    ];
    $current = [
        'unique' => false,
        'type' => 'BTREE',
        'columns' => ['email'],
        'sub_parts' => [null],
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isIndexDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is index different detects type change', function () {
    $standard = [
        'unique' => false,
        'type' => 'BTREE',
        'columns' => ['name'],
        'sub_parts' => [null],
    ];
    $current = [
        'unique' => false,
        'type' => 'FULLTEXT',
        'columns' => ['name'],
        'sub_parts' => [null],
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isIndexDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('is index different detects sub parts change', function () {
    $standard = [
        'unique' => false,
        'type' => 'BTREE',
        'columns' => ['content'],
        'sub_parts' => [255],
    ];
    $current = [
        'unique' => false,
        'type' => 'BTREE',
        'columns' => ['content'],
        'sub_parts' => [null],
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isIndexDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('escape default value handles single quotes', function () {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('escapeDefaultValue');

    $result = $method->invoke($this->service, "it's a test");
    expect($result)->toBe("it''s a test");

    $result = $method->invoke($this->service, "value 'with' quotes");
    expect($result)->toBe("value ''with'' quotes");
});

test('escape default value handles multiple quotes', function () {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('escapeDefaultValue');

    $result = $method->invoke($this->service, "'''");
    expect($result)->toBe("''''''");
});

test('generate add column with special default', function () {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('generateAddColumnStatement');

    $columnDef = [
        'type' => 'varchar(255)',
        'nullable' => false,
        'default' => "it's default",
        'extra' => '',
        'comment' => "it's comment",
    ];

    $result = $method->invoke($this->service, 'test_table', 'test_column', $columnDef);

    expect($result)->toContain("DEFAULT 'it''s default'");
    expect($result)->toContain("COMMENT 'it''s comment'");
});

test('normalize integer type removes display width', function () {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('normalizeIntegerType');

    expect($method->invoke($this->service, 'int(11)'))->toBe('int');
    expect($method->invoke($this->service, 'bigint(20) unsigned'))->toBe('bigint unsigned');
    expect($method->invoke($this->service, 'tinyint(1)'))->toBe('tinyint');
    expect($method->invoke($this->service, 'varchar(255)'))->toBe('varchar(255)');
    expect($method->invoke($this->service, 'bigint unsigned'))->toBe('bigint unsigned');
});

test('is column different ignores integer display width', function () {
    $standard = [
        'type' => 'bigint unsigned',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];
    $current = [
        'type' => 'bigint(20) unsigned',
        'nullable' => false,
        'default' => null,
        'extra' => '',
        'comment' => '',
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isColumnDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeFalse();
});

test('describe column differences reports type change', function () {
    $standard = ['type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''];
    $current = ['type' => 'text', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''];

    $result = $this->service->describeColumnDifferences($standard, $current);

    expect($result)->toContain('类型 text => varchar(255)');
});

test('describe column differences reports extra change', function () {
    $standard = ['type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''];
    $current = ['type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment', 'comment' => ''];

    $result = $this->service->describeColumnDifferences($standard, $current);

    expect($result)->toContain('Extra auto_increment => (无)');
});

test('describe column differences ignores integer display width', function () {
    $standard = ['type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''];
    $current = ['type' => 'bigint(20) unsigned', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''];

    $result = $this->service->describeColumnDifferences($standard, $current);

    expect($result)->toBe('(未知差异)');
});

test('describe column differences reports multiple changes', function () {
    $standard = ['type' => 'varchar(255)', 'nullable' => true, 'default' => 'hello', 'extra' => '', 'comment' => ''];
    $current = ['type' => 'text', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''];

    $result = $this->service->describeColumnDifferences($standard, $current);

    expect($result)->toContain('类型');
    expect($result)->toContain('NOT NULL => NULL');
    expect($result)->toContain('默认值');
});

test('compare structures detects missing and extra tables', function () {
    $standard = [
        'tables' => [
            'users' => [
                'columns' => [], 'indexes' => [], 'foreign_keys' => [],
            ],
            'orders' => [
                'columns' => [], 'indexes' => [], 'foreign_keys' => [],
            ],
        ],
    ];
    $current = [
        'tables' => [
            'users' => [
                'columns' => [], 'indexes' => [], 'foreign_keys' => [],
            ],
            'logs' => [
                'columns' => [], 'indexes' => [], 'foreign_keys' => [],
            ],
        ],
    ];

    $diff = $this->service->compareStructures($standard, $current);

    expect($diff['missing_tables'])->toHaveKey('orders');
    expect($diff['extra_tables'])->toHaveKey('logs');
    expect($diff['table_differences'])->toBeEmpty();
});

test('compare structures detects column differences', function () {
    $standard = [
        'tables' => [
            'users' => [
                'columns' => [
                    'id' => ['position' => 1, 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''],
                    'name' => ['position' => 2, 'type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''],
                ],
                'indexes' => [],
                'foreign_keys' => [],
            ],
        ],
    ];
    $current = [
        'tables' => [
            'users' => [
                'columns' => [
                    'id' => ['position' => 1, 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment', 'comment' => ''],
                ],
                'indexes' => [],
                'foreign_keys' => [],
            ],
        ],
    ];

    $diff = $this->service->compareStructures($standard, $current);

    expect($diff['table_differences'])->toHaveKey('users');
    expect($diff['table_differences']['users']['missing_columns'])->toHaveKey('name');
    expect($diff['table_differences']['users']['modified_columns'])->toHaveKey('id');
});

test('generate add statements creates column and index statements', function () {
    $diff = [
        'missing_tables' => [],
        'extra_tables' => [],
        'table_differences' => [
            'users' => [
                'missing_columns' => [
                    'email' => ['type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '', 'comment' => ''],
                ],
                'missing_indexes' => [
                    'idx_email' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['email'], 'sub_parts' => [null]],
                ],
            ],
        ],
    ];

    $statements = $this->service->generateAddStatements($diff);

    expect($statements)->toHaveCount(2);
    expect($statements[0])->toContain('ADD COLUMN `email`');
    expect($statements[1])->toContain('ADD UNIQUE INDEX `idx_email`');
});

test('generate add statements skips foreign keys when flag set', function () {
    $diff = [
        'missing_tables' => [
            'orders' => [
                'engine' => 'InnoDB',
                'columns' => [
                    'id' => ['type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment', 'comment' => ''],
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['id'], 'sub_parts' => [null]],
                ],
                'foreign_keys' => [
                    'fk_user' => [
                        'columns' => ['user_id'],
                        'references' => ['table' => 'users', 'columns' => ['id']],
                        'on_delete' => 'CASCADE',
                        'on_update' => 'NO ACTION',
                    ],
                ],
            ],
        ],
        'extra_tables' => [],
        'table_differences' => [
            'products' => [
                'missing_foreign_keys' => [
                    'fk_category' => [
                        'columns' => ['category_id'],
                        'references' => ['table' => 'categories', 'columns' => ['id']],
                        'on_delete' => 'CASCADE',
                        'on_update' => 'NO ACTION',
                    ],
                ],
            ],
        ],
    ];

    $withFk = $this->service->generateAddStatements($diff, skipForeignKeys: false);
    $withoutFk = $this->service->generateAddStatements($diff, skipForeignKeys: true);

    $fkCount = fn ($stmts) => count(array_filter($stmts, fn ($s) => str_contains($s, 'FOREIGN KEY')));

    expect($fkCount($withFk))->toBe(2);
    expect($fkCount($withoutFk))->toBe(0);
});

test('generate add statements includes foreign keys from table differences', function () {
    $diff = [
        'missing_tables' => [],
        'extra_tables' => [],
        'table_differences' => [
            'orders' => [
                'missing_foreign_keys' => [
                    'fk_user' => [
                        'columns' => ['user_id'],
                        'references' => ['table' => 'users', 'columns' => ['id']],
                        'on_delete' => 'CASCADE',
                        'on_update' => 'NO ACTION',
                    ],
                ],
            ],
        ],
    ];

    $statements = $this->service->generateAddStatements($diff);

    expect($statements)->toHaveCount(1);
    expect($statements[0])->toContain('FOREIGN KEY');
    expect($statements[0])->toContain('fk_user');
});

test('compare table structure detects modified indexes', function () {
    $standard = [
        'columns' => [],
        'indexes' => [
            'idx_email' => [
                'unique' => true,
                'type' => 'BTREE',
                'columns' => ['email'],
                'sub_parts' => [null],
            ],
        ],
        'foreign_keys' => [],
    ];
    $current = [
        'columns' => [],
        'indexes' => [
            'idx_email' => [
                'unique' => false,
                'type' => 'BTREE',
                'columns' => ['email'],
                'sub_parts' => [null],
            ],
        ],
        'foreign_keys' => [],
    ];

    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('compareTableStructure');

    $result = $method->invoke($this->service, $standard, $current);

    expect($result)->toHaveKey('modified_indexes');
    expect($result['modified_indexes'])->toHaveKey('idx_email');
});

test('check returns correct diff structure', function () {
    // 此测试需要实际数据库连接
    // 如果数据库不可用，验证返回错误信息结构
    try {
        $result = $this->service->check();

        expect($result)->toHaveKey('has_diff');
        expect($result)->toHaveKey('diff');
        expect($result)->toHaveKey('summary');
        expect($result['has_diff'])->toBeBool();
        expect($result['diff'])->toBeArray();
        expect($result['summary'])->toBeArray();
    } catch (\Illuminate\Database\QueryException $e) {
        // 数据库连接不可用时跳过此测试
        test()->markTestSkipped('数据库连接不可用');
    }
});

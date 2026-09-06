<?php

use App\Console\Commands\DatabaseStructureCommand;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

uses(TestCase::class);

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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('isIndexDifferent');

    expect($method->invoke($this->service, $standard, $current))->toBeTrue();
});

test('escape default value handles single quotes', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('escapeDefaultValue');

    $result = $method->invoke($this->service, "it's a test");
    expect($result)->toBe("it''s a test");

    $result = $method->invoke($this->service, "value 'with' quotes");
    expect($result)->toBe("value ''with'' quotes");
});

test('escape default value handles multiple quotes', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('escapeDefaultValue');

    $result = $method->invoke($this->service, "'''");
    expect($result)->toBe("''''''");
});

test('generate add column with special default', function () {
    $reflection = new ReflectionClass($this->service);
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
    $reflection = new ReflectionClass($this->service);
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

    $reflection = new ReflectionClass($this->service);
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

test('describe index differences reports unique change', function () {
    $standard = ['unique' => true, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]];
    $current = ['unique' => false, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]];

    $result = $this->service->describeIndexDifferences($standard, $current);

    expect($result)->toBe('INDEX => UNIQUE');
});

test('describe index differences reports columns change', function () {
    $standard = ['unique' => false, 'type' => 'BTREE', 'columns' => ['email', 'name'], 'sub_parts' => [null, null]];
    $current = ['unique' => false, 'type' => 'BTREE', 'columns' => ['email'], 'sub_parts' => [null]];

    $result = $this->service->describeIndexDifferences($standard, $current);

    expect($result)->toContain('列 (email) => (email,name)');
});

test('describe index differences reports sub_parts change', function () {
    $standard = ['unique' => false, 'type' => 'BTREE', 'columns' => ['url'], 'sub_parts' => [null]];
    $current = ['unique' => false, 'type' => 'BTREE', 'columns' => ['url'], 'sub_parts' => [191]];

    $result = $this->service->describeIndexDifferences($standard, $current);

    expect($result)->toContain('前缀长度');
});

test('describe index differences reports multiple changes', function () {
    $standard = ['unique' => true, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]];
    $current = ['unique' => false, 'type' => 'FULLTEXT', 'columns' => ['code'], 'sub_parts' => [null]];

    $result = $this->service->describeIndexDifferences($standard, $current);

    expect($result)->toContain('INDEX => UNIQUE');
    expect($result)->toContain('类型 FULLTEXT => BTREE');
});

test('describe index differences returns unknown when identical', function () {
    $standard = ['unique' => true, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]];
    $current = ['unique' => true, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]];

    $result = $this->service->describeIndexDifferences($standard, $current);

    expect($result)->toBe('(未知差异)');
});

test('summary records modified_indexes as manual_actions', function () {
    $standard = [
        'tables' => [
            't' => [
                'columns' => [],
                'indexes' => [
                    'idx_code' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]],
                ],
                'foreign_keys' => [],
            ],
        ],
    ];
    $current = [
        'tables' => [
            't' => [
                'columns' => [],
                'indexes' => [
                    'idx_code' => ['unique' => false, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]],
                ],
                'foreign_keys' => [],
            ],
        ],
    ];

    $diff = $this->service->compareStructures($standard, $current);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('generateSummary');
    $summary = $method->invoke($this->service, $diff);

    expect($summary['modified_indexes'])->toContain('t.idx_code');
    expect($summary['can_auto_fix'])->toBeFalse();
    expect($summary['manual_actions'])->toContain('修改索引 t.idx_code');
});

test('summary keeps extra tables informational and does not block additive repairs', function () {
    $diff = [
        'missing_tables' => [
            'users' => ['columns' => [], 'indexes' => [], 'foreign_keys' => []],
        ],
        'extra_tables' => [
            'plugin_logs' => ['columns' => [], 'indexes' => [], 'foreign_keys' => []],
        ],
        'table_differences' => [],
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('generateSummary');
    $summary = $method->invoke($this->service, $diff);

    expect($summary['extra_tables'])->toBe(['plugin_logs'])
        ->and($summary['can_auto_fix'])->toBeTrue()
        ->and($summary['manual_actions'])->not->toContain('删除多余表 plugin_logs');
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

    $reflection = new ReflectionClass($this->service);
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
    } catch (QueryException $e) {
        // 数据库连接不可用时跳过此测试
        test()->markTestSkipped('数据库连接不可用');
    }
});

test('export current structure includes generation expression and table capacity metadata', function () {
    $connection = (string) config('database.default');
    if (! in_array(config("database.connections.$connection.driver"), ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped('当前连接不是 mysql/mariadb');
    }

    $structure = $this->service->exportCurrentStructure($connection);
    $table = reset($structure['tables']);
    $column = reset($table['columns']);

    expect($table)->toHaveKeys(['auto_increment', 'data_length', 'index_length'])
        ->and($column)->toHaveKey('generation_expression');
});

test('current structure export still excludes migrations from program structure checks', function () {
    $connection = (string) config('database.default');
    if (! in_array(config("database.connections.$connection.driver"), ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped('当前连接不是 mysql/mariadb');
    }

    expect(Schema::connection($connection)->hasTable('migrations'))->toBeTrue();
    Config::set('upgrade.exclude_tables', ['migrations']);

    expect($this->service->exportCurrentStructure($connection)['tables'])->not->toHaveKey('migrations');
});

test('backup structure export includes migrations when it is a physical table', function () {
    $connection = (string) config('database.default');
    if (! in_array(config("database.connections.$connection.driver"), ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped('当前连接不是 mysql/mariadb');
    }

    expect(Schema::connection($connection)->hasTable('migrations'))->toBeTrue();
    Config::set('upgrade.exclude_tables', ['migrations']);

    expect($this->service->exportBackupStructure($connection)['tables'])->toHaveKey('migrations');
});

test('upgrade comparison ignores restore-only column metadata', function () {
    $base = [
        'columns' => [
            'code' => [
                'position' => 1,
                'type' => 'varchar(32)',
                'nullable' => false,
                'default' => null,
                'extra' => '',
                'comment' => '',
                'character_set' => 'utf8mb4',
                'generation_expression' => '',
            ],
        ],
        'indexes' => [
            'idx_code' => ['unique' => true, 'type' => 'BTREE', 'columns' => ['code'], 'sub_parts' => [null]],
        ],
        'foreign_keys' => [],
    ];

    $standard = ['tables' => ['samples' => $base + [
        'auto_increment' => 5,
        'data_length' => 1024,
        'index_length' => 512,
    ]]];
    $current = ['tables' => ['samples' => $base + [
        'auto_increment' => 999,
        'data_length' => 2048,
        'index_length' => 1024,
    ]]];

    $current['tables']['samples']['columns']['code']['generation_expression'] = 'upper(`code`)';
    $current['tables']['samples']['columns']['code']['character_set'] = 'latin1';

    expect($this->service->compareStructures($standard, $current)['table_differences'])->toBeEmpty();
});

test('backup schema comparison detects recorded generated expression and character set changes', function () {
    $base = [
        'columns' => [
            'code' => [
                'position' => 1,
                'type' => 'varchar(32)',
                'nullable' => false,
                'default' => null,
                'extra' => '',
                'comment' => '',
                'character_set' => 'utf8mb4',
                'generation_expression' => '',
            ],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ];
    $standard = ['tables' => ['samples' => $base]];
    $current = $standard;

    $current['tables']['samples']['columns']['code']['generation_expression'] = 'upper(`code`)';
    expect($this->service->compareBackupStructures($standard, $current)['table_differences']['samples']['modified_columns'])
        ->toHaveKey('code');

    $current['tables']['samples']['columns']['code']['generation_expression'] = '';
    $current['tables']['samples']['columns']['code']['character_set'] = 'latin1';
    expect($this->service->compareBackupStructures($standard, $current)['table_differences']['samples']['modified_columns'])
        ->toHaveKey('code');
});

test('backup schema comparison accepts legacy columns without restore metadata', function () {
    $standard = ['tables' => ['samples' => [
        'columns' => [
            'code' => [
                'position' => 1,
                'type' => 'varchar(32)',
                'nullable' => false,
                'default' => null,
                'extra' => '',
                'comment' => '',
            ],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ]]];
    $current = $standard;
    $current['tables']['samples']['columns']['code']['character_set'] = 'utf8mb4';
    $current['tables']['samples']['columns']['code']['generation_expression'] = 'upper(`code`)';

    expect($this->service->compareBackupStructures($standard, $current)['table_differences'])->toBeEmpty();
});

test('semantic comparison ignores implicit foreign key index naming differences', function () {
    $table = [
        'columns' => [],
        'foreign_keys' => [
            'fk_order_user' => [
                'columns' => ['user_id'],
                'references' => ['table' => 'users', 'columns' => ['id']],
                'on_delete' => 'CASCADE',
                'on_update' => 'NO ACTION',
            ],
        ],
    ];
    $standard = ['tables' => ['orders' => $table + [
        'indexes' => ['orders_user_id_foreign' => ['unique' => false, 'type' => 'BTREE', 'columns' => ['user_id'], 'sub_parts' => [null]]],
    ]]];
    $current = ['tables' => ['orders' => $table + [
        'indexes' => ['fk_order_user_idx' => ['unique' => false, 'type' => 'BTREE', 'columns' => ['user_id'], 'sub_parts' => [null]]],
    ]]];

    expect($this->service->compareStructures($standard, $current)['table_differences'])->toBeEmpty();
});

test('semantic comparison detects every foreign key definition change', function (string $field, mixed $value) {
    $foreignKey = [
        'columns' => ['user_id'],
        'references' => ['table' => 'users', 'columns' => ['id']],
        'on_delete' => 'CASCADE',
        'on_update' => 'NO ACTION',
    ];
    $standard = ['tables' => ['orders' => [
        'columns' => [],
        'indexes' => [],
        'foreign_keys' => ['fk_order_user' => $foreignKey],
    ]]];
    $current = $standard;

    match ($field) {
        'columns' => $current['tables']['orders']['foreign_keys']['fk_order_user']['columns'] = $value,
        'reference_table' => $current['tables']['orders']['foreign_keys']['fk_order_user']['references']['table'] = $value,
        'reference_columns' => $current['tables']['orders']['foreign_keys']['fk_order_user']['references']['columns'] = $value,
        'on_delete' => $current['tables']['orders']['foreign_keys']['fk_order_user']['on_delete'] = $value,
        'on_update' => $current['tables']['orders']['foreign_keys']['fk_order_user']['on_update'] = $value,
    };

    $diff = $this->service->compareStructures($standard, $current);

    expect($diff['table_differences']['orders']['modified_foreign_keys'])
        ->toHaveKey('fk_order_user');
})->with([
    'columns' => ['columns', ['account_id']],
    'referenced table' => ['reference_table', 'accounts'],
    'referenced columns' => ['reference_columns', ['uuid']],
    'on delete' => ['on_delete', 'RESTRICT'],
    'on update' => ['on_update', 'CASCADE'],
]);

test('semantic comparison does not overwrite duplicate foreign key column indexes while pairing names', function () {
    $foreignKeys = [
        'fk_order_user' => [
            'columns' => ['user_id'],
            'references' => ['table' => 'users', 'columns' => ['id']],
            'on_delete' => 'CASCADE',
            'on_update' => 'NO ACTION',
        ],
    ];
    $index = ['unique' => false, 'type' => 'BTREE', 'columns' => ['user_id'], 'sub_parts' => [null]];
    $standard = ['tables' => ['orders' => [
        'columns' => [],
        'indexes' => ['implicit_old' => $index, 'explicit_user_id' => $index],
        'foreign_keys' => $foreignKeys,
    ]]];
    $current = ['tables' => ['orders' => [
        'columns' => [],
        'indexes' => ['implicit_new' => $index, 'explicit_user_id' => $index, 'duplicate_user_id' => $index],
        'foreign_keys' => $foreignKeys,
    ]]];

    $diff = $this->service->compareStructures($standard, $current);

    expect($diff['table_differences']['orders']['missing_indexes'])
        ->toHaveKey('implicit_old')
        ->and($diff['table_differences']['orders']['extra_indexes'])
        ->toHaveKeys(['implicit_new', 'duplicate_user_id']);
});

test('InnoDB 外键限制规则按等价语义比较且保留其他引擎差异', function (string $engine, string $field, string $currentAction, bool $hasDiff) {
    $standard = ['tables' => ['users' => [
        'engine' => $engine,
        'columns' => [], 'indexes' => [],
        'foreign_keys' => ['users_level_code_foreign' => [
            'columns' => ['level_code'],
            'references' => ['table' => 'user_levels', 'columns' => ['code']],
            'on_delete' => 'RESTRICT', 'on_update' => 'RESTRICT',
        ]],
    ]]];
    $current = $standard;
    $current['tables']['users']['foreign_keys']['users_level_code_foreign'][$field] = $currentAction;
    expect(! empty($this->service->compareStructures($standard, $current)['table_differences']))->toBe($hasDiff);
    expect(! empty($this->service->compareStructures($current, $standard)['table_differences']))->toBe($hasDiff);
})->with([
    ['InnoDB', 'on_delete', 'NO ACTION', false],
    ['InnoDB', 'on_update', 'NO ACTION', false],
    ['InnoDB', 'on_delete', 'CASCADE', true],
    ['InnoDB', 'on_update', 'SET NULL', true],
    ['NDB', 'on_delete', 'NO ACTION', true],
]);

test('命令行检测和修复报告均展示真实外键修改', function (string $method) {
    $standard = [
        'columns' => ['level_code'],
        'references' => ['table' => 'user_levels', 'columns' => ['code']],
        'on_delete' => 'RESTRICT', 'on_update' => 'NO ACTION',
    ];
    $current = $standard;
    $current['on_delete'] = 'CASCADE';
    $diff = ['missing_tables' => [], 'extra_tables' => [], 'table_differences' => [
        'users' => ['modified_foreign_keys' => [
            'users_level_code_foreign' => ['standard' => $standard, 'current' => $current],
        ]],
    ]];
    $command = new DatabaseStructureCommand($this->service);
    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(
        new ArrayInput([]), $output,
    ));
    (new ReflectionMethod($command, $method))->invoke($command, $diff);
    expect($output->fetch())->toContain('users.users_level_code_foreign')
        ->toContain('ON DELETE CASCADE')->toContain('ON DELETE RESTRICT');
})->with(['displayDiffReport', 'displayManualActions']);

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// 测试 A（机制探针，并行安全，破解「默认值巧合」）：
// 用一个非默认值 33（MySQL 默认是 50，33 只可能来自 init_command）证明 MYSQL_ATTR_INIT_COMMAND
// 真的驱动连接的 session 值。派生一个临时连接、不动 global、不动主连接，跑完即 purge，无污染。
it('MYSQL_ATTR_INIT_COMMAND 机制确实把 session 值写进连接（非默认值 33 证明）', function () {
    config([
        'database.connections.mysql_lock_probe' => array_merge(
            config('database.connections.mysql'),
            ['options' => [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout=33']],
        ),
    ]);

    $value = (int) DB::connection('mysql_lock_probe')
        ->selectOne('SELECT @@session.innodb_lock_wait_timeout AS v')->v;

    DB::purge('mysql_lock_probe');

    // 33 ≠ MySQL 默认 50，只能来自 init_command → 机制生效
    expect($value)->toBe(33);
});

// 测试 B（配置正确，改动前必失败的锚点）：证明主 mysql 连接的 options 用了精确的 50 语句。
it('主 mysql 连接 options 含 MYSQL_ATTR_INIT_COMMAND 且语句精确为 50', function () {
    $options = config('database.connections.mysql.options');

    expect($options)->toHaveKey(PDO::MYSQL_ATTR_INIT_COMMAND)
        ->and($options[PDO::MYSQL_ATTR_INIT_COMMAND])
        ->toBe('SET SESSION innodb_lock_wait_timeout=50');
});

// 测试 C（运行时守回归）：主连接的实际 session 值。默认也是 50 故改动前可能 PASS，
// 但 A（机制）+ B（配置=50）已逻辑闭合「主连接必被 init_command 设为 50」；C 守未来 global 漂移/机制失效回归。
it('主 mysql 连接运行时 session innodb_lock_wait_timeout 为 50', function () {
    $value = (int) DB::selectOne('SELECT @@session.innodb_lock_wait_timeout AS v')->v;

    expect($value)->toBe(50);
});

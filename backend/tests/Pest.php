<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * 造一个 shell 脚本，模拟 mysql/mysqldump 的 --version 输出，供
 * BackupService::ensureMysqlClient 的 proc_open 探测识别为合法 mysql 客户端。
 *
 * 返回脚本绝对路径。注册 shutdown 时自动清理。
 */
function fakeMysqlClientBin(string $tool = 'mysqldump'): string
{
    $path = sys_get_temp_dir().'/fake_'.$tool.'_'.uniqid().'.sh';
    file_put_contents(
        $path,
        "#!/bin/sh\necho '$tool  Ver 8.0.99 for Linux on x86_64 (MySQL Distrib 8.0.99)'\n"
    );
    chmod($path, 0755);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

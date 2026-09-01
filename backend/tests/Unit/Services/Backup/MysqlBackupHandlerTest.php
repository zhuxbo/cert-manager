<?php

use App\Services\Backup\MysqlBackupHandler;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\NativeProcessPipeline;
use App\Services\Backup\PipelineResult;
use App\Services\Binary\BinaryLocator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->handlerDirectory = sys_get_temp_dir().'/mysql handler '.bin2hex(random_bytes(6));
    mkdir($this->handlerDirectory, 0700, true);
});

afterEach(function () {
    foreach (glob($this->handlerDirectory.'/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($this->handlerDirectory);
});

function mysqlHandlerExecutable(string $path, string $contents): string
{
    file_put_contents($path, $contents);
    chmod($path, 0700);

    return $path;
}

test('handler 按官方 MySQL 系列构造单次 dump 并固定 gzip 参数', function (
    string $series,
    string $version,
    bool $expectsColumnStatistics,
) {
    $dumpArgsPath = $this->handlerDirectory.'/dump args';
    $cnfModePath = $this->handlerDirectory.'/cnf mode';
    $gzipArgsPath = $this->handlerDirectory.'/gzip args';
    $mysqldump = mysqlHandlerExecutable(
        $this->handlerDirectory.'/mysqldump fake',
        "#!/bin/sh\n"
            .'if [ "$1" = "--version" ]; then printf \'mysqldump Ver '.$version.' for Linux (MySQL Community Server - GPL)\\n\'; exit 0; fi'."\n"
            .'for arg in "$@"; do case "$arg" in --defaults-extra-file=*) '
            .'cnf="${arg#*=}"; stat -c \'%a\' "$cnf" > '.escapeshellarg($cnfModePath).';; esac; done'."\n"
            .'printf \'%s\\n\' "$@" > '.escapeshellarg($dumpArgsPath)."\n"
            ."printf 'CREATE TABLE t (id int);\\n'\n",
    );
    $gzip = mysqlHandlerExecutable(
        $this->handlerDirectory.'/gzip fake',
        "#!/bin/sh\n"
            .'if [ "$1" = "--version" ]; then printf \'gzip 1.12\\n\'; exit 0; fi'."\n"
            .'printf \'%s\\n\' "$@" > '.escapeshellarg($gzipArgsPath)."\n"
            .'exec /usr/bin/gzip "$@"'."\n",
    );
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('mysqldump')->once()->andReturn($mysqldump);
    $locator->shouldReceive('gzip')->once()->andReturn($gzip);
    DB::shouldReceive('selectOne')
        ->once()
        ->with('SELECT VERSION() AS version, @@version_comment AS version_comment')
        ->andReturn((object) [
            'version' => $version,
            'version_comment' => 'MySQL Community Server - GPL',
        ]);
    $checker = new MysqlToolchainChecker($locator);
    $toolchain = $checker->inspect(requireMysql: false, requireMysqldump: true);
    $handler = new MysqlBackupHandler(new NativeProcessPipeline);
    $partPath = $this->handlerDirectory.'/backup.sql.gz.part';
    $secret = 'handler-secret-'.bin2hex(random_bytes(8));

    $result = $handler->backup([
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'backup-user',
        'password' => $secret,
        'charset' => 'utf8mb4',
        'database' => 'ssl_manager_test',
    ], $partPath, ['jobs'], $toolchain);
    $dumpArgs = (string) file_get_contents($dumpArgsPath);
    $gzipArgs = (string) file_get_contents($gzipArgsPath);
    preg_match('/--defaults-extra-file=(.+)/', $dumpArgs, $matches);
    if ($expectsColumnStatistics) {
        expect($dumpArgs)->toContain('--column-statistics=0');
    } else {
        expect($dumpArgs)->not->toContain('--column-statistics=0');
    }

    expect($result)->toBeInstanceOf(PipelineResult::class)
        ->and(gzdecode((string) file_get_contents($partPath)))->toBe("CREATE TABLE t (id int);\n")
        ->and($dumpArgs)->toContain('--set-gtid-purged=OFF')
        ->toContain('--no-tablespaces')
        ->toContain('--ignore-table=ssl_manager_test.jobs')
        ->and($gzipArgs)->toBe("-1\n-c\n")
        ->and(trim((string) file_get_contents($cnfModePath)))->toBe('600')
        ->and($dumpArgs)->not->toContain($secret)
        ->and(is_file($matches[1] ?? ''))->toBeFalse();
})->with([
    'MySQL 5.7' => ['5.7', '5.7.44', false],
    'MySQL 8.0' => ['8.0', '8.0.41', true],
    'MySQL 8.4' => ['8.4', '8.4.3', true],
]);

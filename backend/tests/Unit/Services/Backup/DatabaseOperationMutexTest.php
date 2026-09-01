<?php

declare(strict_types=1);

use App\Services\Backup\DatabaseOperationMutex;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

uses(TestCase::class)->group('database');

beforeEach(function () {
    $this->mutex = null;
    $this->probePdos = [];
});

afterEach(function () {
    if ($this->mutex instanceof DatabaseOperationMutex) {
        $this->mutex->release();
    }

    $lockName = databaseOperationMutexLockName();
    foreach ($this->probePdos as $pdo) {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$lockName]);
    }
});

function databaseOperationMutexLockName(): string
{
    $default = (string) config('database.default');
    $database = (string) config("database.connections.$default.database");

    return hash('sha256', $database);
}

function databaseOperationMutexPdo(object $test): PDO
{
    $default = (string) config('database.default');
    /** @var array<string, mixed> $config */
    $config = config("database.connections.$default");

    $socket = (string) ($config['unix_socket'] ?? '');
    $dsn = $socket !== ''
        ? 'mysql:unix_socket='.$socket.';dbname='.$config['database'].';charset=utf8mb4'
        : 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4';

    $pdo = new PDO(
        $dsn,
        (string) $config['username'],
        (string) $config['password'],
        (array) ($config['options'] ?? []) + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $test->probePdos[] = $pdo;

    return $pdo;
}

test('锁名由当前数据库名的 sha256 得到且不超过 MySQL 64 字节限制', function () {
    $this->mutex = new DatabaseOperationMutex(app(DatabaseManager::class));

    expect($this->mutex->acquire())->toBeTrue();

    $lockName = databaseOperationMutexLockName();
    $probe = databaseOperationMutexPdo($this);
    $statement = $probe->prepare('SELECT IS_USED_LOCK(?)');
    $statement->execute([$lockName]);
    $ownerConnectionId = $statement->fetchColumn();

    expect(strlen($lockName))->toBeLessThanOrEqual(64)
        ->and($ownerConnectionId)->not->toBeFalse()
        ->and($ownerConnectionId)->not->toBeNull();
});

test('独立连接不能同时持有同一个数据库操作锁', function () {
    $holder = databaseOperationMutexPdo($this);
    $statement = $holder->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([databaseOperationMutexLockName()]);
    expect((int) $statement->fetchColumn())->toBe(1);

    $this->mutex = new DatabaseOperationMutex(app(DatabaseManager::class));

    expect($this->mutex->acquire())->toBeFalse();
});

test('synchronized 回调异常时 finally 释放原连接持有的锁', function () {
    $this->mutex = new DatabaseOperationMutex(app(DatabaseManager::class));

    expect(fn () => $this->mutex->synchronized(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    $probe = databaseOperationMutexPdo($this);
    $statement = $probe->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([databaseOperationMutexLockName()]);
    expect((int) $statement->fetchColumn())->toBe(1);
});

test('synchronized 返回回调结果并在正常退出后释放锁', function () {
    $this->mutex = new DatabaseOperationMutex(app(DatabaseManager::class));

    expect($this->mutex->synchronized(fn () => 'completed'))->toBe('completed');

    $probe = databaseOperationMutexPdo($this);
    $statement = $probe->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([databaseOperationMutexLockName()]);
    expect((int) $statement->fetchColumn())->toBe(1);
});

test('非持有连接 release 不会释放或宣称取得他方锁', function () {
    $holder = databaseOperationMutexPdo($this);
    $statement = $holder->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([databaseOperationMutexLockName()]);
    expect((int) $statement->fetchColumn())->toBe(1);

    $this->mutex = new DatabaseOperationMutex(app(DatabaseManager::class));
    expect($this->mutex->acquire())->toBeFalse();
    $this->mutex->release();

    $probe = databaseOperationMutexPdo($this);
    $statement = $probe->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([databaseOperationMutexLockName()]);
    expect((int) $statement->fetchColumn())->toBe(0);
});

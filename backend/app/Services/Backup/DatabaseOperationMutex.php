<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class DatabaseOperationMutex
{
    private ?Connection $holdingConnection = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function acquire(int $waitSeconds = 0): bool
    {
        if ($this->holdingConnection !== null) {
            return true;
        }

        $connection = $this->database->connection();
        $result = $connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [$this->lockName($connection), $waitSeconds],
        );
        $acquired = $result->acquired ?? null;

        if ($acquired === 0 || $acquired === '0') {
            return false;
        }
        if ($acquired !== 1 && $acquired !== '1') {
            throw new RuntimeException('获取数据库操作锁结果异常');
        }

        $this->holdingConnection = $connection;

        return true;
    }

    public function release(): void
    {
        $connection = $this->holdingConnection;
        if ($connection === null) {
            return;
        }

        $this->holdingConnection = null;
        $result = $connection->selectOne(
            'SELECT RELEASE_LOCK(?) AS released',
            [$this->lockName($connection)],
        );
        $released = $result->released ?? null;

        if ($released !== 1 && $released !== '1') {
            throw new RuntimeException('释放数据库操作锁结果异常');
        }
    }

    public function synchronized(callable $callback, int $waitSeconds = 0): mixed
    {
        if (! $this->acquire($waitSeconds)) {
            return false;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    private function lockName(Connection $connection): string
    {
        return hash('sha256', $connection->getDatabaseName());
    }
}

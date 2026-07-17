<?php

declare(strict_types=1);

namespace App\Services\ProductPrice;

use App\Exceptions\ProductPriceMutationBusyException;
use App\Exceptions\ProductPriceMutationLockException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductPriceMutationLock
{
    private const KEY = 'ssl-manager:product-price:mutation';

    public function __construct(private readonly DatabaseManager $database) {}

    public function runWithLock(Closure $callback): mixed
    {
        $connection = $this->database->connection();

        try {
            $result = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->key()]);
        } catch (Throwable $e) {
            throw new ProductPriceMutationLockException('获取产品价格变更锁失败', previous: $e);
        }

        $acquired = $result->acquired ?? null;
        if ($acquired === null) {
            throw new ProductPriceMutationLockException('获取产品价格变更锁结果异常');
        }
        if ($acquired === 0 || $acquired === '0') {
            throw new ProductPriceMutationBusyException;
        }
        if ($acquired !== 1 && $acquired !== '1') {
            throw new ProductPriceMutationLockException('获取产品价格变更锁结果异常');
        }

        $value = null;
        $callbackError = null;
        $releaseError = null;

        try {
            try {
                $value = $callback();
            } catch (Throwable $e) {
                $callbackError = $e;
            }
        } finally {
            $releaseError = $this->release($connection);
        }

        if ($callbackError !== null) {
            throw $callbackError;
        }
        if ($releaseError !== null) {
            throw new ProductPriceMutationLockException('释放产品价格变更锁失败', previous: $releaseError);
        }

        return $value;
    }

    private function release(Connection $connection): ?Throwable
    {
        try {
            $result = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->key()]);
            $released = $result->released ?? null;
            if ($released !== 1 && $released !== '1') {
                throw new ProductPriceMutationLockException('命名锁释放结果异常');
            }

            return null;
        } catch (Throwable $e) {
            Log::critical('[product_price] 命名锁释放失败', ['exception' => $e]);

            try {
                $this->database->disconnect($connection->getName());
            } catch (Throwable $disconnectError) {
                Log::critical('[product_price] 命名锁连接断开失败', ['exception' => $disconnectError]);
            }

            return $e;
        }
    }

    private function key(): string
    {
        if (! app()->environment('testing')) {
            return self::KEY;
        }

        $token = getenv('TEST_TOKEN');

        return self::KEY.':'.($token === false || $token === '' ? 'single' : $token);
    }
}

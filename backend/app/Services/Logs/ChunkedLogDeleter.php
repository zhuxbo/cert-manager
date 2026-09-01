<?php

declare(strict_types=1);

namespace App\Services\Logs;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ChunkedLogDeleter
{
    /**
     * @param  callable(): (EloquentBuilder|QueryBuilder)  $queryFactory
     */
    public function delete(callable $queryFactory, int $chunkSize, bool $dryRun = false): int
    {
        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('chunk size must be greater than zero');
        }

        if ($dryRun) {
            return (int) $queryFactory()->count();
        }

        $total = 0;
        do {
            $deleted = DB::transaction(function () use ($queryFactory, $chunkSize): int {
                $ids = $queryFactory()->orderBy('id')->limit($chunkSize)->pluck('id');
                if ($ids->isEmpty()) {
                    return 0;
                }

                $deleted = (int) $queryFactory()->whereIn('id', $ids)->delete();
                if ($deleted === 0) {
                    throw new RuntimeException('chunked delete made no progress');
                }

                return $deleted;
            });
            $total += $deleted;
        } while ($deleted === $chunkSize);

        return $total;
    }
}

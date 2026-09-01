<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

final readonly class RestoreCutoverResult
{
    /** @param list<string> $swappedTables */
    public function __construct(
        public string $operation,
        public array $swappedTables,
    ) {}
}

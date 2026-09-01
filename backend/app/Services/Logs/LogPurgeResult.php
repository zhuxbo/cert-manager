<?php

declare(strict_types=1);

namespace App\Services\Logs;

final readonly class LogPurgeResult
{
    /**
     * @param  array<string, int>  $deletedByTable
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $owner,
        public array $deletedByTable,
        public int $unclassified,
        public array $warnings = [],
    ) {}
}

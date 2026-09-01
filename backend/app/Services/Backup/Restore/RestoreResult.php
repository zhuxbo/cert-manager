<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

final readonly class RestoreResult
{
    /**
     * @param  list<array{code:string,message:string}>  $warnings
     * @param  array<string, int|float|string>  $metrics
     */
    public function __construct(
        public string $operation,
        public string $restoreToken,
        public array $warnings,
        public array $metrics,
    ) {}
}

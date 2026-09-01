<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

final readonly class RestoreValidationReport
{
    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array<string, mixed>>  $warnings
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public bool $passed,
        public array $errors,
        public array $warnings,
        public array $metrics,
    ) {}
}

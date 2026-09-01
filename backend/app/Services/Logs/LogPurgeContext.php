<?php

declare(strict_types=1);

namespace App\Services\Logs;

use Carbon\CarbonImmutable;

final readonly class LogPurgeContext
{
    public function __construct(
        public CarbonImmutable $fullCutoff,
        public CarbonImmutable $auditCutoff,
        public int $chunkSize,
        public bool $dryRun,
    ) {}
}

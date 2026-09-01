<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

final readonly class RestoreRequest
{
    public function __construct(
        public string $backupId,
        public bool $allowSchemaDifference,
        public string $actor,
    ) {}
}

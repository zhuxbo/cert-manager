<?php

declare(strict_types=1);

namespace App\Services\Backup;

interface SqlStreamTransformer
{
    public function push(string $chunk): string;

    public function finish(): string;
}

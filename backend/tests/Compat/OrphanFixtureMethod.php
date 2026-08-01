<?php

declare(strict_types=1);

namespace Tests\Compat;

function withoutPestDatasetSuffix(string $method): string
{
    return preg_replace(
        '/@(?:dataset\s+(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|\(.*\))\s+with data\s+\(.*\)$/s',
        '',
        $method
    ) ?? $method;
}

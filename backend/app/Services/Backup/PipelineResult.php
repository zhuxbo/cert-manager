<?php

declare(strict_types=1);

namespace App\Services\Backup;

final readonly class PipelineResult
{
    /**
     * @param  array<string, int>  $exitCodes
     * @param  array<string, string>  $stderr
     */
    public function __construct(
        public int $inputBytes,
        public int $outputBytes,
        public string $outputSha256,
        public array $exitCodes,
        public array $stderr,
    ) {}
}

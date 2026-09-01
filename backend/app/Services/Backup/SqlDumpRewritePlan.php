<?php

declare(strict_types=1);

namespace App\Services\Backup;

final readonly class SqlDumpRewritePlan
{
    /**
     * @param  array<string, string>  $tableMap
     * @param  array<string, list<string>>  $columnOrder
     * @param  array<string, list<string>>  $generatedColumns
     */
    public function __construct(
        public array $tableMap,
        public array $columnOrder,
        public array $generatedColumns,
        public bool $omitForeignKeys = true,
        public bool $inferSchema = false,
    ) {}
}

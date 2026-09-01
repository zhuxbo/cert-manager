<?php

namespace App\Contracts;

use App\Services\Logs\LogPurgeContext;
use App\Services\Logs\LogPurgeResult;

interface PluginLogPurger
{
    public function plugin(): string;

    /** @return list<string> */
    public function tables(): array;

    public function purge(LogPurgeContext $context): LogPurgeResult;
}

<?php

namespace Plugins\Easy;

use App\Contracts\PluginLogHandler;
use App\Services\LogBuffer;
use Plugins\Easy\Models\EasyLog;

class EasyLogHandler implements PluginLogHandler
{
    public function shouldHandle(string $path): bool
    {
        return str_starts_with($path, 'api/easy/');
    }

    public function handle(array $logData): void
    {
        LogBuffer::add(EasyLog::class, array_intersect_key($logData, array_flip([
            'action', 'method', 'url', 'params', 'response', 'ip', 'status',
        ])));
    }
}

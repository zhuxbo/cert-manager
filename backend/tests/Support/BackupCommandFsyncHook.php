<?php

declare(strict_types=1);

namespace App\Console\Commands;

function fsync(mixed $stream): bool
{
    $hook = $GLOBALS['backup_command_fsync_hook'] ?? null;

    return is_callable($hook) ? $hook($stream) : \fsync($stream);
}

<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

enum RestoreState: string
{
    case Clean = 'clean';
    case Staged = 'staged';
    case ActiveWithOld = 'active_with_old';
    case ActiveForeignKeysRemoved = 'active_foreign_keys_removed';
    case ShadowForeignKeysReady = 'shadow_foreign_keys_ready';
    case BrokenOldSet = 'broken_old_set';
}

<?php

namespace App\Services\Upgrade;

use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RuntimeSessionCutover
{
    public const string MIGRATION = '2026_09_04_000001_invalidate_sessions_for_runtime_cache_cutover';

    public const string MIGRATION_PATH = 'database/migrations/'.self::MIGRATION.'.php';

    public static function isPending(): bool
    {
        return Schema::hasTable('migrations')
            && ! DB::table('migrations')->where('migration', self::MIGRATION)->exists();
    }

    public static function finishCompletedUpgrade(int $pid): void
    {
        $status = (new UpgradeStatusManager)->get();
        if (($status['status'] ?? null) !== 'completed'
            || ($status['pid'] ?? null) !== $pid
            || UpgradeFreezeLock::isFrozen()
            || ! self::isPending()) {
            return;
        }

        if (Artisan::call('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]) !== 0) {
            throw new RuntimeException('升级已完成，但会话切库迁移失败：'.Artisan::output());
        }
    }
}

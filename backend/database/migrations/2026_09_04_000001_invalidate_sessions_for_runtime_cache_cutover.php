<?php

use App\Services\Upgrade\LegacyJwtBlacklistMigration;
use App\Services\Upgrade\RedisDatabaseConfig;
use App\Services\Upgrade\RuntimeSessionCutover;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function shouldRun(): bool
    {
        // 旧版后台进程仍持有旧配置，必须在后续 config:clear 前保留 Redis 编号。
        if (config('cache.stores.runtime') === null) {
            RedisDatabaseConfig::preserve();
        }

        if (! UpgradeFreezeLock::isFrozen()) {
            return true;
        }

        // shouldRun=false 不记入 migrations；旧升级器也能在成功退出时补跑本迁移。
        $pid = getmypid();
        app()->terminating(static fn () => RuntimeSessionCutover::finishCompletedUpgrade($pid));

        return false;
    }

    public function up(): void
    {
        if (config('cache.stores.runtime') === null) {
            RedisDatabaseConfig::preserve();
        }

        LegacyJwtBlacklistMigration::run();
    }

    public function down(): void
    {
        // 保留已复制的黑名单，回滚不恢复已吊销 token。
    }
};

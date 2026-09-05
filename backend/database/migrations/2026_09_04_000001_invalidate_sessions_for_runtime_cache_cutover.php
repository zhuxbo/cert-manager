<?php

use App\Services\Upgrade\RedisDatabaseConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const int MAX_TOKEN_VERSION = 4294967295;

    /**
     * JWT 黑名单首次从可清理缓存切到 runtime 仓库时，旧库中的键不再可靠读取。
     * 一次性吊销全部存量会话，确保已登出的 access token 不会因切库恢复有效。
     */
    public function up(): void
    {
        // 首次后台升级仍执行旧 UpgradeService，但此进程保留着旧版配置；在清配置缓存前固定编号。
        // 脚本升级已在切码前处理，新安装与新版进程无需重复执行。
        if (config('cache.stores.runtime') === null) {
            RedisDatabaseConfig::preserve();
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('admins')) {
            return;
        }

        $maxUserVersion = (int) (DB::table('users')->max('token_version') ?? 0);
        $maxAdminVersion = (int) (DB::table('admins')->max('token_version') ?? 0);
        if ($maxUserVersion >= self::MAX_TOKEN_VERSION || $maxAdminVersion >= self::MAX_TOKEN_VERSION) {
            throw new RuntimeException('token_version 递增将溢出，拒绝执行会话切库迁移');
        }

        $expiredAt = now()->subSeconds((int) config('jwt.blacklist_grace_period', 30) + 1);

        DB::transaction(function () use ($expiredAt): void {
            DB::table('users')->update([
                'token_version' => DB::raw('token_version + 1'),
                'logout_at' => $expiredAt,
            ]);
            DB::table('admins')->update([
                'token_version' => DB::raw('token_version + 1'),
                'logout_at' => $expiredAt,
            ]);

            if (Schema::hasTable('user_refresh_tokens')) {
                DB::table('user_refresh_tokens')->delete();
            }
            if (Schema::hasTable('admin_refresh_tokens')) {
                DB::table('admin_refresh_tokens')->delete();
            }
        });
    }

    public function down(): void
    {
        // 会话吊销不可逆，回滚代码也不应让旧 token 重新生效。
    }
};

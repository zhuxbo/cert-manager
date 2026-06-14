<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->cleanupNotificationTemplates();
        $this->renameLegacyFinanceAuditAlert();
        $this->dropChannelsColumn();
        $this->ensureUniqueCodeOnNotificationTemplates();
        $this->flattenUserNotificationSettings();
    }

    public function down(): void
    {
        // 系统不支持回滚
    }

    /**
     * 清理 sms 通道残留：
     *   - channels = ['sms']     → 删除整条记录
     *   - channels = [...,'sms'] → 从数组中移除 sms 项
     */
    protected function cleanupNotificationTemplates(): void
    {
        if (! Schema::hasTable('notification_templates') ||
            ! Schema::hasColumn('notification_templates', 'channels')) {
            return;
        }

        DB::table('notification_templates')->orderBy('id')->each(function ($row) {
            $channels = json_decode((string) ($row->channels ?? ''), true);
            if (! is_array($channels) || ! in_array('sms', $channels, true)) {
                return;
            }

            $remaining = array_values(array_filter(
                $channels,
                fn ($channel) => $channel !== 'sms'
            ));

            if (empty($remaining)) {
                DB::table('notification_templates')->where('id', $row->id)->delete();

                return;
            }

            DB::table('notification_templates')
                ->where('id', $row->id)
                ->update(['channels' => json_encode($remaining)]);
        });
    }

    /**
     * 存量改名：旧 code = finance_audit_alert → finance_audit。
     *
     * 背景：老版本 seeder 用 finance_audit_alert，现已改名为 finance_audit；
     * 若不改名，旧行会变孤儿死数据，管理员对旧行做过的自定义配置也会丢失。
     *
     * 顺序：本步骤在 cleanup 之后、去重(ensureUnique)之前执行，
     * 让改名产生的与既有 finance_audit 的重复行交由去重步骤按"保留启用行"规则收敛。
     *
     * 幂等 + 唯一约束安全：
     *   - 无 finance_audit_alert 行直接返回；
     *   - 改名前若 finance_audit 已存在（索引可能已是 unique），先把两个 code 的行
     *     合并去重（保留启用行）只留一行，避免改名撞唯一索引。
     */
    protected function renameLegacyFinanceAuditAlert(): void
    {
        if (! Schema::hasTable('notification_templates') ||
            ! Schema::hasColumn('notification_templates', 'code')) {
            return;
        }

        $hasLegacy = DB::table('notification_templates')
            ->where('code', 'finance_audit_alert')
            ->exists();

        if (! $hasLegacy) {
            return;
        }

        $hasNew = DB::table('notification_templates')
            ->where('code', 'finance_audit')
            ->exists();

        // 仅有旧行：直接整体改名（含潜在多旧行，去重交给后续 ensureUnique 收敛）
        if (! $hasNew) {
            DB::table('notification_templates')
                ->where('code', 'finance_audit_alert')
                ->update(['code' => 'finance_audit']);

            return;
        }

        // 新旧 code 同时存在：合并候选行，保留启用行的最小 id，删除其余，
        // 再把保留行统一为 finance_audit（避免改名撞唯一索引）。
        $keepId = $this->resolveKeepId(['finance_audit_alert', 'finance_audit']);

        DB::table('notification_templates')
            ->whereIn('code', ['finance_audit_alert', 'finance_audit'])
            ->where('id', '!=', $keepId)
            ->delete();

        DB::table('notification_templates')
            ->where('id', $keepId)
            ->update(['code' => 'finance_audit']);
    }

    /**
     * 在给定 code 集合的所有行中，挑出应保留的行 id：
     *   - 优先保留 status=1（启用）行中 id 最小的；
     *   - 若无启用行，退回保留 id 最小行。
     */
    protected function resolveKeepId(array $codes): ?int
    {
        $enabledMin = DB::table('notification_templates')
            ->whereIn('code', $codes)
            ->where('status', 1)
            ->min('id');

        if ($enabledMin !== null) {
            return (int) $enabledMin;
        }

        $anyMin = DB::table('notification_templates')
            ->whereIn('code', $codes)
            ->min('id');

        return $anyMin === null ? null : (int) $anyMin;
    }

    /**
     * 删除 notification_templates.channels 字段
     */
    protected function dropChannelsColumn(): void
    {
        if (! Schema::hasTable('notification_templates') ||
            ! Schema::hasColumn('notification_templates', 'channels')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn('channels');
        });
    }

    /**
     * 将 notification_templates.code 普通索引升级为唯一索引：
     *   - 先按 code 去重，保留 id 最小的，删除其余重复行
     *   - 删除旧的普通索引 notification_templates_code_index
     *   - 建立唯一索引（命名沿用 notification_templates_code_index）
     */
    protected function ensureUniqueCodeOnNotificationTemplates(): void
    {
        if (! Schema::hasTable('notification_templates') ||
            ! Schema::hasColumn('notification_templates', 'code')) {
            return;
        }

        // SHOW INDEX 不支持服务端 prepared 参数绑定（Laravel 默认 EMULATE_PREPARES=false），
        // 全量取回后用 Collection 过滤，避免 SQLSTATE[42000] 1064
        $codeIndexes = collect(DB::select('SHOW INDEX FROM notification_templates'))
            ->where('Column_name', 'code');

        // 已是唯一索引则跳过（幂等）
        if ($codeIndexes->firstWhere('Non_unique', 0)) {
            return;
        }

        // 去重：每个 code 优先保留 status=1（启用）行中 id 最小的，
        // 无启用行才退回保留 id 最小行——避免删掉实际生效的启用行（DELETE 不可逆）。
        $dupCodes = DB::table('notification_templates')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        foreach ($dupCodes as $code) {
            $keepId = $this->resolveKeepId([$code]);

            if ($keepId === null) {
                continue;
            }

            DB::table('notification_templates')
                ->where('code', $code)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        // 旧索引存在才删，容错索引名被改/删的环境
        $oldIndexExists = $codeIndexes->firstWhere('Key_name', 'notification_templates_code_index') !== null;

        if ($oldIndexExists) {
            Schema::table('notification_templates', function (Blueprint $table) {
                $table->dropIndex('notification_templates_code_index');
            });
        }

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->unique('code', 'notification_templates_code_index');
        });
    }

    /**
     * 扁平化 users.notification_settings：
     *   {mail: {x: true}, sms: {...}} → {x: true}
     * 已经是扁平结构的跳过；只保留 mail 子树，其他通道丢弃。
     */
    protected function flattenUserNotificationSettings(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'notification_settings')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('notification_settings')
            ->orderBy('id')
            ->each(function ($row) {
                $raw = $row->notification_settings;
                if (! is_string($raw) || $raw === '') {
                    return;
                }

                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    return;
                }

                $hasNestedChannel = false;
                foreach ($decoded as $key => $value) {
                    if (is_array($value) && in_array($key, ['mail', 'sms'], true)) {
                        $hasNestedChannel = true;
                        break;
                    }
                }

                if (! $hasNestedChannel) {
                    return;
                }

                $flat = $decoded['mail'] ?? [];

                DB::table('users')
                    ->where('id', $row->id)
                    ->update(['notification_settings' => json_encode($flat)]);
            });
    }
};

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

        // 去重：保留每个 code 的最小 id
        $duplicates = DB::table('notification_templates')
            ->select('code', DB::raw('MIN(id) as keep_id'))
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('notification_templates')
                ->where('code', $row->code)
                ->where('id', '!=', $row->keep_id)
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

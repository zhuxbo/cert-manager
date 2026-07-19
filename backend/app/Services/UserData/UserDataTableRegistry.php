<?php

namespace App\Services\UserData;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserDataTableRegistry
{
    /**
     * 直接通过 user_id 关联的表（导出+清理共用）
     *
     * @return array<int, array{table: string, name: string}>
     */
    public static function directTables(): array
    {
        return [
            ['table' => 'orders', 'name' => '订单'],
            ['table' => 'acmes', 'name' => 'ACME订单'],
            ['table' => 'contacts', 'name' => '联系人'],
            ['table' => 'organizations', 'name' => '组织'],
            ['table' => 'funds', 'name' => '充值记录'],
            ['table' => 'transactions', 'name' => '交易记录'],
            ['table' => 'cname_delegations', 'name' => 'CNAME委托'],
            ['table' => 'callbacks', 'name' => '回调配置'],
            ['table' => 'order_documents', 'name' => '订单验证文档'],
        ];
    }

    /**
     * 通过 order_id 间接关联的表
     *
     * @return array<int, array{table: string, name: string}>
     */
    public static function indirectTables(): array
    {
        return [
            ['table' => 'certs', 'name' => '证书'],
            ['table' => 'auto_deploy_reports', 'name' => '自动部署上报记录'],
            ['table' => 'tasks', 'name' => '任务'],
            ['table' => 'domain_validation_records', 'name' => '域名验证记录'],
        ];
    }

    /**
     * 仅清理用的表（令牌、日志，不导出）
     *
     * @return array<int, array{table: string, name: string}>
     */
    public static function purgeOnlyTables(): array
    {
        return [
            ['table' => 'api_tokens', 'name' => 'API令牌'],
            ['table' => 'deploy_tokens', 'name' => '部署令牌'],
            ['table' => 'user_logs', 'name' => '用户日志'],
            ['table' => 'api_logs', 'name' => 'API日志'],
            ['table' => 'user_refresh_tokens', 'name' => 'JWT刷新令牌'],
        ];
    }

    /**
     * users.password 在导出时排除的字段
     */
    public static function excludedUserColumns(): array
    {
        return ['password'];
    }

    /**
     * 雪花 ID 的表（对应模型使用 HasSnowflakeId trait）
     * 不在此列表中的用户数据表均为自增 ID，导出时去掉 id 列避免跨系统冲突
     */
    private static array $snowflakeIdTables = [
        'users', 'orders', 'acmes', 'contacts', 'organizations',
        'funds', 'certs', 'auto_deploy_reports', 'cname_delegations', 'transactions',
    ];

    /**
     * 判断表是否使用自增 ID（非雪花即自增）
     */
    public static function isAutoIncrement(string $table): bool
    {
        return ! in_array($table, self::$snowflakeIdTables);
    }

    /**
     * 导出用的表，按外键依赖排序（用于跨系统迁移，自增 ID 表去掉 id 列）
     */
    public static function exportTables(): array
    {
        return [
            // 1. 根表
            ['table' => 'users', 'name' => '用户', 'type' => 'user'],
            // 2. user_id 直接关联
            ['table' => 'orders', 'name' => '订单', 'type' => 'direct'],
            ['table' => 'acmes', 'name' => 'ACME订单', 'type' => 'direct'],
            ['table' => 'contacts', 'name' => '联系人', 'type' => 'direct'],
            ['table' => 'organizations', 'name' => '组织', 'type' => 'direct'],
            ['table' => 'funds', 'name' => '充值记录', 'type' => 'direct'],
            ['table' => 'transactions', 'name' => '交易记录', 'type' => 'direct'],
            ['table' => 'callbacks', 'name' => '回调配置', 'type' => 'direct'],
            // 3. order_id 间接关联（依赖 orders）
            ['table' => 'certs', 'name' => '证书', 'type' => 'indirect'],
            ['table' => 'auto_deploy_reports', 'name' => '自动部署上报记录', 'type' => 'indirect'],
        ];
    }

    /**
     * 获取清理用的删除顺序（考虑外键依赖）
     */
    public static function purgeOrder(): array
    {
        return [
            // 1. 多态关联
            ['table' => 'notifications', 'name' => '通知', 'type' => 'notification'],
            // 2. 间接关联（必须在 orders 之前删除）
            ['table' => 'domain_validation_records', 'name' => '域名验证记录', 'type' => 'indirect'],
            ['table' => 'auto_deploy_reports', 'name' => '自动部署上报记录', 'type' => 'indirect'],
            ['table' => 'certs', 'name' => '证书', 'type' => 'indirect'],
            // tasks.order_id 同时承载 orders.id 与 acmes.id（ACME 任务），单独路径删除（在 orders/acmes 之前）
            ['table' => 'tasks', 'name' => '任务', 'type' => 'tasks'],
            // 3. 有 order_id 的直接表（在 orders 之前）
            ['table' => 'order_documents', 'name' => '订单验证文档', 'type' => 'direct'],
            // 4. 订单和其他直接表
            ['table' => 'orders', 'name' => '订单', 'type' => 'direct'],
            ['table' => 'acmes', 'name' => 'ACME订单', 'type' => 'direct'],
            ['table' => 'deploy_tokens', 'name' => '部署令牌', 'type' => 'direct'],
            ['table' => 'contacts', 'name' => '联系人', 'type' => 'direct'],
            ['table' => 'organizations', 'name' => '组织', 'type' => 'direct'],
            ['table' => 'api_tokens', 'name' => 'API令牌', 'type' => 'direct'],
            ['table' => 'funds', 'name' => '充值记录', 'type' => 'direct'],
            ['table' => 'transactions', 'name' => '交易记录', 'type' => 'direct'],
            ['table' => 'cname_delegations', 'name' => 'CNAME委托', 'type' => 'direct'],
            ['table' => 'callbacks', 'name' => '回调配置', 'type' => 'direct'],
            ['table' => 'user_logs', 'name' => '用户日志', 'type' => 'direct'],
            ['table' => 'api_logs', 'name' => 'API日志', 'type' => 'direct'],
            ['table' => 'user_refresh_tokens', 'name' => 'JWT刷新令牌', 'type' => 'direct'],
        ];
    }

    /**
     * 动态扫描其他含 user_id 字段的表
     */
    public static function dynamicTables(int $userId): array
    {
        $knownTables = array_merge(
            ['users'],
            array_column(self::directTables(), 'table'),
            array_column(self::indirectTables(), 'table'),
            array_column(self::purgeOnlyTables(), 'table'),
            ['notifications'],
        );

        $results = [];

        try {
            $database = DB::getDatabaseName();
            $placeholders = implode(',', array_fill(0, count($knownTables), '?'));
            $tables = DB::select(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ? AND TABLE_NAME NOT IN ($placeholders)",
                array_merge([$database, 'user_id'], $knownTables)
            );

            foreach ($tables as $table) {
                $tableName = $table->TABLE_NAME;
                $count = DB::table($tableName)->where('user_id', $userId)->count();
                if ($count > 0) {
                    $results[] = ['table' => $tableName, 'name' => $tableName, 'type' => 'dynamic'];
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return $results;
    }

    /**
     * 统计用户关联数据
     */
    public static function getStatistics(User $user): array
    {
        $stats = [];
        $schema = DB::getSchemaBuilder();

        // 用户自身
        $stats[] = ['用户', 1];

        // 直接表
        foreach (self::directTables() as $item) {
            if ($schema->hasTable($item['table'])) {
                $stats[] = [$item['name'], DB::table($item['table'])->where('user_id', $user->id)->count()];
            }
        }

        // 令牌/日志表
        foreach (self::purgeOnlyTables() as $item) {
            if ($schema->hasTable($item['table'])) {
                $stats[] = [$item['name'], DB::table($item['table'])->where('user_id', $user->id)->count()];
            }
        }

        // 间接表
        foreach (self::indirectTables() as $item) {
            if ($schema->hasTable($item['table'])) {
                $count = DB::table($item['table'])
                    ->whereIn('order_id', fn ($q) => $q->select('id')->from('orders')->where('user_id', $user->id))
                    ->count();

                // tasks.order_id 也可能指向 acmes.id（ACME 任务），需合并统计才不漏算
                if ($item['table'] === 'tasks' && $schema->hasTable('acmes')) {
                    $count += DB::table($item['table'])
                        ->whereIn('order_id', fn ($q) => $q->select('id')->from('acmes')->where('user_id', $user->id))
                        ->count();
                }

                $stats[] = [$item['name'], $count];
            }
        }

        // 通知
        if ($schema->hasTable('notifications')) {
            $stats[] = ['通知', DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->count()];
        }

        // 动态表
        foreach (self::dynamicTables($user->id) as $item) {
            $stats[] = [$item['name'], DB::table($item['table'])->where('user_id', $user->id)->count()];
        }

        return $stats;
    }
}

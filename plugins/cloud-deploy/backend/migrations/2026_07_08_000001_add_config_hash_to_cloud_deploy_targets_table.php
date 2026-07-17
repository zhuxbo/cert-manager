<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_deploy_targets')) {
            return;
        }

        if (! Schema::hasColumn('cloud_deploy_targets', 'config_hash')) {
            Schema::table('cloud_deploy_targets', function (Blueprint $table) {
                $table->char('config_hash', 64)->default('')->after('config')->comment('规范化 config SHA-256');
            });
        }

        DB::table('cloud_deploy_targets')
            ->select(['id', 'config'])
            ->orderBy('id')
            ->chunkById(200, function ($targets): void {
                foreach ($targets as $target) {
                    DB::table('cloud_deploy_targets')
                        ->where('id', $target->id)
                        ->update(['config_hash' => $this->configHash($target->config)]);
                }
            });

        $duplicate = DB::table('cloud_deploy_targets')
            ->select('user_id', 'access_id', 'product', 'config_hash', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('user_id', 'access_id', 'product', 'config_hash')
            ->having('aggregate', '>', 1)
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('cloud_deploy_targets 存在重复推送目标，请先清理后再添加唯一索引');
        }

        if (! Schema::hasIndex('cloud_deploy_targets', ['user_id', 'access_id', 'product', 'config_hash'])) {
            Schema::table('cloud_deploy_targets', function (Blueprint $table) {
                $table->unique(['user_id', 'access_id', 'product', 'config_hash'], 'cloud_deploy_targets_unique_target');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloud_deploy_targets')) {
            return;
        }

        if (Schema::hasIndex('cloud_deploy_targets', ['user_id', 'access_id', 'product', 'config_hash'])) {
            Schema::table('cloud_deploy_targets', function (Blueprint $table) {
                $table->dropUnique('cloud_deploy_targets_unique_target');
            });
        }

        if (Schema::hasColumn('cloud_deploy_targets', 'config_hash')) {
            Schema::table('cloud_deploy_targets', function (Blueprint $table) {
                $table->dropColumn('config_hash');
            });
        }
    }

    private function configHash(?string $config): string
    {
        $decoded = $this->decodeConfig($config);

        return hash('sha256', json_encode(
            $this->normalizeConfig($decoded),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function decodeConfig(?string $config): mixed
    {
        if ($config === null || $config === '') {
            return [];
        }

        $decoded = json_decode($config, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $config;
    }

    private function normalizeConfig(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeConfig($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
};

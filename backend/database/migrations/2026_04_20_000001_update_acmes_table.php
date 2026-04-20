<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acmes')) {
            return;
        }

        // eab_kid 索引（支持前缀搜索）
        if (Schema::hasColumn('acmes', 'eab_kid')) {
            $indexName = 'acmes_eab_kid_index';
            $exists = collect(Schema::getIndexes('acmes'))
                ->contains(fn ($idx) => ($idx['name'] ?? '') === $indexName);

            if (! $exists) {
                Schema::table('acmes', function (Blueprint $table) use ($indexName) {
                    $table->index('eab_kid', $indexName);
                });
            }
        }

        // plus 列（赠送时间，0/1）
        if (! Schema::hasColumn('acmes', 'plus')) {
            Schema::table('acmes', function (Blueprint $table) {
                $table->unsignedTinyInteger('plus')->default(0)->after('period')->comment('赠送时间');
            });
        }

        // channel 列（提交通道：web/admin/api/deploy/auto）
        if (! Schema::hasColumn('acmes', 'channel')) {
            Schema::table('acmes', function (Blueprint $table) {
                $table->string('channel', 20)->default('web')->after('status')->comment('提交通道：web/admin/api/deploy/auto');
            });
        }
    }
};

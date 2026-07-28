<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('setting_groups') || ! Schema::hasTable('settings')) {
            return;
        }

        // 幂等守卫：enum 已含 image 时跳过 DDL（DDL 隐式提交，重复执行也应无副作用）
        $typeColumn = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['settings', 'type'],
        );
        if ($typeColumn !== null && ! str_contains($typeColumn->column_type, "'image'")) {
            DB::statement("ALTER TABLE `settings` MODIFY COLUMN `type` ENUM('string','integer','float','boolean','select','array','base64','image') NOT NULL DEFAULT 'string' COMMENT '类型: string=字符串,integer=整数,float=浮点数,boolean=布尔值,select=选择框,array=数组,base64=Base64编码,image=图片'");
        }
    }
};

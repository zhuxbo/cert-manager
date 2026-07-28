<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('setting_groups')) {
            Schema::create('setting_groups', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement()->comment('ID');
                $table->string('name', 50)->unique()->comment('组名称');
                $table->string('title', 100)->comment('组标题');
                $table->string('description', 500)->nullable()->comment('组描述');
                $table->integer('weight')->default(0)->index()->comment('权重');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement()->comment('ID');
                $table->unsignedInteger('group_id')->comment('组ID');
                $table->string('key', 100)->comment('键名');
                $table->enum('type', ['string', 'integer', 'float', 'boolean', 'select', 'array', 'base64', 'image'])
                    ->default('string')
                    ->comment('类型: string=字符串,integer=整数,float=浮点数,boolean=布尔值,select=选择框,array=数组,base64=Base64编码,image=图片');
                $table->string('options', 2000)->nullable()->comment('选项');
                $table->boolean('is_multiple')->default(false)->comment('是否多选');
                $table->string('value', 10000)->nullable()->comment('键值');
                $table->string('description', 500)->nullable()->comment('描述');
                $table->integer('weight')->default(0)->index()->comment('权重');
                $table->timestamps();

                $table->unique(['group_id', 'key']);
                $table->foreign('group_id')->references('id')->on('setting_groups')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};

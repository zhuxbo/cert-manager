<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ca_logs')) {
            Schema::create('ca_logs', function (Blueprint $table) {
                $table->id();
                $table->string('url', 2000)->comment('请求URL');
                $table->string('api', 100)->nullable()->comment('API');
                $table->mediumtext('params')->nullable()->comment('请求参数');
                $table->mediumtext('response')->nullable()->comment('响应内容');
                $table->integer('status_code')->default(200)->index()->comment('状态码');
                $table->unsignedTinyInteger('status')->default(0)->index()->comment('状态: 0=失败, 1=成功');
                $table->decimal('duration')->default(0)->comment('耗时(秒)');
                $table->timestamp('created_at')->nullable()->index()->comment('创建时间');
            });
        }

        if (! Schema::hasTable('callback_logs')) {
            Schema::create('callback_logs', function (Blueprint $table) {
                $table->id();
                $table->string('module', 100)->nullable()->comment('模块');
                $table->string('action', 100)->nullable()->comment('操作');
                $table->string('method', 10)->index()->comment('请求方法');
                $table->string('url', 2000)->comment('请求地址');
                $table->mediumtext('params')->comment('回调参数');
                $table->mediumtext('response')->nullable()->comment('响应内容');
                $table->string('ip', 100)->nullable()->comment('IP地址');
                $table->unsignedTinyInteger('status')->default(0)->index()->comment('状态: 0=失败, 1=成功');
                $table->timestamp('created_at')->nullable()->index()->comment('创建时间');
                $table->index(['module', 'action', 'created_at']);
            });
        }

        if (! Schema::hasTable('api_logs')) {
            Schema::create('api_logs', function (Blueprint $table) {
                $table->id();
                $table->string('module', 100)->nullable()->comment('模块');
                $table->string('action', 100)->nullable()->comment('操作');
                $table->unsignedBigInteger('user_id')->nullable()->index()->comment('用户ID');
                $table->string('version', 10)->default('v2')->index()->comment('API版本');
                $table->string('method', 10)->index()->comment('请求方法');
                $table->string('url', 2000)->comment('请求URL');
                $table->mediumtext('params')->nullable()->comment('请求参数');
                $table->mediumtext('response')->nullable()->comment('响应内容');
                $table->integer('status_code')->default(200)->index()->comment('状态码');
                $table->unsignedTinyInteger('status')->default(0)->index()->comment('状态: 0=失败, 1=成功');
                $table->decimal('duration')->default(0)->comment('耗时(秒)');
                $table->string('ip', 100)->nullable()->comment('IP地址');
                $table->string('user_agent', 500)->nullable()->comment('User Agent');
                $table->timestamp('created_at')->nullable()->index()->comment('创建时间');
                $table->index(['module', 'action', 'created_at']);
            });
        }

        if (! Schema::hasTable('user_logs')) {
            Schema::create('user_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index()->comment('用户ID');
                $table->string('module', 100)->index()->comment('模块');
                $table->string('action', 100)->index()->comment('操作');
                $table->string('method', 10)->index()->comment('请求方法');
                $table->string('url', 2000)->comment('请求URL');
                $table->mediumtext('params')->nullable()->comment('请求参数');
                $table->mediumtext('response')->nullable()->comment('响应内容');
                $table->integer('status_code')->default(200)->index()->comment('状态码');
                $table->unsignedTinyInteger('status')->default(0)->index()->comment('状态: 0=失败, 1=成功');
                $table->decimal('duration')->default(0)->comment('耗时(秒)');
                $table->string('ip', 100)->nullable()->comment('IP地址');
                $table->string('user_agent', 500)->nullable()->comment('User Agent');
                $table->timestamp('created_at')->nullable()->index()->comment('创建时间');
            });
        }

        if (! Schema::hasTable('admin_logs')) {
            Schema::create('admin_logs', function (Blueprint $table) {
                $table->id();
                $table->integer('admin_id')->nullable()->index()->comment('管理员ID');
                $table->string('module', 100)->index()->comment('模块');
                $table->string('action', 100)->index()->comment('操作');
                $table->string('method', 10)->index()->comment('请求方法');
                $table->string('url', 2000)->comment('请求URL');
                $table->mediumtext('params')->nullable()->comment('请求参数');
                $table->mediumtext('response')->nullable()->comment('响应内容');
                $table->integer('status_code')->default(200)->index()->comment('状态码');
                $table->unsignedTinyInteger('status')->default(0)->index()->comment('状态: 0=失败, 1=成功');
                $table->decimal('duration')->default(0)->comment('耗时(秒)');
                $table->string('ip', 100)->nullable()->comment('IP地址');
                $table->string('user_agent', 500)->nullable()->comment('User Agent');
                $table->timestamp('created_at')->nullable()->index()->comment('创建时间');
            });
        }

        if (! Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('module', 100)->nullable()->comment('模块');
                $table->string('action', 100)->nullable()->comment('操作');
                $table->string('method', 10)->index()->comment('请求方法');
                $table->string('url', 2000)->comment('请求URL');
                $table->string('exception', 255)->comment('异常类型');
                $table->string('message', 1000)->comment('错误信息');
                $table->mediumtext('trace')->nullable()->comment('错误堆栈跟踪');
                $table->integer('status_code')->default(500)->index()->comment('状态码');
                $table->string('ip', 100)->nullable()->comment('IP地址');
                $table->timestamp('created_at')->nullable()->index()->comment('创建时间');
                $table->index(['module', 'action', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};

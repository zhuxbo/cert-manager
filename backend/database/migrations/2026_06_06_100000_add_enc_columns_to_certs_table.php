<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('certs')) {
            return;
        }

        Schema::table('certs', function (Blueprint $table) {
            // 国密双证书：加密证书 + 加密私钥（每张证书独立，与 cert/private_key 并列存本表，
            // 不入按 issuer 聚合的 chains 表，避免同 CA 多证书互相覆盖加密私钥）
            if (! Schema::hasColumn('certs', 'enc_cert')) {
                $table->mediumText('enc_cert')->nullable()->after('cert')->comment('国密加密证书');
            }
            if (! Schema::hasColumn('certs', 'enc_key')) {
                $table->mediumText('enc_key')->nullable()->after('enc_cert')->comment('国密加密私钥(GMT-0016)');
            }
            if (! Schema::hasColumn('certs', 'enc_key2')) {
                $table->mediumText('enc_key2')->nullable()->after('enc_key')->comment('国密加密私钥(GMT-0009)');
            }
        });
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};

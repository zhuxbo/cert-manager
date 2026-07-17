<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_deploy_remote_certs')) {
            return;
        }

        Schema::create('cloud_deploy_remote_certs', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary()->comment('ID');
            $table->unsignedBigInteger('user_id')->index()->comment('用户ID');
            $table->unsignedBigInteger('access_id')->index()->comment('凭证ID');
            $table->unsignedBigInteger('cert_id')->comment('本地证书ID');
            $table->string('fingerprint', 95)->comment('证书指纹');
            $table->string('store_kind', 32)->comment('证书存储空间标识 cas/slb/tencent_ssl');
            $table->string('remote_cert_id')->comment('云端证书ID');
            $table->timestamp('created_at')->nullable();
            // 去重键含 store_kind：CAS CertId 与 SLB ServerCertificateId 是不同标识空间，不可混用同 fingerprint
            $table->unique(['access_id', 'store_kind', 'fingerprint'], 'cloud_deploy_remote_certs_access_fp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploy_remote_certs');
    }
};

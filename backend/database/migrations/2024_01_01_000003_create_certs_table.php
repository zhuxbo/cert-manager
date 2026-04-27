<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('certs')) {
            Schema::create('certs', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary()->comment('ID');
                $table->unsignedBigInteger('order_id')->nullable()->index()->comment('订单ID');
                $table->unsignedBigInteger('last_cert_id')->nullable()->unique()->comment('上一个证书ID');
                $table->string('api_id', 200)->nullable()->index()->comment('接口ID');
                $table->string('vendor_id', 200)->nullable()->comment('CA订单ID');
                $table->string('refer_id', 200)->nullable()->unique()->comment('参考ID');
                $table->string('unique_value', 32)->nullable()->comment('唯一值');
                $table->string('issuer', 200)->nullable()->index()->comment('颁发者');
                $table->enum('action', ['new', 'renew', 'reissue'])->default('new')->comment('操作');
                $table->enum('channel', ['admin', 'web', 'api', 'deploy', 'auto'])->comment('渠道');
                $table->mediumText('params')->nullable()->comment('参数');
                $table->decimal('amount', 10)->default(0)->comment('金额');
                $table->string('common_name', 256)->index()->comment('通用名称');
                $table->mediumText('alternative_names')->nullable()->comment('备用名称');
                $table->string('email', 500)->nullable()->index()->comment('邮箱地址');
                $table->integer('standard_count')->default(0)->comment('标准域名数');
                $table->integer('wildcard_count')->default(0)->comment('通配符数');
                $table->mediumText('dcv')->nullable()->comment('验证信息');
                $table->mediumText('validation')->nullable()->comment('验证');
                $table->string('documents', 2000)->nullable()->comment('验证文档列表');
                $table->timestamp('issued_at')->nullable()->index()->comment('颁发时间');
                $table->timestamp('expires_at')->nullable()->index()->comment('过期时间');
                $table->timestamp('auto_deploy_at')->nullable()->comment('自动部署时间');
                $table->char('csr_md5', 32)->nullable()->index()->comment('CSR的MD5');
                $table->mediumText('csr')->nullable()->comment('CSR');
                $table->mediumText('private_key')->nullable()->comment('私钥');
                $table->mediumText('cert')->nullable()->comment('证书');
                $table->string('serial_number', 128)->nullable()->comment('序列号');
                $table->string('fingerprint', 128)->nullable()->comment('指纹');
                $table->string('encryption_alg', 20)->nullable()->comment('加密算法');
                $table->unsignedSmallInteger('encryption_bits')->default(0)->comment('加密位数');
                $table->string('signature_digest_alg', 20)->nullable()->comment('签名摘要算法');
                $table->unsignedTinyInteger('cert_apply_status')->default(0)->comment('证书申请状态: 0=未提交CA, 2=已提交CA');
                $table->unsignedTinyInteger('domain_verify_status')->default(0)->comment('域名验证状态: 0=未开始, 1=进行中, 2=已完成');
                $table->unsignedTinyInteger('org_verify_status')->default(0)->comment('组织验证状态: 0=未开始, 1=进行中, 2=已完成');
                $table->enum('status', [
                    'unpaid',
                    'pending',
                    'processing',
                    'approving',
                    'active',
                    'failed',
                    'cancelling',
                    'cancelled',
                    'revoked',
                    'renewed',
                    'reissued',
                    'expired',
                ])->default('unpaid')->index()->comment('状态');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};

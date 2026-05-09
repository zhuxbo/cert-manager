<?php

use App\Models\Cert;
use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 删除废弃的 vendor_cert_id 列
        if (Schema::hasColumn('certs', 'vendor_cert_id')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->dropColumn('vendor_cert_id');
            });
        }

        // 添加 auto_deploy_at 列
        if (! Schema::hasColumn('certs', 'auto_deploy_at')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->timestamp('auto_deploy_at')->nullable()->comment('自动部署时间')->after('expires_at');
            });
        }

        // 添加 expires_at 索引
        if (! collect(Schema::getIndexes('certs'))->contains('name', 'certs_expires_at_index')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->index('expires_at');
            });
        }

        // 添加 issued_at 索引
        if (! collect(Schema::getIndexes('certs'))->contains('name', 'certs_issued_at_index')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->index('issued_at');
            });
        }

        // 修改 status 枚举，移除 'revoking'
        // mysql 下 column 类型字符串包含 enum 选项，根据是否含 'revoking' 决定是否需要迁移（幂等）
        $statusCol = collect(Schema::getColumns('certs'))->firstWhere('name', 'status');
        if ($statusCol && str_contains($statusCol['type'], "'revoking'")) {
            DB::table('certs')->where('status', 'revoking')->update(['status' => 'cancelling']);

            Schema::table('certs', function (Blueprint $table) {
                $table->enum('status', [
                    'unpaid', 'pending', 'processing', 'approving', 'active', 'failed',
                    'cancelling', 'cancelled', 'revoked', 'renewed', 'reissued', 'expired',
                ])->default('unpaid')->comment('状态')->change();
            });
        }

        // 数据迁移 channel='acme' → 'api'
        DB::table('certs')->where('channel', 'acme')->update(['channel' => 'api']);

        // 修改 channel 枚举：移除 'acme'，添加 'auto'（自动续签发起）
        $channelCol = collect(Schema::getColumns('certs'))->firstWhere('name', 'channel');
        if ($channelCol && (str_contains($channelCol['type'], "'acme'") || ! str_contains($channelCol['type'], "'auto'"))) {
            Schema::table('certs', function (Blueprint $table) {
                $table->enum('channel', ['admin', 'web', 'api', 'deploy', 'auto'])
                    ->comment('渠道')
                    ->change();
            });
        }

        // delegation dcv 数据迁移 — 复用原 2026_01_28_100000 的逻辑（params 列在 schema 里是 mediumText，全表扫即可）
        $certs = Cert::all()->filter(function ($cert) {
            return is_array($cert->params) && ($cert->params['validation_method'] ?? null) === 'delegation';
        });

        foreach ($certs as $cert) {
            $dcv = $cert->dcv;

            if (($dcv['method'] ?? '') === 'txt' && empty($dcv['is_delegate'])) {
                $dcv['is_delegate'] = true;

                $order = Order::with('product')->find($cert->order_id);
                if ($order && $order->product) {
                    $dcv['ca'] = strtolower($order->product->ca);
                }

                $cert->dcv = $dcv;
                $cert->save();
            }
        }

        // 统一 tinyInteger → unsignedTinyInteger
        $col = collect(Schema::getColumns('certs'))->firstWhere('name', 'cert_apply_status');
        if ($col && str_contains($col['type'], 'tinyint') && ! str_contains($col['type'], 'unsigned')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->unsignedTinyInteger('cert_apply_status')->default(0)->comment('证书申请状态: 0=未提交CA, 2=已提交CA')->change();
                $table->unsignedTinyInteger('domain_verify_status')->default(0)->comment('域名验证状态: 0=未开始, 1=进行中, 2=已完成')->change();
                $table->unsignedTinyInteger('org_verify_status')->default(0)->comment('组织验证状态: 0=未开始, 1=进行中, 2=已完成')->change();
            });
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};

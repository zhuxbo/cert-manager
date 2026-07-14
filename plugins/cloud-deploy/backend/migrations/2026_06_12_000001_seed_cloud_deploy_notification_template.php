<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $code = 'cloud_deploy_failed';

    public function up(): void
    {
        // content 必须 base64_encode：NotificationTemplate::content accessor get=base64_decode，
        // 直写明文会被解码成乱码。模板走 Blade::render，用 {{ $var }} 语法。表无 is_html 列（走 Builder _meta）。
        $blade = "云部署失败\n\n".
            "产品：{{ \$product }}\n".
            "域名：{{ \$domain }}\n".
            "云账号：{{ \$access_name }}\n".
            "错误码：{{ \$error_code }}\n\n".
            '证书已签发但推送到云平台失败，请登录系统查看部署日志并手动重推。';

        DB::table('notification_templates')->insertOrIgnore([
            'code' => $this->code,
            'name' => '云部署失败',
            'content' => base64_encode($blade),
            'variables' => json_encode([
                'product',
                'domain',
                'access_name',
                'error_code',
            ], JSON_THROW_ON_ERROR),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('code', $this->code)->delete();
    }
};

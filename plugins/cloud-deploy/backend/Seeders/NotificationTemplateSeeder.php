<?php

namespace Plugins\CloudDeploy\Seeders;

use App\Contracts\ProvidesNotificationTemplateDefaults;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder implements ProvidesNotificationTemplateDefaults
{
    public function notificationTemplateDefaults(): array
    {
        $content = <<<'BLADE'
云部署失败

产品：{{ $product }}
域名：{{ $domain }}
云账号：{{ $access_name }}
错误码：{{ $error_code }}

证书已签发但推送到云平台失败，请登录系统查看部署日志并手动重推。
BLADE;

        return [
            [
                'code' => 'cloud_deploy_failed',
                'name' => '云部署失败',
                'content' => $content,
                'variables' => [
                    'product',
                    'domain',
                    'access_name',
                    'error_code',
                ],
                'example' => null,
                'status' => 1,
            ],
        ];
    }

    public function run(): void
    {
        foreach ($this->notificationTemplateDefaults() as $template) {
            NotificationTemplate::firstOrCreate(
                ['code' => $template['code']],
                [
                    'name' => $template['name'],
                    'content' => $template['content'],
                    'variables' => $template['variables'],
                    'example' => $template['example'] ?? null,
                    'status' => $template['status'] ?? 1,
                ]
            );
        }
    }

    public function clear(): void
    {
        NotificationTemplate::where('code', 'cloud_deploy_failed')->delete();
    }
}

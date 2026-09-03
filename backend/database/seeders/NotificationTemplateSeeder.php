<?php

namespace Database\Seeders;

use App\Contracts\ProvidesNotificationTemplateDefaults;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder implements ProvidesNotificationTemplateDefaults
{
    public function notificationTemplateDefaults(): array
    {
        return [
            // 证书签发通知
            [
                'code' => 'cert_issued',
                'name' => '证书签发通知',
                'content' => $this->getOrderIssuedHtml(),
                'variables' => ['order_id', 'email'],
                'example' => null,
            ],
            // 证书到期提醒
            [
                'code' => 'cert_expire',
                'name' => '证书到期提醒',
                'content' => $this->getOrderExpireHtml(),
                'variables' => ['email'],
                'example' => null,
            ],
            // ACME 订阅到期提醒（订阅到期 ≠ 证书到期：到期后 certbot 无法继续自动续签）
            [
                'code' => 'acme_expire',
                'name' => 'ACME 订阅到期提醒',
                'content' => $this->getAcmeExpireHtml(),
                // username/subscriptions/site_url 由 Builder 读取用户与订阅数据后生成，测试发送只允许覆盖收件邮箱。
                'variables' => ['email'],
                'example' => null,
            ],
            // 续期停滞孤儿提醒（续费/重签把前驱证书终态化后，后续证书长期卡在非 active 停滞态、前驱即将到期）。
            // site_url/site_name 不列入：由 CertRenewStalledNotificationBuilder 从系统设置注入。
            [
                'code' => 'cert_renew_stalled',
                'name' => '证书续期停滞提醒',
                'content' => $this->getCertRenewStalledHtml(),
                // username/certificates/site_url/site_name 均由 Builder 查询或注入。
                'variables' => ['email'],
                'example' => null,
            ],
            // 续签订单取消一次性通知（续费/重签订单在非恢复态取消后，原证书脱离续期监控）。
            // site_url/site_name 不列入：由 CertRenewCancelledNotificationBuilder 从系统设置注入。
            [
                'code' => 'cert_renew_cancelled',
                'name' => '证书续签取消提醒',
                'content' => $this->getCertRenewCancelledHtml(),
                'variables' => ['email', 'common_name', 'expires_at', 'order_id', 'product_type'],
                'example' => null,
            ],
            // 证书吊销一次性通知（Order sync 直写 revoked 终态：证书被 CA 吊销、立即失去信任）。
            // site_url/site_name 不列入：由 CertRevokedNotificationBuilder 从系统设置注入。
            [
                'code' => 'cert_revoked',
                'name' => '证书吊销提醒',
                'content' => $this->getCertRevokedHtml(),
                'variables' => ['email', 'common_name', 'expires_at', 'order_id', 'is_successor', 'product_type'],
                'example' => null,
            ],
            // 安全通知
            [
                'code' => 'security',
                'name' => '安全通知',
                'content' => '您好 {{ $username }}，您的账号发生安全变更：{{ $event }}，如非本人操作请及时处理。',
                'variables' => ['event'],
                'example' => '您好 test，您的密码已修改，如非本人操作请及时处理。',
            ],
            // 用户创建通知
            [
                'code' => 'user_created',
                'name' => '用户创建通知',
                'content' => '您好，我们为您创建了账号，用户名 {{ $username }}，密码 {{ $password }}，登录地址 {{ $site_url }}',
                // site_url 不列入：由 UserCreatedNotificationBuilder 从系统设置注入，测试发送无需手填
                'variables' => ['username', 'password'],
                'example' => '您好，我们为您创建了账号，用户名 test，密码 123456，登录地址 www.example.com',
            ],
            // 自动续费/重签失败提醒（schedule:auto-renew 处理失败时发给订单用户）
            [
                'code' => 'auto_renew_failed',
                'name' => '自动续费/重签失败提醒',
                'content' => $this->getAutoRenewFailedHtml(),
                // site_url 不列入：由 AutoRenewFailedNotificationBuilder 从系统设置注入，测试发送无需手填
                'variables' => [
                    'common_name',
                    'action',
                    'reason',
                ],
                'example' => null,
            ],
            // 余额前瞻预警（schedule:balance-forecast 周一 09:30 触发）：未来 30 天自动续费余额不足。
            // required 是「预估上限」，模板文案「预计最多需要」。site_url 由 Builder 从系统设置注入。
            [
                'code' => 'balance_forecast',
                'name' => '余额前瞻预警',
                'content' => $this->getBalanceForecastHtml(),
                'variables' => [
                    'available',
                    'required',
                    'shortfall',
                    'certificates',
                ],
                'example' => null,
            ],
            // 任务失败告警
            [
                'code' => 'task_failed',
                'name' => '任务失败告警',
                'content' => $this->getTaskFailedHtml(),
                'variables' => [
                    'task_id',
                    'error_message',
                ],
                'example' => null,
            ],
            // 资金审计告警（finance:audit 命令每天 03:00 触发）
            [
                'code' => 'finance_audit',
                'name' => '资金审计告警',
                'content' => $this->getFinanceAuditHtml(),
                'variables' => [
                    'violation_count',
                    'violations',
                    'detected_at',
                ],
                'example' => null,
            ],
            // 通用运维/健康告警（admin-only）：SystemAlert 服务经 system_alert code 触发，
            // E1/E3~E5 监控命令与后续 F/G/H 复用。details 由 SystemAlertNotificationBuilder 过滤，
            // 模板一律 Blade {{ }} 转义（禁 {!! !!}），防外部可控文本 XSS 进管理员邮箱。
            [
                'code' => 'system_alert',
                'name' => '运维告警',
                'content' => $this->getSystemAlertHtml(),
                'variables' => [
                    'category',
                    'title',
                    'message',
                ],
                'example' => null,
            ],
        ];
    }

    public function run(): void
    {
        $templates = $this->notificationTemplateDefaults();
        foreach ($templates as $template) {
            // finance_audit：旧版本 code 为 finance_audit_alert，已由迁移改名为 finance_audit。
            // 用 updateOrCreate 按 code 匹配，避免重复 seed 产生重复行；
            // 仅更新结构性字段（name/variables/example），保留管理员可能自定义的 content 与 status。
            if ($template['code'] === 'finance_audit') {
                $existing = NotificationTemplate::query()->where('code', 'finance_audit')->first();

                NotificationTemplate::updateOrCreate(
                    ['code' => 'finance_audit'],
                    [
                        'name' => $template['name'],
                        'variables' => $template['variables'],
                        'example' => $template['example'],
                        // 新建时填默认内容/启用；已存在则保留管理员现有配置
                        'content' => $existing->content ?? $template['content'],
                        'status' => $existing->status ?? 1,
                    ]
                );

                continue;
            }

            NotificationTemplate::firstOrCreate(
                ['code' => $template['code']],
                [
                    'name' => $template['name'],
                    'content' => $template['content'],
                    'variables' => $template['variables'],
                    'example' => $template['example'] ?? null,
                    'status' => 1,
                ]
            );
        }
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getOrderIssuedHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $product_type_label ?? 'SSL' }} 证书已签发</title>
    <style>
        /* 基础重置 */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        /* 移动端与暗黑模式适配 */
        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            /* 移动端上下间距稍微减小一点，避免太空 */
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .content-cell { background-color: #1a1a1a !important; color: #e1e1e1 !important; }
            .card-info { background-color: #252525 !important; border: 1px solid #333333 !important; }
            h1, h2, h3, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #ffffff !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        您申请的 {{ $product_type_label ?? 'SSL' }} 证书 {{ $domain }} 已成功签发{{ ($has_attachment ?? true) ? '，请查收附件' : '' }}。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #10b981; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="content-cell mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ✅ {{ $product_type_label ?? 'SSL' }} 证书已成功签发
                                </h1>

                                <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    尊敬的 <span class="highlight-text" style="color: #10b981; font-weight: 600;">{{ $username }}</span>，您好：
                                </p>

                                <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    您在 <a href="{{ $site_url }}" style="color: #10b981; text-decoration: none; font-weight: 600;">{{ $site_name }}</a> 申请的证书审核通过，现已正式签发。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 24px;">
                                    <tr>
                                        <td class="card-info" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px;">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">证书标识</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="padding-bottom: 16px; font-size: 18px; font-weight: 600; color: #333333; font-family: monospace;">{{ $domain }}</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">产品名称</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="font-size: 16px; color: #333333; font-family: sans-serif;">{{ $product }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                @if($has_attachment ?? true)
                                <div style="background-color: #ecfdf5; border-left: 4px solid #10b981; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p style="margin: 0; font-size: 15px; line-height: 24px; color: #065f46;">
                                        <strong>📎 附件提醒：</strong><br>
                                        证书文件已打包为 ZIP 附件，请下载后解压并安装。
                                    </p>
                                </div>
                                @endif

                                <p style="margin: 0; font-size: 15px; line-height: 24px; color: #666666;">
                                    如果您在安装过程中遇到任何问题，或附件无法下载，请随时登录控制台或联系我们的技术支持。
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    感谢您选择 <a href="{{ $site_url }}" style="color: #999999; text-decoration: underline;">{{ $site_name }}</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getOrderExpireHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>证书到期提醒</title>
    <style>
        /* 基础重置 */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        /* 移动端适配 */
        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
            /* 强制表格在手机端滚动或调整字号 */
            .data-table th, .data-table td { font-size: 12px !important; padding: 10px 5px !important; }
        }

        /* 暗黑模式适配 */
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f59e0b !important; }
            /* 表格暗黑模式 */
            .data-table th { background-color: #333333 !important; color: #cccccc !important; border-bottom: 1px solid #444 !important; }
            .data-table td { border-bottom: 1px solid #333 !important; color: #e1e1e1 !important; }
            .warning-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .warning-text { color: #fbbf24 !important; }
            .error-box { background-color: #3b1818 !important; border-left-color: #dc2626 !important; }
            .error-text { color: #f87171 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        您的证书即将到期，请尽快处理。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⚠️ 证书到期提醒
                                </h1>

                                <p style="margin: 0 0 15px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    尊敬的 <span class="highlight-text" style="color: #f59e0b; font-weight: 600;">{{ $username }}</span>，您好：
                                </p>

                                <p style="margin: 0 0 25px 0; font-size: 15px; line-height: 26px; color: #555555;">
                                    您的下列证书即将到期。请尽快检查续期安排或联系我们处理，避免证书到期影响对应业务。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="data-table" style="margin-bottom: 24px; border-collapse: collapse; width: 100%;">
                                    <thead>
                                        <tr style="background-color: #fffbeb;">
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">序号</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">证书类型</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">证书标识</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">到期时间</th>
                                            <th align="center" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">剩余</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {{-- 数据库模板中的 Blade 循环 --}}
                                        @foreach($certificates as $index => $cert)
                                        <tr>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666;">
                                                {{ $index + 1 }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; font-weight: 600; color: #333333; font-family: monospace;">
                                                {{ $cert['product_type_label'] ?? 'SSL' }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; font-weight: 600; color: #333333; font-family: monospace;">
                                                {{ $cert['domain'] }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666;">
                                                {{ $cert['expire_at'] }}
                                            </td>
                                            <td align="center" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px;">
                                                @if($cert['days_left'] <= 7)
                                                    <span style="background-color: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px;">{{ $cert['days_left'] }}天</span>
                                                @else
                                                    <span style="background-color: #fffbeb; color: #d97706; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px;">{{ $cert['days_left'] }}天</span>
                                                @endif
                                            </td>
                                        </tr>
                                        @endforeach
                                        {{-- 循环结束 --}}
                                    </tbody>
                                </table>

                                <div class="warning-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 30px;">
                                    <p class="warning-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        <strong>重要提示：</strong><br>
                                        @if($has_ssl_certificate ?? true)
                                            SSL 证书到期后，浏览器可能拦截 HTTPS 访问并显示“不安全”警告。
                                        @else
                                            证书到期后将不再具备有效信任状态，可能影响身份验证、签名或加密业务。
                                        @endif
                                    </p>
                                </div>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#f59e0b; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                查看证书
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    感谢您选择 <a href="{{ $site_url }}" style="color: #999999; text-decoration: underline;">{{ $site_name }}</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getAcmeExpireHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACME 订阅到期提醒</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
            .data-table th, .data-table td { font-size: 12px !important; padding: 10px 5px !important; }
        }

        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f59e0b !important; }
            .data-table th { background-color: #333333 !important; color: #cccccc !important; border-bottom: 1px solid #444 !important; }
            .data-table td { border-bottom: 1px solid #333 !important; color: #e1e1e1 !important; }
            .warning-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .warning-text { color: #fbbf24 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        您的 ACME 订阅即将到期，到期后自动续签将中断。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⚠️ ACME 订阅到期提醒
                                </h1>

                                <p style="margin: 0 0 15px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    尊敬的 <span class="highlight-text" style="color: #f59e0b; font-weight: 600;">{{ $username }}</span>，您好：
                                </p>

                                <p style="margin: 0 0 25px 0; font-size: 15px; line-height: 26px; color: #555555;">
                                    您的下列 ACME 订阅即将到期。<strong>订阅到期后，ACME 客户端（如 certbot）将无法继续自动续签证书</strong>；已签发的证书会在其各自有效期到期后失效。请及时续订 ACME 订阅，避免自动化链路中断。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="data-table" style="margin-bottom: 24px; border-collapse: collapse; width: 100%;">
                                    <thead>
                                        <tr style="background-color: #fffbeb;">
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">序号</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">产品</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">订阅标识</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">到期时间</th>
                                            <th align="center" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">剩余</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($subscriptions as $index => $sub)
                                        <tr>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666;">
                                                {{ $index + 1 }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; font-weight: 600; color: #333333;">
                                                {{ $sub['product_name'] }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666; font-family: monospace;">
                                                {{ $sub['eab_kid'] }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666;">
                                                {{ $sub['expire_at'] }}
                                            </td>
                                            <td align="center" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px;">
                                                @if($sub['days_left'] <= 7)
                                                    <span style="background-color: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px;">{{ $sub['days_left'] }}天</span>
                                                @else
                                                    <span style="background-color: #fffbeb; color: #d97706; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px;">{{ $sub['days_left'] }}天</span>
                                                @endif
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <div class="warning-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 30px;">
                                    <p class="warning-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        <strong>重要提示：</strong><br>
                                        订阅到期后 ACME 客户端续签将被 CA 拒绝，证书无人续期，最终随有效期到期失效，导致站点“不安全”告警。
                                    </p>
                                </div>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#f59e0b; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                续订订阅
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    感谢您选择 <a href="{{ $site_url }}" style="color: #999999; text-decoration: underline;">{{ $site_name }}</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getCertRenewStalledHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>证书续期停滞提醒</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
            .data-table th, .data-table td { font-size: 12px !important; padding: 10px 5px !important; }
        }

        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f59e0b !important; }
            .data-table th { background-color: #333333 !important; color: #cccccc !important; border-bottom: 1px solid #444 !important; }
            .data-table td { border-bottom: 1px solid #333 !important; color: #e1e1e1 !important; }
            .notice-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .notice-text { color: #fbbf24 !important; }
            .warning-box { background-color: #3b1818 !important; border-left-color: #dc2626 !important; }
            .warning-text { color: #f87171 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        您的证书续期/重签流程未正常完成，原证书即将到期，请尽快处理以免服务中断。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⚠️ 证书续期停滞提醒
                                </h1>

                                <p style="margin: 0 0 15px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    尊敬的 <span class="highlight-text" style="color: #f59e0b; font-weight: 600;">{{ $username }}</span>，您好：
                                </p>

                                <div class="notice-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p class="notice-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        这是一封<strong>续期停滞重要提醒</strong>：您的证书续期/重签流程未正常完成、原证书即将到期。因涉及服务中断风险，本提醒不受常规到期提醒偏好控制。
                                    </p>
                                </div>

                                <p style="margin: 0 0 25px 0; font-size: 15px; line-height: 26px; color: #555555;">
                                    下列证书的续期/重签订单仍在进行中或未完成，而原证书即将到期。请按每张证书的处理建议尽快处理，以免原证书到期造成服务中断。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="data-table" style="margin-bottom: 24px; border-collapse: collapse; width: 100%;">
                                    <thead>
                                        <tr style="background-color: #fffbeb;">
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">序号</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">证书类型</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">证书标识</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">到期时间</th>
                                            <th align="center" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">剩余</th>
                                            <th align="left" style="padding: 12px 10px; border-bottom: 2px solid #fcd34d; font-size: 13px; font-weight: 600; color: #92400e; text-transform: uppercase;">状态与处理建议</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($certificates as $index => $cert)
                                        @php($stall_label = ['unpaid' => '未支付', 'pending' => '处理中', 'processing' => '验证中', 'approving' => '审核中', 'failed' => '已失败'][$cert['stall_status'] ?? ''] ?? '停滞')
                                        <tr>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666; vertical-align: top;">
                                                {{ $index + 1 }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; font-weight: 600; color: #333333; font-family: monospace; vertical-align: top;">
                                                {{ $cert['product_type_label'] ?? 'SSL' }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; font-weight: 600; color: #333333; font-family: monospace; vertical-align: top;">
                                                {{ $cert['domain'] }}
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; color: #666666; vertical-align: top;">
                                                {{ $cert['expire_at'] }}
                                            </td>
                                            <td align="center" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 14px; vertical-align: top;">
                                                @if($cert['days_left'] <= 7)
                                                    <span style="background-color: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px;">{{ $cert['days_left'] }}天</span>
                                                @else
                                                    <span style="background-color: #fffbeb; color: #d97706; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px;">{{ $cert['days_left'] }}天</span>
                                                @endif
                                            </td>
                                            <td align="left" style="padding: 12px 10px; border-bottom: 1px solid #eeeeee; font-size: 13px; color: #555555; vertical-align: top;">
                                                <span style="display: inline-block; background-color: #eef2ff; color: #4338ca; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 12px; margin-bottom: 4px;">{{ $stall_label }}</span><br>
                                                {{ $cert['action_hint'] }}
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <div class="warning-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 30px;">
                                    <p class="warning-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        <strong>重要提示：</strong><br>
                                        @if($has_ssl_certificate ?? true)
                                            SSL 证书到期后，浏览器可能拦截 HTTPS 访问并显示“不安全”警告。
                                        @else
                                            证书到期后将不再具备有效信任状态，可能影响身份验证、签名或加密业务。
                                        @endif
                                        若已扣费的订单长时间未完成，请勿重复下单/支付，直接联系客服核实。
                                    </p>
                                </div>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#f59e0b; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                登录控制台处理
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    感谢您选择 <a href="{{ $site_url }}" style="color: #999999; text-decoration: underline;">{{ $site_name }}</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getCertRenewCancelledHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $product_type_label ?? 'SSL' }} 证书续签取消提醒</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
        }

        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f59e0b !important; }
            .card-info { background-color: #252525 !important; border: 1px solid #333333 !important; }
            .notice-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .notice-text { color: #fbbf24 !important; }
            .warning-box { background-color: #3b1818 !important; border-left-color: #dc2626 !important; }
            .warning-text { color: #f87171 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        您的 {{ $product_type_label ?? 'SSL' }} 证书续签订单已取消，原证书不再受续期监控，如需继续使用请手动续期。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⚠️ {{ $product_type_label ?? 'SSL' }} 证书续签取消提醒
                                </h1>

                                <p style="margin: 0 0 15px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    尊敬的 <span class="highlight-text" style="color: #f59e0b; font-weight: 600;">{{ $username }}</span>，您好：
                                </p>

                                <div class="notice-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p class="notice-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        这是一封<strong>证书续签取消重要提醒</strong>：因涉及服务连续性风险，本提醒不受常规到期提醒偏好控制。
                                    </p>
                                </div>

                                <p style="margin: 0 0 20px 0; font-size: 15px; line-height: 26px; color: #555555;">
                                    您的 {{ $product_type_label ?? 'SSL' }} 证书续签订单（订单号 {{ $order_id }}）已取消。原证书目前仍在有效期内，但<strong>已不再受自动续期、到期提醒与续期停滞监控</strong>。如需继续使用该证书，请在到期前<strong>手动重新发起续期</strong>。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 24px;">
                                    <tr>
                                        <td class="card-info" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px;">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">原证书标识</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="padding-bottom: 16px; font-size: 18px; font-weight: 600; color: #333333; font-family: monospace;">{{ $common_name }}</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">原证书到期时间</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="font-size: 16px; color: #333333; font-family: sans-serif;">{{ $expires_at }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <div class="warning-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 30px;">
                                    <p class="warning-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        <strong>重要提示：</strong><br>
                                        @if(($product_type ?? 'ssl') === 'ssl')
                                            原 SSL 证书到期后，浏览器可能拦截 HTTPS 访问并显示“不安全”警告。
                                        @else
                                            原证书到期后将不再具备有效信任状态，可能影响身份验证、签名或加密业务。
                                        @endif
                                        由于该证书已脱离自动续期监控，系统不会再就其到期向您发送提醒，请务必自行安排手动续期。
                                    </p>
                                </div>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#f59e0b; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                登录控制台处理
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    感谢您选择 <a href="{{ $site_url }}" style="color: #999999; text-decoration: underline;">{{ $site_name }}</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getCertRevokedHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $product_type_label ?? 'SSL' }} 证书吊销提醒</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
        }

        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f87171 !important; }
            .card-info { background-color: #252525 !important; border: 1px solid #333333 !important; }
            .notice-box { background-color: #3b1818 !important; border-left-color: #dc2626 !important; }
            .notice-text { color: #f87171 !important; }
            .successor-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .successor-text { color: #fbbf24 !important; }
            .warning-box { background-color: #3b1818 !important; border-left-color: #dc2626 !important; }
            .warning-text { color: #f87171 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        您的 {{ $product_type_label ?? 'SSL' }} 证书 {{ $common_name }} 已被证书颁发机构吊销。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #dc2626; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⛔ {{ $product_type_label ?? 'SSL' }} 证书吊销提醒
                                </h1>

                                <p style="margin: 0 0 15px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    尊敬的 <span class="highlight-text" style="color: #dc2626; font-weight: 600;">{{ $username }}</span>，您好：
                                </p>

                                <div class="notice-box" style="background-color: #fef2f2; border-left: 4px solid #dc2626; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p class="notice-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #991b1b;">
                                        这是一封<strong>证书吊销重要提醒</strong>：因涉及服务中断风险，本提醒不受常规到期提醒偏好控制。
                                    </p>
                                </div>

                                <p style="margin: 0 0 20px 0; font-size: 15px; line-height: 26px; color: #555555;">
                                    您的 {{ $product_type_label ?? 'SSL' }} 证书（订单号 {{ $order_id }}）已被证书颁发机构（CA）<strong>吊销</strong>，将<strong>立即失去信任</strong>。
                                    @if(($product_type ?? 'ssl') === 'ssl')
                                        浏览器可能拦截 HTTPS 访问并显示“不安全”警告。如仍需提供网站服务，请尽快<strong>重新申请证书</strong>。
                                    @else
                                        这可能影响身份验证、签名或加密业务。如仍需使用相关业务，请尽快<strong>重新申请证书</strong>。
                                    @endif
                                </p>

                                @if($is_successor)
                                <div class="successor-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p class="successor-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #92400e;">
                                        <strong>请注意：</strong>被吊销的是续签后签发的新证书，原证书已因本次续期<strong>不再受自动续期、到期提醒与续期停滞监控</strong>。系统不会再就原证书到期向您发送提醒，请务必自行安排手动续期。
                                    </p>
                                </div>
                                @endif

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 24px;">
                                    <tr>
                                        <td class="card-info" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px;">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">被吊销证书标识</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="padding-bottom: 16px; font-size: 18px; font-weight: 600; color: #333333; font-family: monospace;">{{ $common_name }}</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">原到期时间</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="font-size: 16px; color: #333333; font-family: sans-serif;">{{ $expires_at }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <div class="warning-box" style="background-color: #fef2f2; border-left: 4px solid #dc2626; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 30px;">
                                    <p class="warning-text" style="margin: 0; font-size: 14px; line-height: 22px; color: #991b1b;">
                                        <strong>重要提示：</strong><br>
                                        证书吊销不可撤销。若吊销并非您本人操作或不清楚原因，请尽快登录控制台核实并联系技术支持。
                                    </p>
                                </div>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#dc2626; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                登录控制台处理
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    感谢您选择 <a href="{{ $site_url }}" style="color: #999999; text-decoration: underline;">{{ $site_name }}</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getAutoRenewFailedHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSL 证书自动续期失败</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f59e0b !important; }
            .card-info { background-color: #252525 !important; border: 1px solid #333333 !important; }
            .reason-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .reason-text { color: #fbbf24 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    @php($action_label = ($action ?? '') === 'renew' ? '续费' : (($action ?? '') === 'reissue' ? '重签' : '续期'))

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        证书 {{ $common_name }} 自动{{ $action_label }}失败，请尽快处理以免证书到期失效。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⚠️ SSL 证书自动{{ $action_label }}失败
                                </h1>

                                <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    您好，系统在为下列订单自动{{ $action_label }}时遇到问题，未能完成。为避免证书到期影响网站访问，请尽快登录控制台处理。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 24px;">
                                    <tr>
                                        <td class="card-info" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px;">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">证书域名</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="padding-bottom: 16px; font-size: 18px; font-weight: 600; color: #333333; font-family: monospace;">{{ $common_name }}</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 14px; color: #888888; font-family: sans-serif;">操作类型</td>
                                                </tr>
                                                <tr>
                                                    <td class="highlight-text" style="font-size: 16px; color: #333333; font-family: sans-serif;">自动{{ $action_label }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <div class="reason-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p class="reason-text" style="margin: 0; font-size: 15px; line-height: 24px; color: #92400e;">
                                        <strong>失败原因：</strong><br>
                                        {{ $reason }}
                                    </p>
                                </div>

                                <p style="margin: 0 0 12px 0; font-size: 15px; line-height: 24px; color: #666666;">
                                    请按以下情况对照处理，处理后系统会在后续检测窗口自动重试：
                                </p>
                                <p style="margin: 0 0 8px 0; font-size: 15px; line-height: 24px; color: #555555;">
                                    • <strong>账户余额不足</strong>：请充值后等待自动重试，或手动续期
                                </p>
                                <p style="margin: 0 0 8px 0; font-size: 15px; line-height: 24px; color: #555555;">
                                    • <strong>域名委托无效</strong>：请配置并验证域名 CNAME 委托
                                </p>
                                <p style="margin: 0 0 8px 0; font-size: 15px; line-height: 24px; color: #555555;">
                                    • <strong>IP 地址证书</strong>：IP 证书自动续签须由自动部署工具发起，请配置自动部署工具
                                </p>
                                <p style="margin: 0 0 28px 0; font-size: 15px; line-height: 24px; color: #555555;">
                                    • <strong>其他情况</strong>：若以上均已确认仍未成功，请联系客服协助处理
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#f59e0b; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                登录控制台
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    本邮件由系统自动发送，请勿直接回复。
                                </p>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getBalanceForecastHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>账户余额前瞻预警</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div, td { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .highlight-text { color: #f59e0b !important; }
            .card-info { background-color: #252525 !important; border: 1px solid #333333 !important; }
            .cert-row { border-bottom-color: #333333 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        未来 30 天内有证书将自动续费，预计最多需要 {{ $required }} 元，当前可用额度不足，请及时充值。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    💰 账户余额前瞻预警
                                </h1>

                                <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 26px; color: #555555;">
                                    您好，未来 30 天内有以下证书将自动续费。按当前价格<strong>预计最多需要 {{ $required }} 元</strong>，
                                    而您的账户当前可用额度为 {{ $available }} 元，尚差约 {{ $shortfall }} 元。为避免因余额不足导致自动续费失败，请及时充值。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 24px;">
                                    <tr>
                                        <td class="card-info" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px;">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                <tr>
                                                    <td style="padding-bottom: 8px; font-size: 13px; color: #888888; font-family: sans-serif;">证书域名</td>
                                                    <td style="padding-bottom: 8px; font-size: 13px; color: #888888; font-family: sans-serif; text-align: right;">到期日 / 预估金额</td>
                                                </tr>
                                                @foreach($certificates as $cert)
                                                <tr class="cert-row" style="border-bottom: 1px solid #eeeeee;">
                                                    <td style="padding: 10px 0; font-size: 14px; color: #333333; font-family: monospace;">{{ $cert['common_name'] ?? '' }}</td>
                                                    <td style="padding: 10px 0; font-size: 14px; color: #333333; font-family: sans-serif; text-align: right;">{{ $cert['expires_at'] ?? '' }} · {{ $cert['amount'] ?? '' }} 元</td>
                                                </tr>
                                                @endforeach
                                                <tr>
                                                    <td style="padding-top: 14px; font-size: 15px; font-weight: 600; color: #333333;">预计最多合计</td>
                                                    <td class="highlight-text" style="padding-top: 14px; font-size: 18px; font-weight: 700; color: #f59e0b; text-align: right;">{{ $required }} 元</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <p style="margin: 0 0 28px 0; font-size: 13px; line-height: 22px; color: #999999;">
                                    说明：以上为预估上限。实际扣费以续费当日为准，部分证书若因委托未配置等原因未能续费则不会扣费，因此真实扣费可能低于此金额。
                                </p>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $site_url }}" style="background-color:#f59e0b; border-radius:4px; color:#ffffff; display:inline-block; font-family:sans-serif; font-size:16px; font-weight:bold; line-height:44px; text-align:center; text-decoration:none; width:200px; -webkit-text-size-adjust:none;">
                                                登录控制台充值
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    本邮件由系统自动发送，请勿直接回复。
                                </p>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection HtmlUnknownTarget
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     * @noinspection CssNonIntegerLengthInPixels
     */
    private function getTaskFailedHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>队列任务执行失败</title>
    <style>
        /* 基础重置 */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        /* 移动端适配 */
        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
            /* 手机端表格变为块级显示，标签和值换行 */
            .data-row td { display: block !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important; border: none !important; }
            .data-label { padding-bottom: 4px !important; font-size: 12px !important; color: #999 !important; }
            .data-value { padding-bottom: 16px !important; border-bottom: 1px solid #eee !important; }
        }

        /* 暗黑模式适配 */
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .data-label { color: #888888 !important; }
            .data-value { color: #e1e1e1 !important; border-bottom-color: #333 !important; }
            .code-block { background-color: #111 !important; border: 1px solid #333 !important; color: #a5b4fc !important; }
            .error-text { color: #f87171 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        任务执行失败：{{ $task_action }} (订单ID: {{ $order_id }}) - {{ $error_message }}
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 680px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #dc2626; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td width="40" style="padding-right: 15px; vertical-align: middle;">
                                            <img src="https://img.icons8.com/fluency/48/cancel.png" width="32" height="32" alt="Error" style="display: block; border: 0;">
                                        </td>
                                        <td style="vertical-align: middle;">
                                            <h1 style="margin: 0; font-size: 20px; line-height: 30px; color: #dc2626; font-weight: 700;">
                                                队列任务执行失败
                                            </h1>
                                        </td>
                                    </tr>
                                </table>

                                <div style="margin-top: 20px; margin-bottom: 25px; height: 1px; background-color: #eeeeee; font-size: 0; line-height: 0;">&nbsp;</div>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse: collapse;">

                                    <tr class="data-row">
                                        <td class="data-label" width="30%" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">订单 ID</td>
                                        <td class="data-value" style="padding: 10px 0; font-size: 14px; color: #333333; font-family: monospace; font-weight: 600; border-bottom: 1px solid #f0f0f0;">
                                            {{ $order_id }}
                                        </td>
                                    </tr>

                                    <tr class="data-row">
                                        <td class="data-label" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">任务记录 ID</td>
                                        <td class="data-value" style="padding: 10px 0; font-size: 14px; color: #333333; font-family: monospace; border-bottom: 1px solid #f0f0f0;">
                                            {{ $task_id }}
                                        </td>
                                    </tr>

                                    <tr class="data-row">
                                        <td class="data-label" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">动作 (Action)</td>
                                        <td class="data-value" style="padding: 10px 0; font-size: 14px; color: #333333; border-bottom: 1px solid #f0f0f0;">
                                            {{ $task_action }}
                                        </td>
                                    </tr>

                                    <tr class="data-row">
                                        <td class="data-label" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">执行状态</td>
                                        <td class="data-value" style="padding: 10px 0; font-size: 14px; color: #333333; border-bottom: 1px solid #f0f0f0;">
                                            <span style="background-color: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-weight: bold;">
                                                {{ $task_status }}
                                            </span>
                                        </td>
                                    </tr>

                                    <tr class="data-row">
                                        <td class="data-label" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">执行次数</td>
                                        <td class="data-value" style="padding: 10px 0; font-size: 14px; color: #333333; border-bottom: 1px solid #f0f0f0;">
                                            {{ $attempts }}
                                        </td>
                                    </tr>

                                    <tr class="data-row">
                                        <td class="data-label" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">错误信息</td>
                                        <td class="data-value error-text" style="padding: 10px 0; font-size: 14px; color: #dc2626; font-weight: 600; border-bottom: 1px solid #f0f0f0;">
                                            {{ $error_message }}
                                        </td>
                                    </tr>

                                    <tr class="data-row">
                                        <td class="data-label" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0;">时间</td>
                                        <td class="data-value" style="padding: 10px 0; font-size: 13px; color: #555555; border-bottom: 1px solid #f0f0f0;">
                                            创建于: {{ $created_at }}<br>
                                            执行于: {{ $executed_at }}
                                        </td>
                                    </tr>
                                </table>

                                <div style="margin-top: 30px; margin-bottom: 10px;">
                                    <span style="font-size: 14px; font-weight: bold; color: #333333; text-transform: uppercase; letter-spacing: 0.5px;">运行结果详情</span>
                                </div>

                                <div class="code-block" style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 15px; font-size: 13px; line-height: 1.6; color: #333;">
                                    <div style="font-weight: bold; margin-bottom: 5px; color: #555;">Params:</div>
                                    <pre style="margin: 0; white-space: pre-wrap; word-break: break-all; font-family: 'Menlo', 'Consolas', monospace; font-size: 12px; color: #4b5563;">{{ $params }}</pre>
                                </div>

                                <div class="code-block" style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 15px; font-size: 13px; line-height: 1.6; color: #333;">
                                    <div style="font-weight: bold; margin-bottom: 5px; color: #555;">Result:</div>
                                    <pre style="margin: 0; white-space: pre-wrap; word-break: break-all; font-family: 'Menlo', 'Consolas', monospace; font-size: 12px; color: #4b5563;">{{ $result }}</pre>
                                </div>

                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getFinanceAuditHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>资金审计告警</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div { color: #e1e1e1 !important; }
            .code-block { background-color: #111 !important; border: 1px solid #333 !important; color: #fca5a5 !important; }
            .layer-badge { background-color: #3b1818 !important; color: #f87171 !important; }
            .summary-box { background-color: #3b1818 !important; border-left-color: #dc2626 !important; }
            .summary-text { color: #fca5a5 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        资金审计校验发现 {{ $violation_count }} 项违反，请立即排查。
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 680px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #dc2626; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 10px 0; font-size: 22px; line-height: 30px; color: #dc2626; font-weight: 700;">
                                    🚨 资金审计告警
                                </h1>

                                <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 22px; color: #888888;">
                                    检测时间：{{ $detected_at }}
                                </p>

                                <div class="summary-box" style="background-color: #fef2f2; border-left: 4px solid #dc2626; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 28px;">
                                    <p class="summary-text" style="margin: 0; font-size: 15px; line-height: 24px; color: #991b1b;">
                                        <strong>共发现 {{ $violation_count }} 项不变式违反。</strong><br>
                                        请立即排查 funds / transactions / users.balance 相关数据，并在控制台运行 <code>php artisan finance:audit</code> 复核。
                                    </p>
                                </div>

                                @foreach ($violations as $v)
                                <div style="margin-bottom: 24px; padding: 16px; border: 1px solid #fecaca; border-radius: 6px; background-color: #fffafa;">
                                    <div style="margin-bottom: 10px;">
                                        <span class="layer-badge" style="display: inline-block; background-color: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 700; font-family: monospace;">
                                            {{ $v['layer'] ?? '?' }}
                                        </span>
                                        <span style="margin-left: 8px; font-size: 14px; color: #444444;">{{ $v['message'] ?? '' }}</span>
                                    </div>

                                    @if (! empty($v['rows']))
                                    <div class="code-block" style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; padding: 12px; margin-top: 8px;">
                                        <pre style="margin: 0; white-space: pre-wrap; word-break: break-all; font-family: 'Menlo', 'Consolas', monospace; font-size: 12px; line-height: 1.6; color: #4b5563;">{{ json_encode($v['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                    @endif

                                    @if (! empty($v['rows_total']) && $v['rows_total'] > count($v['rows'] ?? []))
                                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #888888;">
                                        共 {{ $v['rows_total'] }} 行，仅显示前 {{ count($v['rows']) }} 行。
                                    </p>
                                    @endif
                                </div>
                                @endforeach

                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * 通用运维告警模板（system_alert）。
     *
     * 全部 Blade {{ }} 转义输出、禁用 {!! !!}：title/message/details 均可能含上游 msg、
     * 域名等外部可控文本，SystemAlertNotificationBuilder 已截断/掩码，模板再以 {{ }} 转义
     * 兜底，防 XSS 进管理员邮箱。details 为过滤后的一层键值标量。
     *
     * @noinspection CssRedundantUnit
     * @noinspection HtmlDeprecatedTag
     * @noinspection HtmlDeprecatedAttribute
     * @noinspection XmlDeprecatedElement
     * @noinspection CssReplaceWithShorthandSafely
     */
    private function getSystemAlertHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>运维告警</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        table { border-collapse: collapse !important; }
        body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f6f8; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; margin: auto !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .wrapper-padding { padding-top: 30px !important; padding-bottom: 30px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .outer-wrapper { background-color: #2d2d2d !important; }
            .white-card { background-color: #1f1f1f !important; border: 1px solid #333333 !important; }
            h1, h2, h3, p, span, div, td { color: #e1e1e1 !important; }
            .footer-text { color: #888888 !important; }
            .category-badge { background-color: #33240a !important; color: #fbbf24 !important; }
            .message-box { background-color: #332b00 !important; border-left-color: #f59e0b !important; }
            .message-text { color: #fbbf24 !important; }
            .detail-key { color: #888888 !important; }
            .detail-value { color: #e1e1e1 !important; border-bottom-color: #333 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8;">

    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all; font-family: sans-serif;">
        运维告警：{{ $title }} - {{ $message }}
    </div>

    <center style="width: 100%; background-color: #f4f6f8;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="outer-wrapper" style="background-color: #f4f6f8;">
            <tr>
                <td align="center" class="wrapper-padding" style="padding-top: 50px; padding-bottom: 50px; padding-left: 10px; padding-right: 10px;">

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="white-card" style="max-width: 640px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: left;">

                        <tr>
                            <td style="background-color: #f59e0b; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="padding: 40px 40px 30px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

                                <h1 style="margin: 0 0 12px 0; font-size: 22px; line-height: 30px; color: #333333; font-weight: 700;">
                                    ⚠️ {{ $title }}
                                </h1>

                                @if(! empty($category))
                                <p style="margin: 0 0 20px 0;">
                                    <span class="category-badge" style="display: inline-block; background-color: #fff7ed; color: #b45309; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 700; font-family: monospace;">{{ $category }}</span>
                                </p>
                                @endif

                                <div class="message-box" style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 0 4px 4px 0; margin-bottom: 24px;">
                                    <p class="message-text" style="margin: 0; font-size: 15px; line-height: 24px; color: #92400e; word-break: break-word;">
                                        {{ $message }}
                                    </p>
                                </div>

                                @if(! empty($details))
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse: collapse; margin-bottom: 12px;">
                                    @foreach($details as $key => $value)
                                    <tr>
                                        <td class="detail-key" width="35%" style="padding: 10px 0; font-size: 13px; color: #888888; vertical-align: top; border-bottom: 1px solid #f0f0f0; word-break: break-all;">{{ $key }}</td>
                                        <td class="detail-value" style="padding: 10px 0; font-size: 14px; color: #333333; font-family: monospace; border-bottom: 1px solid #f0f0f0; word-break: break-all;">{{ $value }}</td>
                                    </tr>
                                    @endforeach
                                </table>
                                @endif

                                <p style="margin: 16px 0 0 0; font-size: 13px; line-height: 20px; color: #999999;">
                                    本邮件由系统监控自动发送，请登录控制台核查处理。
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <td class="mobile-padding" style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eeeeee;">
                                <p class="footer-text" style="margin: 0; font-size: 13px; line-height: 20px; color: #999999; font-family: sans-serif;">
                                    本邮件由系统自动发送，请勿直接回复。
                                </p>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }
}

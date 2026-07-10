<?php

namespace App\Services\Notification\Builders;

use App\Models\Admin;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * 通用运维/健康告警 Builder（code=system_alert，admin-only，包0 前置共享件）。
 *
 * 携密不入库的机制化兜底（非措辞）：notifications.data cast=json 明文落库且随邮件外发，
 * details 若被未来携密调用方（如证书链排障、云平台凭据告警）误塞 token/PEM/secret，
 * 会明文泄漏。本 Builder 对 details 施加四道机制——标量化 + 敏感键 denylist（含
 * authorization/bearer/sign）+ PEM/JWT 值掩码/超长截断 + 键数上界——让误塞结构性挡在
 * notifications.data 外；并对外部可控的 title/message 先截断，模板一律 Blade {{ }}
 * 转义（禁 {!! !!}），防外部文本 XSS 进管理员邮箱。
 *
 * 显式不回落 DefaultNotificationBuilder（后者直通 context 无过滤）。
 */
class SystemAlertNotificationBuilder implements NotificationBuilderInterface
{
    /** 敏感键名 denylist：键名命中即值掩码（键保留便于定位） */
    private const SENSITIVE_KEY_PATTERN = '/token|secret|password|passwd|key|private|pem|cert|hmac|apiclient|credential|authorization|bearer|sign/i';

    /** 值内容 PEM 兜底：命中即整值掩码，防超长截断上限内仍泄漏 PEM 片段 */
    private const PEM_VALUE_PATTERN = '/-----BEGIN|PRIVATE KEY/';

    /** JWT 值启发式：eyJ（base64url 的 '{"'）开头且达到最小长度 → 整值掩码；不做通用 base64 检测（误伤面大） */
    private const JWT_VALUE_PREFIX = 'eyJ';

    private const JWT_MIN_VALUE_LENGTH = 40;

    private const MASK = '***';

    private const NON_SCALAR_PLACEHOLDER = '[filtered:non-scalar]';

    /** details 单值长度上限（超出截断加省略号） */
    private const MAX_VALUE_LENGTH = 200;

    /** details 键数上界（超出截断并追加 _truncated 占位） */
    private const MAX_KEYS = 20;

    /** 外部可控标题长度上限 */
    private const MAX_TITLE_LENGTH = 100;

    /** 外部可控正文长度上限 */
    private const MAX_MESSAGE_LENGTH = 500;

    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        if (! $notifiable instanceof Admin) {
            throw new RuntimeException('通知接收者必须为管理员');
        }

        // email 映射（读端，镜像 FinanceAuditNotificationBuilder）：写端由 SystemAlert::send
        // 显式传 admin_email，缺失才回落 notifiable->email。为空则抛异常（对齐既有 Builder）。
        $email = ($intent->context['admin_email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('管理员邮箱为空');
        }

        $context = $intent->context;

        // 顶层白名单：仅 category/title/message/details/email 入 payload.data，其余顶层键丢弃
        $category = (string) ($context['category'] ?? '');
        $title = mb_substr((string) ($context['title'] ?? ''), 0, self::MAX_TITLE_LENGTH);
        $message = mb_substr((string) ($context['message'] ?? ''), 0, self::MAX_MESSAGE_LENGTH);
        $details = $this->filterDetails((array) ($context['details'] ?? []));

        $subject = get_system_setting('site', 'name', 'SSL证书管理系统').' 运维告警';

        $data = [
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'details' => $details,
            'admin_email' => $email,
            'email' => $email,
            'subject' => $subject,
            '_meta' => [
                'email' => $email,
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }

    /**
     * details 机制化过滤：键数上界 → 敏感键掩码 → 标量化 → PEM/JWT 值掩码/超长截断。
     *
     * @param  array<array-key, mixed>  $details
     * @return array<string, mixed>
     */
    private function filterDetails(array $details): array
    {
        $filtered = [];
        $processed = 0;
        $total = count($details);

        foreach ($details as $key => $value) {
            // 0) 键数上界：仅保留前 MAX_KEYS 个键，其余截断并以占位记数
            if ($processed >= self::MAX_KEYS) {
                $filtered['_truncated'] = '[truncated:'.($total - self::MAX_KEYS).' keys]';

                break;
            }
            $processed++;

            $keyStr = (string) $key;

            // 1) 敏感键 denylist：键名命中 → 值掩码，键保留便于定位（不论值类型）
            if (preg_match(self::SENSITIVE_KEY_PATTERN, $keyStr)) {
                $filtered[$keyStr] = self::MASK;

                continue;
            }

            // 2) 标量化：非标量（array/object/null 等）→ 占位，不泄漏结构与内容
            if (! is_scalar($value)) {
                $filtered[$keyStr] = self::NON_SCALAR_PLACEHOLDER;

                continue;
            }

            // 3) 值内容兜底（仅字符串）：PEM/JWT 整值掩码 + 超长截断
            if (is_string($value)) {
                if (preg_match(self::PEM_VALUE_PATTERN, $value)) {
                    $filtered[$keyStr] = self::MASK;

                    continue;
                }

                if (str_starts_with($value, self::JWT_VALUE_PREFIX) && mb_strlen($value) >= self::JWT_MIN_VALUE_LENGTH) {
                    $filtered[$keyStr] = self::MASK;

                    continue;
                }

                if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                    $filtered[$keyStr] = mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…';

                    continue;
                }
            }

            $filtered[$keyStr] = $value;
        }

        return $filtered;
    }
}

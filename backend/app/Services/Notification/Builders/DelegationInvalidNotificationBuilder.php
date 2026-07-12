<?php

namespace App\Services\Notification\Builders;

use App\Models\CnameDelegation;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * 委托失效提醒 Builder（镜像 CertRenewStalledNotificationBuilder）。
 *
 * delegation:check 周巡检确认委托无效（经 unreachable 分档与熔断过滤）+ fail_count≥阈值 +
 * 有 active 证书引用其域名时触发，按 user 聚合、每用户一封。委托失效 → 自动续期域名验证失败 →
 * 证书静默过期，属服务连续性，故强制发（不入 user_default_preferences，穿透用户已关的到期偏好）。
 *
 * 数据来源钉死 context 显式传入的 delegation_ids（禁自行全表扫失效委托，否则脱离命令侧
 * gate/熔断的选择集）；Builder 只按 ids 重载 + 过滤 valid=false（读持久列、不再查 DNS），
 * 全恢复/全删则返 null 不发。
 *
 * 携密/隐私不入库：payload 仅域名/前缀/目标/失败次数 + 固定用户友好文案；**last_error 绝不进
 * payload**（其异常路径可含 SQLSTATE 回显嵌套 SQL/内部主机名，仅留 cron 日志与 console）。
 */
class DelegationInvalidNotificationBuilder implements NotificationBuilderInterface
{
    /** 固定用户友好文案（常量化，模板只渲染不做逻辑；绝不含 last_error/原始异常）。 */
    private const INVALID_HINT = '下列域名的 CNAME 委托解析未配置或已失效。请登录控制台检查并重新配置委托解析（CNAME 记录），否则自动续期将无法完成域名验证，可能导致证书到期无法自动续签、服务中断。';

    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        if (! $notifiable instanceof User) {
            throw new RuntimeException('通知接收者必须为用户');
        }

        $email = ($intent->context['email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('邮箱为空');
        }

        $ids = array_map('intval', $intent->context['delegation_ids'] ?? []);
        $delegations = $this->fetchInvalidDelegations($ids);

        $rows = [];
        foreach ($delegations as $delegation) {
            $rows[] = [
                'zone' => $delegation->zone,
                'prefix' => $delegation->prefix,
                'target_fqdn' => $delegation->target_fqdn,
                'fail_count' => $delegation->fail_count,
            ];
        }

        // 异步延迟内委托恢复/被删 → 过滤后为空 → 返 null 不发（读持久标记，非再查 DNS）
        if (empty($rows)) {
            return null;
        }

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
        $subject = '域名委托失效提醒 ['.$siteName.']';

        $data = [
            'username' => $notifiable->username,
            'email' => $email,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'delegations' => $rows,
            'hint' => self::INVALID_HINT,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }

    /**
     * 按 ids 重载并过滤 valid=false 的委托（读持久列、不再查 DNS，零 DNS 放大）。
     *
     * 已删行经 whereIn 自然剔除（null-guard），valid=true（异步延迟内已恢复）经 where 过滤——
     * `valid` 与 `fail_count` 在 applyProbeOutcome 内同写，Builder 只查 valid=false 结构性 ⊆
     * 命令侧 dispatch 选择集，不可能过发。抽为可覆盖方法以便 Unit 测 makePartial 覆盖注入缝
     * （镜像 CertRenewStalledNotificationBuilder::fetchStalledPairs）。
     *
     * @param  int[]  $ids
     * @return Collection<int, CnameDelegation>
     */
    protected function fetchInvalidDelegations(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return CnameDelegation::query()
            ->whereIn('id', $ids)
            ->where('valid', false)
            ->get();
    }
}

<?php

namespace App\Services\Notification\Builders;

use App\Bootstrap\ApiExceptions;
use App\Console\Commands\Concerns\ExpireNotifyWindow;
use App\Models\Acme;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use DateMalformedStringException;
use DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * ACME 订阅到期提醒 Builder（镜像 CertExpireNotificationBuilder）。
 *
 * 语义区分：period_till = ACME 订阅（EAB 签发权）到期，≠ 已签发 TLS 证书到期。
 * 订阅到期后 ACME 客户端（certbot）下次续签被 CA 拒（EAB 失效），已签发证书随各自有效期到期。
 *
 * 携密不入库：仅白名单字段进 payload（product_name / eab_kid 前缀 / period_till / days_left），
 * eab_hmac 默认 hidden 且绝不进 payload。
 */
class AcmeExpireNotificationBuilder implements NotificationBuilderInterface
{
    use ExpireNotifyWindow;

    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        if (! $notifiable instanceof User) {
            throw new RuntimeException('通知接收者必须为用户');
        }

        $email = ($intent->context['email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('邮箱为空');
        }

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');

        $acmes = $this->fetchExpiringAcmes($notifiable);

        $subscriptions = [];

        foreach ($acmes as $acme) {
            if (! $acme->period_till) {
                continue;
            }

            try {
                $daysLeft = (int) (new DateTime)->diff(new DateTime((string) $acme->period_till))->format('%a');
            } catch (DateMalformedStringException $e) {
                app(ApiExceptions::class)->logException($e);
                $daysLeft = 0;
            }

            $subscriptions[] = [
                'product_name' => $acme->product->name ?: ($acme->product->api_id ?? 'ACME'),
                'eab_kid' => $this->maskEabKid((string) $acme->eab_kid),
                'expire_at' => $acme->period_till->format('Y-m-d'),
                'days_left' => $daysLeft,
            ];
        }

        if (empty($subscriptions)) {
            return null;
        }

        $subject = 'ACME 订阅到期提醒 ['.$siteName.']';
        $data = [
            'username' => $notifiable->username,
            'email' => $email,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'subscriptions' => $subscriptions,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }

    /**
     * 拉取该用户 max(EXPIRE_NOTIFY_NODES) 天内到期的活跃 ACME 订阅。
     *
     * 连续超集窗口（逐字镜像 CertExpireNotificationBuilder::fetchExpiringOrders）：派发侧用离散节点
     * （14/7/3/1 防每日重复），Builder 侧用连续超集——NotificationJob 异步延迟 Δ 后以 now()=T0+Δ 重算
     * 窗口，离散窗口会滑过 period_till 落入节点空隙致重查为空/静默漏发；连续超集在任意 Δ 下恒覆盖派发侧
     * 全部节点，且同封一并列出落在节点空隙的其他订阅。窗口上界由 max(EXPIRE_NOTIFY_NODES) 单一源派生
     * （非硬编码 14，对齐 StalledRenewalQuery::forUser）：派发/重查两侧同随节点集演进，防节点扩成含 >14
     * 天时派发侧发了 intent 而此处窗口未覆盖 → build 返 null 整封静默漏发。抽为可覆盖方法以便 Unit 测试 mock。
     *
     * @return Collection<int, Acme>
     */
    protected function fetchExpiringAcmes(User $user): Collection
    {
        // whereHas('product') 与 CertExpire::fetchExpiringOrders 对齐：产品被硬删（acmes.product_id
        // 无外键、Admin destroy 无引用守卫）的孤儿订阅若不过滤，build 内解引用 $acme->product->name
        // 抛 ErrorException → 该 user 整封 acme_expire（含同封其他有效订阅）静默漏发
        return Acme::with('product')
            ->whereHas('product')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereBetween('period_till', [now(), now()->addDays(max(self::EXPIRE_NOTIFY_NODES))])
            ->orderBy('period_till')
            ->get();
    }

    /**
     * 截断 eab_kid，仅展示前缀标识订阅（不暴露完整 kid，绝不带 eab_hmac）。
     */
    private function maskEabKid(string $eabKid): string
    {
        if ($eabKid === '') {
            return '';
        }

        return strlen($eabKid) <= 8 ? $eabKid : substr($eabKid, 0, 8).'…';
    }
}

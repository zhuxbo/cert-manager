<?php

namespace App\Services\Notification\Builders;

use App\Bootstrap\ApiExceptions;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Order\AutoRenewService;
use DateMalformedStringException;
use DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CertExpireNotificationBuilder implements NotificationBuilderInterface
{
    public function __construct(
        private readonly AutoRenewService $autoRenewService
    ) {}

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

        $orders = $this->fetchExpiringOrders($notifiable);

        $certificates = [];

        foreach ($orders as $order) {
            // 排除"会被 AutoRenewCommand 妥善处理"的订单（与 ExpireCommand 去重口径完全一致）：
            //   - API channel 订单 AutoRenewCommand 不处理 → 不排除（照常进汇总邮件）
            //   - 其余 willAutoRenewExecute||willAutoReissueExecute 为真 → 排除（交由 auto_renew_failed 提醒）
            // 注意：不再按委托有效性细分。委托未配置/失败的自动订单同样由 AutoRenewCommand 发 auto_renew_failed，
            // 这里若保留则会与 auto_renew_failed 双发，故统一排除。
            if ($order->latestCert->channel !== 'api'
                && ($this->autoRenewService->willAutoRenewExecute($order, $notifiable)
                    || $this->autoRenewService->willAutoReissueExecute($order, $notifiable))) {
                continue;
            }

            try {
                $daysLeft = (int) (new DateTime)->diff(new DateTime((string) $order->latestCert->expires_at))->format('%a');
            } catch (DateMalformedStringException $e) {
                app(ApiExceptions::class)->logException($e);
                $daysLeft = 0;
            }

            $certificates[] = [
                'domain' => $order->latestCert->common_name,
                'expire_at' => $order->latestCert->expires_at->format('Y-m-d'),
                'days_left' => $daysLeft,
                'delegation_status' => 'need_renew',
            ];
        }

        if (empty($certificates)) {
            return null;
        }

        $subject = 'SSL证书到期提醒 ['.$siteName.']';
        $data = [
            'username' => $notifiable->username,
            'email' => $email,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'certificates' => $certificates,
            'subject' => $subject,
            // 保留键以兼容历史模板；去重后到期邮件只列"需手动续期"证书，故恒为 false
            'has_delegation_issue' => false,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }

    /**
     * 拉取该用户 14 天内到期的活跃证书订单。
     * 抽出为可覆盖方法以便 Unit 测试 mock，避免在 builder 内嵌静态 Eloquent 查询。
     *
     * @return Collection<int, Order>
     */
    protected function fetchExpiringOrders(User $user): Collection
    {
        return Order::with(['product', 'latestCert', 'user'])
            ->whereHas('product')
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->whereBetween('expires_at', [now(), now()->addDays(14)])
                    ->orderBy('expires_at');
            })
            ->where('user_id', $user->id)
            ->get();
    }
}

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
        $hasDelegationIssue = false;

        foreach ($orders as $order) {
            try {
                $daysLeft = (int) (new DateTime)->diff(new DateTime((string) $order->latestCert->expires_at))->format('%a');
            } catch (DateMalformedStringException $e) {
                app(ApiExceptions::class)->logException($e);
                $daysLeft = 0;
            }

            // 检查自动任务是否会实际执行
            $willAutoRenew = $this->autoRenewService->willAutoRenewExecute($order, $notifiable);
            $willAutoReissue = $this->autoRenewService->willAutoReissueExecute($order, $notifiable);

            // 如果自动任务会实际执行，检查委托有效性
            if ($willAutoRenew || $willAutoReissue) {
                $ca = strtolower($order->product->ca ?? '');
                $domains = $order->latestCert->alternative_names;
                $delegationValid = $this->autoRenewService->checkDelegationValidity($notifiable->id, $domains, $ca);

                if ($delegationValid) {
                    // 委托有效，完全跳过该证书（不发通知）
                    continue;
                }

                // 委托无效，加入通知列表并标记
                $hasDelegationIssue = true;
                $certificates[] = [
                    'domain' => $order->latestCert->common_name,
                    'expire_at' => $order->latestCert->expires_at->format('Y-m-d'),
                    'days_left' => $daysLeft,
                    'delegation_status' => 'invalid',
                ];
            } else {
                // 自动任务不会执行，加入通知列表
                $certificates[] = [
                    'domain' => $order->latestCert->common_name,
                    'expire_at' => $order->latestCert->expires_at->format('Y-m-d'),
                    'days_left' => $daysLeft,
                    'delegation_status' => 'need_renew',
                ];
            }
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
            'has_delegation_issue' => $hasDelegationIssue,
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

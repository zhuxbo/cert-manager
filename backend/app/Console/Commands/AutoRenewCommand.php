<?php

namespace App\Console\Commands;

use App\Exceptions\ApiResponseException;
use App\Models\Order;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use Illuminate\Console\Command;
use Throwable;

class AutoRenewCommand extends Command
{
    protected $signature = 'schedule:auto-renew';

    protected $description = '自动续费/重签即将到期的证书';

    public function handle(): void
    {
        $this->info('开始自动续费/重签任务...');

        // 查询需要自动续费的订单
        $renewOrders = $this->getRenewOrders();
        $this->processOrders($renewOrders, 'renew');

        // 查询需要自动重签的订单
        $reissueOrders = $this->getReissueOrders();
        $this->processOrders($reissueOrders, 'reissue');

        $this->info('自动续费/重签任务完成');
    }

    /**
     * 获取需要续费的订单
     * 条件：
     * - auto_renew = true
     * - period_till - latestCert.expires_at < 7天（订单与证书到期时间接近）
     * - latestCert.expires_at > now()->subDays(15)（证书未过期超过15天）
     * - latestCert.expires_at < now()->addDays(15)（证书即将到期）
     * - latestCert.status = 'active'
     */
    private function getRenewOrders()
    {
        return Order::with(['user', 'product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product', function ($query) {
                $query->where('status', 1)->where('renew', 1);
            })
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '>', now()->subDays(15))
                    ->where('expires_at', '<', now()->addDays(15));
            })
            ->where('auto_renew', true)
            // 订单到期时间与证书到期时间相差小于7天
            ->whereRaw('DATEDIFF(period_till, (SELECT expires_at FROM certs WHERE certs.id = orders.latest_cert_id)) < 7')
            ->get();
    }

    /**
     * 获取需要重签的订单
     * 条件：
     * - auto_renew = true
     * - period_till - latestCert.expires_at > 7天（订单周期还有余量）
     * - latestCert.expires_at > now()->subDays(15)（证书未过期超过15天）
     * - latestCert.expires_at < now()->addDays(15)（证书即将到期）
     * - latestCert.status = 'active'
     */
    private function getReissueOrders()
    {
        return Order::with(['user', 'product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product', function ($query) {
                $query->where('status', 1);
            })
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '>', now()->subDays(15))
                    ->where('expires_at', '<', now()->addDays(15));
            })
            ->where('auto_renew', true)
            // 订单到期时间与证书到期时间相差大于7天
            ->whereRaw('DATEDIFF(period_till, (SELECT expires_at FROM certs WHERE certs.id = orders.latest_cert_id)) > 7')
            ->get();
    }

    /**
     * 处理订单
     */
    private function processOrders($orders, string $action): void
    {
        foreach ($orders as $order) {
            try {
                $this->processOrder($order, $action);
            } catch (Throwable $e) {
                $this->error("订单 #{$order->id} {$action} 失败: {$e->getMessage()}");
                $this->sendFailureNotification($order, $action, $e->getMessage());
            }
        }
    }

    /**
     * 处理单个订单
     */
    private function processOrder(Order $order, string $action): void
    {
        $user = $order->user;
        $cert = $order->latestCert;

        $this->info("处理订单 #{$order->id} ({$action}): {$cert->common_name}");

        // 续费需要检查余额
        if ($action === 'renew') {
            // 计算可用余额：balance + |credit_limit|
            $availableBalance = bcadd($user->balance, abs($user->credit_limit), 2);

            // 估算续费金额（使用当前证书金额作为参考）
            $estimatedAmount = $cert->amount ?? '0.00';

            if (bccomp($availableBalance, $estimatedAmount, 2) < 0) {
                throw new \Exception("余额不足，可用余额: $availableBalance，预计需要: $estimatedAmount");
            }
        }

        // 构建参数
        $params = [
            'order_id' => $order->id,
            'action' => $action,
            'channel' => 'auto',
            'csr_generate' => 1,
            'domains' => $cert->alternative_names,
            'validation_method' => $cert->dcv['method'] ?? 'txt',
        ];

        // 执行续费或重签
        $actionService = new Action($user->id);

        try {
            if ($action === 'renew') {
                $actionService->renew($params);
            } else {
                $actionService->reissue($params);
            }

            $this->info("订单 #{$order->id} {$action} 成功");

            // 自动支付并提交
            $this->autoPayAndCommit($order->id, $user->id);
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();

            // 如果返回新订单ID（续费创建了新订单）
            if (isset($result['data']['order_id'])) {
                $newOrderId = $result['data']['order_id'];
                $this->info("订单 #{$order->id} 续费创建新订单 #{$newOrderId}");
                $this->autoPayAndCommit($newOrderId, $user->id);
            } else {
                throw new \Exception($result['msg'] ?? '操作失败');
            }
        }
    }

    /**
     * 自动支付并提交
     */
    private function autoPayAndCommit(int $orderId, int $userId): void
    {
        $actionService = new Action($userId);

        try {
            // 支付订单
            $actionService->pay($orderId, true);
            $this->info("订单 #{$orderId} 支付并提交成功");
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            $this->warn("订单 #{$orderId} 支付提交: " . ($result['msg'] ?? '未知状态'));
        } catch (Throwable $e) {
            $this->warn("订单 #{$orderId} 支付提交异常: {$e->getMessage()}");
        }
    }

    /**
     * 发送失败通知
     */
    private function sendFailureNotification(Order $order, string $action, string $reason): void
    {
        $user = $order->user;

        if (! $user || ! $user->email) {
            return;
        }

        try {
            $notificationCenter = app(NotificationCenter::class);
            $notificationCenter->dispatch(new NotificationIntent(
                'auto_renew_failed',
                'user',
                $user->id,
                [
                    'order_id' => $order->id,
                    'action' => $action,
                    'reason' => $reason,
                    'email' => $user->email,
                ],
                ['mail']
            ));
        } catch (Throwable $e) {
            $this->error("发送通知失败: {$e->getMessage()}");
        }
    }
}

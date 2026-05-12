<?php

namespace App\Console\Commands;

use App\Exceptions\ApiResponseException;
use App\Models\Order;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\AutoRenewService;
use App\Services\Order\Utils\DomainUtil;
use App\Services\Order\Utils\OrderUtil;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class AutoRenewCommand extends Command
{
    protected $signature = 'schedule:auto-renew';

    // 注意：不处理 ACME 订单。ACME 续签由客户端（certbot）主动发起，服务端不主动续费/重签
    protected $description = '自动续费/重签即将到期的证书';

    /**
     * 自动续签窗口：到期前 14 天开始检测
     *
     * 客户端部署说明：
     * - 主动发起：应在证书到期前 15 天以上发起，避免与本命令重复提交
     * - 被动拉取：可在到期前 14 天之后拉取，确保已完成续签
     */
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
     * - auto_renew = true（订单级或用户级）
     * - 订单未过期且剩余 ≤15 天（走续费）
     * - latestCert.expires_at < now()+14天（证书即将到期）
     * - latestCert.status = 'active'
     * - product.status = 1 且 renew = 1
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
                    ->where('expires_at', '<', now()->addDays(14))
                    // API 订单由下游系统自行处理续费/重签
                    ->where(function ($q) {
                        $q->whereNull('channel')->orWhere('channel', '!=', 'api');
                    });
            })
            // 订单级 auto_renew=true，或订单未设置时回落到用户设置
            ->where(function ($query) {
                $query->where('auto_renew', true)
                    ->orWhere(function ($q) {
                        $q->whereNull('auto_renew')
                            ->whereHas('user', fn ($u) => $this->whereJsonBoolEq($u, 'auto_settings', 'auto_renew', true));
                    });
            })
            // 订单剩余 ≤15 天走续费（active 状态已保证未过期）
            ->where('period_till', '<=', now()->addDays(15))
            ->get();
    }

    /**
     * 获取需要重签的订单
     * 条件：
     * - auto_reissue = true（订单级或用户级）
     * - 订单剩余 >15 天（走重签）
     * - latestCert.expires_at < now()+14天（证书即将到期）
     * - latestCert.status = 'active'
     * - product.reissue = 1（产品禁用仍可重签）
     */
    private function getReissueOrders()
    {
        return Order::with(['user', 'product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product', function ($query) {
                $query->where('reissue', 1);
            })
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '<', now()->addDays(14))
                    // API 订单由下游系统自行处理续费/重签
                    ->where(function ($q) {
                        $q->whereNull('channel')->orWhere('channel', '!=', 'api');
                    });
            })
            // 订单级 auto_reissue=true，或订单未设置时回落到用户设置。
            // auto_reissue 用户默认值是 true（normalizeAutoSettings），
            // auto_settings 为 null 或不含 auto_reissue 键时视为 true
            ->where(function ($query) {
                $query->where('auto_reissue', true)
                    ->orWhere(function ($q) {
                        $q->whereNull('auto_reissue')
                            ->whereHas('user', function ($u) {
                                $u->whereNull('auto_settings')
                                    ->orWhere(fn ($q2) => $this->whereJsonBoolEq($q2, 'auto_settings', 'auto_reissue', true))
                                    ->orWhere(fn ($q2) => $this->whereJsonKeyMissing($q2, 'auto_settings', 'auto_reissue'));
                            });
                    });
            })
            // 订单剩余时间超过15天，走重签
            ->whereRaw('DATEDIFF(period_till, NOW()) > 15')
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
     * 创建续费/重签 → 支付 → 派发延时 commit 任务（分散提交压力）
     */
    private function processOrder(Order $order, string $action): void
    {
        $user = $order->user;
        $cert = $order->latestCert;
        $product = $order->product;

        $this->info("处理订单 #{$order->id} ($action): $cert->common_name");

        // 域名包含 IP 地址时跳过（IP 证书不支持委托验证，无法自动续签）
        $domains = explode(',', $cert->alternative_names);
        foreach ($domains as $domain) {
            $type = DomainUtil::getType(trim($domain));
            if ($type === 'ipv4' || $type === 'ipv6') {
                $this->warn("订单 #{$order->id} 跳过：域名包含 IP 地址");

                return;
            }
        }

        // 检查委托有效性，无有效委托则跳过
        $ca = strtolower($product->ca ?? '');
        if (! $this->checkDelegationValidity($user->id, $cert->alternative_names, $ca)) {
            $this->warn("订单 #{$order->id} 跳过：无有效委托记录");

            return;
        }

        // 续费需要检查余额（使用当前产品价格实时计算）
        if ($action === 'renew') {
            $availableBalance = bcadd($user->balance, (string) abs((float) $user->credit_limit), 2);

            $estimatedAmount = OrderUtil::getLatestCertAmount(
                ['user_id' => $user->id, 'product_id' => $product->id, 'period' => $order->period,
                    'purchased_standard_count' => 0, 'purchased_wildcard_count' => 0],
                ['standard_count' => $cert->standard_count, 'wildcard_count' => $cert->wildcard_count, 'action' => 'renew'],
                $product->toArray()
            );

            if (bccomp($availableBalance, $estimatedAmount, 2) < 0) {
                throw new \Exception("余额不足，可用余额: {$availableBalance}，预计需要: $estimatedAmount");
            }
        }

        // 从原订单提取参数
        $params = [
            'order_id' => $order->id,
            'action' => $action,
            'channel' => 'auto',
            'domains' => $cert->alternative_names,
            'validation_method' => 'delegation',
            'period' => $order->period,
            'contact' => $order->contact,
        ];

        // CSR：产品支持重用则重用（含私钥），否则自动生成
        if ($product->reuse_csr ?? false) {
            $params['csr'] = $cert->csr;
            if ($cert->private_key) {
                $params['private_key'] = $cert->private_key;
            }
        } else {
            $params['csr_generate'] = 1;
        }

        // OV/EV 需要组织信息
        if ($order->organization) {
            $params['organization'] = $order->organization;
        }

        $actionService = app(Action::class);
        $targetOrderId = null;

        // 1. 创建续费/重签
        try {
            if ($action === 'renew') {
                $actionService->renew($params);
            } else {
                $actionService->reissue($params);
            }
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            if (! isset($result['data']['order_id'])) {
                throw new \Exception($result['msg'] ?? '操作失败');
            }
            $targetOrderId = $result['data']['order_id'];
        }

        if ($targetOrderId != $order->id) {
            $this->info("订单 #{$order->id} 续费创建新订单 #{$targetOrderId}");
        }

        // 2. 支付（不自动提交，转为 pending 状态）
        try {
            $actionService->pay($targetOrderId, false);
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            if (($result['code'] ?? 0) !== 1) {
                throw new \Exception('支付失败: '.($result['msg'] ?? '未知错误'));
            }
        }

        // 3. 创建延时提交任务（随机分布在0~8小时内，8点后人工可检查状态）
        $delay = random_int(0, 28800);
        $actionService->createTask($targetOrderId, 'commit', $delay);

        $scheduledAt = now()->addSeconds($delay)->format('m-d H:i');
        $this->info("订单 #{$targetOrderId} 已支付，计划于 $scheduledAt 提交");
    }

    /**
     * 发送失败通知
     */
    private function sendFailureNotification(Order $order, string $action, string $reason): void
    {
        $user = $order->user;

        if (! $user->email) {
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

    /**
     * 检查所有域名是否都有有效委托记录（即时验证）
     */
    private function checkDelegationValidity(int $userId, string $domains, string $ca): bool
    {
        return app(AutoRenewService::class)->checkDelegationValidity($userId, $domains, $ca);
    }

    /**
     * MySQL 兼容的 JSON 路径布尔比较（auto_settings 列存的是 text + array cast）。
     *
     * 用 JSON_UNQUOTE(JSON_EXTRACT(...)) = 'true' / 'false' 字符串比较，
     * 与 model auto_settings cast 'array' 序列化后的 JSON 表示匹配。
     */
    private function whereJsonBoolEq(Builder $query, string $column, string $key, bool $value): Builder
    {
        $jsonPath = '$.'.json_encode($key);
        $expected = $value ? 'true' : 'false';

        return $query->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT($column, ?)) = ?",
            [$jsonPath, $expected]
        );
    }

    /**
     * MySQL 兼容的 JSON 路径不存在（key missing 或 value 是 JSON null）。
     */
    private function whereJsonKeyMissing(Builder $query, string $column, string $key): Builder
    {
        $jsonPath = '$.'.json_encode($key);

        return $query->whereRaw(
            "JSON_EXTRACT($column, ?) IS NULL OR JSON_TYPE(JSON_EXTRACT($column, ?)) = 'NULL'",
            [$jsonPath, $jsonPath]
        );
    }
}

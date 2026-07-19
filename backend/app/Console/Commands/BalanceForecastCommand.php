<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\QueriesUserJsonSettings;
use App\Models\Order;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\AutoRenewService;
use App\Services\Order\Utils\OrderUtil;
use Illuminate\Console\Command;
use Throwable;

/**
 * 余额前瞻预警（A1，P1-7①）。
 *
 * 只读聚合每个用户「未来 30 天证书到期且已进入付费续费轨道」的估价上限，与可用额
 * （balance + |credit_limit|）比较，不足则每用户一封 balance_forecast 预警。weekly 调度自然去重。
 *
 * 估价口径（诚实披露）：
 * - 续费/重签分界在代码里是订单剩余时长（period_till 距今 ≤15 天走续费、>15 天走免费重签），
 *   故 DB 粗筛 period_till<=now+15 + PHP 层以 willAutoRenewExecute 权威判定（单一源，天然含
 *   A3 的 isSSL 与 15 天分界），排除免费重签单，消除「把免费重签计成付费续费」的系统性高估。
 * - 残余高估（委托将失败等运行日会被跳过不扣费的单仍计入）以「预估上限」文案披露、接受——
 *   forecast 是 selection 口径、与选单一致，不做 weekly 批量 DNS 预检。
 * - 真正的安全网是每日 AutoRenewCommand 逐单余额预检 + A2 独立键失败通知；前瞻是提前量增强件。
 */
class BalanceForecastCommand extends Command
{
    use QueriesUserJsonSettings;

    protected $signature = 'schedule:balance-forecast';

    protected $description = '未来 30 天自动续费余额前瞻预警（预估上限）';

    /** 证书到期观察窗（天）。主控已裁：类常量，不做 config。 */
    private const FORECAST_DAYS = 30;

    /** 付费续费分界粗筛（天），同 AutoRenewCommand::getRenewOrders 的 period_till<=now+15。 */
    private const RENEW_BOUNDARY_DAYS = 15;

    public function handle(): void
    {
        $this->info('开始余额前瞻扫描...');

        $autoRenewService = app(AutoRenewService::class);

        $candidates = Order::with(['user', 'product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product', function ($query) {
                $query->where('status', 1)->where('renew', 1)
                    // A3 同款 ssl 白名单（product_type NULL 视为 ssl）
                    ->where(fn ($p) => $p->whereNull('product_type')->orWhere('product_type', 'ssl'));
            })
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '<', now()->addDays(self::FORECAST_DAYS))
                    // 过期防御（与 getRenewOrders 同构）：已过期证书不会被自动续费，不计入预估
                    ->where('expires_at', '>=', now())
                    // API 订单由下游续费，不计入
                    ->where(function ($q) {
                        $q->whereNull('channel')->orWhere('channel', '!=', 'api');
                    });
            })
            // 订单级 auto_renew=true，或订单未设置时回落到用户设置（与 getRenewOrders 同构）
            ->where(function ($query) {
                $query->where('auto_renew', true)
                    ->orWhere(function ($q) {
                        $q->whereNull('auto_renew')
                            ->whereHas('user', fn ($u) => $this->whereJsonBoolEq($u, 'auto_settings', 'auto_renew', true));
                    });
            })
            // 付费续费分界粗筛：period_till 距今 >15 天当前走免费重签，不计入估价
            ->where('period_till', '<=', now()->addDays(self::RENEW_BOUNDARY_DAYS))
            ->get()
            // 权威判定单一源：复用 willAutoRenewExecute（auto 回落 + product status/renew + isSSL + 15 天分界）
            ->filter(fn (Order $order) => $autoRenewService->willAutoRenewExecute($order, $order->user));

        $notificationCenter = app(NotificationCenter::class);

        foreach ($candidates->groupBy('user_id') as $userOrders) {
            $user = $userOrders->first()->user;
            if (! $user->email) {
                continue;
            }

            $required = '0.00';
            $certificates = [];
            foreach ($userOrders as $order) {
                $cert = $order->latestCert;
                $amount = OrderUtil::getLatestCertAmount(
                    ['user_id' => $user->id, 'product_id' => $order->product_id, 'period' => $order->period,
                        'purchased_standard_count' => 0, 'purchased_wildcard_count' => 0],
                    ['standard_count' => $cert->standard_count, 'wildcard_count' => $cert->wildcard_count, 'action' => 'renew'],
                    $order->product->toArray()
                );
                $required = bcadd($required, $amount, 2);
                $certificates[] = [
                    'common_name' => $cert->common_name,
                    'expires_at' => $cert->expires_at?->format('Y-m-d'),
                    'amount' => $amount,
                ];
            }

            // 可用额 = balance + |credit_limit|，与 AutoRenewCommand/Deploy 余额预检共用 User::availableBalance()
            $available = $user->availableBalance();
            if (bccomp($available, $required, 2) >= 0) {
                continue;
            }

            // 单用户 dispatch 包 try/catch：单用户失败不中断整批
            try {
                $notificationCenter->dispatch(new NotificationIntent(
                    'balance_forecast',
                    'user',
                    $user->id,
                    [
                        'available' => $available,
                        'required' => $required,
                        'shortfall' => bcsub($required, $available, 2),
                        'certificates' => $certificates,
                        // site_url 由 BalanceForecastNotificationBuilder 从系统设置注入
                        'email' => $user->email,
                    ]
                ));
                $this->info("用户 #{$user->id} 余额前瞻预警：可用 {$available}，预计最多需 {$required}");
            } catch (Throwable $e) {
                $this->error("用户 #{$user->id} 余额前瞻通知失败: {$e->getMessage()}");
            }
        }

        $this->info('余额前瞻扫描完成');
    }
}

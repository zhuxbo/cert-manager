<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ExpireNotifyWindow;
use App\Console\Commands\Concerns\QueriesUserJsonSettings;
use App\Exceptions\ApiResponseException;
use App\Models\Order;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Action;
use App\Services\Order\AutoDeployReportService;
use App\Services\Order\AutoRenewService;
use App\Services\Order\Utils\DomainUtil;
use App\Services\Order\Utils\OrderUtil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutoRenewCommand extends Command
{
    use ExpireNotifyWindow;
    use QueriesUserJsonSettings;

    protected $signature = 'schedule:auto-renew';

    // 注意：不处理 ACME 订单。ACME 续签由客户端（certbot）主动发起，服务端不主动续费/重签
    protected $description = '自动续费/重签即将到期的证书';

    /**
     * 用户端失败兜底文案：域名含 IP、上游/系统类错误等非用户可控失败统一归一，
     * 不暴露原始异常细节；仍发通知以堵 ExpireCommand 排除自动续签订单后的静默过期洞。
     * 用户可行动的失败（余额不足、委托无效）各自给专属清晰文案，不走此兜底。
     */
    private const FALLBACK_REASON = '自动续签未成功，请尽快手动续期';

    /**
     * 余额不足失败通知的 per-user 去重间隔（天）。
     *
     * 余额不足是账户级持续态，逐单每日发会风暴。改独立去重键（脱离 14/7/3/1 节点 gate）：
     * per-user 每 N 天一封 + 到期前 ≤1 天窗口豁免必发，兼顾减频与「兜底必发」下界。
     */
    private const BALANCE_NOTIFY_INTERVAL_DAYS = 3;

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
                $query->where('status', 1)->where('renew', 1)
                    // 仅 ssl 产品（product_type NULL 视为 ssl）；非 ssl 退出续费选单
                    ->where(fn ($p) => $p->whereNull('product_type')->orWhere('product_type', 'ssl'));
            })
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '<', now()->addDays(14))
                    // 过期防御（与客户端过期静默对齐、堵 00:00 auto-renew 早于 09:00 ExpireCommand 翻转的时序缝）：
                    // 证书已过期（expires_at < now）不再自动续费/重签，交 ExpireCommand 翻 expired 后由人工处理
                    ->where('expires_at', '>=', now())
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
                $query->where('reissue', 1)
                    // 仅 ssl 产品（product_type NULL 视为 ssl）；非 ssl 退出重签选单
                    ->where(fn ($p) => $p->whereNull('product_type')->orWhere('product_type', 'ssl'));
            })
            ->whereHas('latestCert', function ($query) {
                $query->where('status', 'active')
                    ->where('expires_at', '<', now()->addDays(14))
                    // 过期防御（与客户端过期静默对齐、堵 00:00 auto-renew 早于 09:00 ExpireCommand 翻转的时序缝）：
                    // 证书已过期（expires_at < now）不再自动续费/重签，交 ExpireCommand 翻 expired 后由人工处理
                    ->where('expires_at', '>=', now())
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

                // 同订单重签成功：失败在案时服务端自写恢复行 + 清去重键（无客户端回调的 web 订单
                // 唯一恢复路径，否则最后一条报告永远停在 failure、被持续误提醒）。renew 成功建新单、
                // 旧订单证书翻 renewed 终态，提醒天然停止，无需恢复行。
                if ($action === 'reissue') {
                    app(AutoDeployReportService::class)->recordServerRecovery(
                        $order,
                        '自动重签成功：前次失败已恢复'
                    );
                }
            } catch (Throwable $e) {
                // 原始异常进 cron 日志供运维排查；用户端归一为兜底文案，不泄露系统细节
                $this->error("订单 #{$order->id} {$action} 失败: {$e->getMessage()}");
                $this->sendFailureNotification($order, $action, self::FALLBACK_REASON);

                // pull scheduler 自动重签/续费失败：服务端自写 status=failure，交小时聚合告警
                // （客户端零参与）。message 归一（不泄露原始异常）、以「自动{续费|重签}失败：」开头，与客户端
                // 部署失败天然可辨。跳过类（IP/委托/缺价/余额）不进本 catch，保持既有仅用户兜底通知语义不变。
                app(AutoDeployReportService::class)->recordServerFailure(
                    $order,
                    ($action === 'renew' ? '自动续费失败：' : '自动重签失败：').self::FALLBACK_REASON
                );
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
        // 仍发失败通知（节点 gate），与 ExpireCommand 去重对齐：会被本命令处理的订单一律由本命令提醒
        $domains = explode(',', $cert->alternative_names);
        foreach ($domains as $domain) {
            $type = DomainUtil::getType(trim($domain));
            if ($type === 'ipv4' || $type === 'ipv6') {
                $this->warn("订单 #{$order->id} 跳过：域名包含 IP 地址");
                $this->sendFailureNotification($order, $action, self::FALLBACK_REASON);

                return;
            }
        }

        // 检查委托有效性，无有效委托则跳过（同样发失败通知，纳入节点 gate）
        $ca = strtolower($product->ca ?? '');
        if (! $this->checkDelegationValidity(
            $user->id,
            $cert->alternative_names,
            $ca,
            is_array($cert->validation) ? $cert->validation : [],
        )) {
            $this->warn("订单 #{$order->id} 跳过：无有效委托记录");
            $this->sendFailureNotification($order, $action, '部分域名 CNAME 委托未配置或验证未通过，已跳过');

            return;
        }

        // 续费需要检查余额（重签不扣费、不检查）。余额不足是用户可行动失败 → 跳过并发清晰文案
        if ($action === 'renew') {
            // A4 零价成单守卫：缺价格行则跳过（前置于 renew()/旧证书终态化之前，避免 getMinPrice
            // `?? '0'` 传导出 0 元静默续费）。行存在即放行——显式免费产品（price=0.00 行）合法续费。
            // reissue 基础价本就置 0（OrderUtil），不校验。
            $dedupeKey = "missing_price:{$product->id}:{$order->period}";
            if (! OrderUtil::hasPriceConfigured($user->id, $product->id, $order->period)) {
                // 真实缺价参数进 cron 日志供运维排障
                $this->error("订单 #{$order->id} 跳过：产品价格未配置（product={$product->id} period={$order->period} level={$user->level_code}）");
                // admin 告警复用包0 SystemAlert：固定指纹 'missing' 防 details 中 level/order 波动 churn 击穿去重；
                // TTL 72h ≥ 3× 巡检周期（本命令每日跑），持续缺价至多每 72h 一封，补价后经 clearDedupe 复位
                app(SystemAlert::class)->send(
                    'missing_price',
                    '产品价格未配置（自动续费已跳过）',
                    "product_id={$product->id} period={$order->period} 无任何价格行，该组合自动续费已跳过，请补配价格",
                    [
                        'product_id' => $product->id,
                        'period' => $order->period,
                        'level_code' => $user->level_code,
                        'sample_order_id' => $order->id,
                    ],
                    $dedupeKey,
                    72,
                    'missing'
                );
                // 用户端走兜底文案（缺价非用户可行动，不暴露内部）+ 既有节点 gate
                $this->sendFailureNotification($order, $action, self::FALLBACK_REASON);

                return;
            }
            // healthy：价格已配置（含运营补价后首次通过）→ 清去重键，再次缺价立即告警
            app(SystemAlert::class)->clearDedupe($dedupeKey);

            // O2：余额预检前刷新用户余额（消 00:00 预载 stale balance）。getRenewOrders 一次性 with('user')
            // 预载，同 user_id 多订单共享同一 User 实例且 balance 停留在查询时刻值；前序单 charge 改的是
            // DB 另取的 user 行，内存实例不更新 → 不 refresh 则后续同用户单读旧值必误放行（07-07 断言 1）。
            $user->refresh();

            $availableBalance = $user->availableBalance();

            $estimatedAmount = OrderUtil::getLatestCertAmount(
                ['user_id' => $user->id, 'product_id' => $product->id, 'period' => $order->period,
                    'purchased_standard_count' => 0, 'purchased_wildcard_count' => 0],
                ['standard_count' => $cert->standard_count, 'wildcard_count' => $cert->wildcard_count, 'action' => 'renew'],
                $product->toArray()
            );

            if (bccomp($availableBalance, $estimatedAmount, 2) < 0) {
                // 内部估价数字仅进 cron 日志，用户端只给可行动文案
                $this->warn("订单 #{$order->id} 跳过：余额不足（可用 {$availableBalance}，需 {$estimatedAmount}）");
                // 余额不足脱离节点 gate，走独立去重（per-user 每 N 天 + final-window 豁免必发）
                $this->sendBalanceFailureNotification($order, $action);

                return;
            }

            // 余额充足：清除欠费去重键，恢复后再欠费立即告警（不等 TTL），闭合「充值→又欠费」序列
            Cache::forget("auto_renew_balance_notified:{$user->id}");
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

        // O1：把「创建续费/重签 + 支付(不提交)」两步包进单个外层事务，保证原子性——pay 段 charge 失败时，
        // renew 已翻转的旧证书（active→renewed）+ 新订单/证书一并回滚，杜绝「旧证书 renewed 终态 + 新单卡
        // unpaid」的静默孤儿（P0-1 路径 1）。延时 commit 任务留事务外（= V2「commit 移出事务」等价）。
        //
        // 【attempts=1 是必需约束、非仅从简】renew()/reissue() 入口的 checkDuplicate 是 Cache::add(SETNX,
        // 10s TTL) 且回滚不清缓存；若事务级重试（attempts>1），重入 renew()→checkDuplicate 命中自己首轮
        // 残留键 → error('参数重复...') → 重试必自败。故必须用 DB::transaction 默认 attempts=1，勿改大。
        // 死锁 → 回滚 → 下方 processOrders catch 兜底通知 → 次日自愈（与 V2 一条龙对齐）。
        //
        // 【闭包内零上游 HTTP】new()/reissue() 全本地 SQL、charge() 纯本地扣费；含上游的 commit() 由事务外
        // createTask 的延时任务异步执行。CSR 生成（initParams 内本地 openssl fork，无上游）落在事务内但先于
        // 任何行锁（首个写 Order::create 在其后），不违反「锁内不做慢操作」红线，AutoRenew 串行低并发可接受。
        $targetOrderId = DB::transaction(function () use ($actionService, $action, $params) {
            $newOrderId = null;

            // 段1：创建续费/重签。success() 抛 ApiResponseException(带 data.order_id) 是成功信号——吞掉取 id；
            // 业务失败（无 order_id）rethrow \Exception 逸出闭包 → 外层回滚（勿把成功路径当失败回滚，07-07 §四.1）。
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
                $newOrderId = $result['data']['order_id'];
            }

            // 段2：支付（不自动提交，转 pending）。code!==1 rethrow 逸出闭包 → 外层回滚（renew + 扣费一起撤销）。
            try {
                $actionService->pay($newOrderId, false);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if (($result['code'] ?? 0) !== 1) {
                    throw new \Exception('支付失败: '.($result['msg'] ?? '未知错误'));
                }
            }

            return $newOrderId;
        });

        if ($targetOrderId != $order->id) {
            $this->info("订单 #{$order->id} 续费创建新订单 #{$targetOrderId}");
        }

        // 3. 创建延时提交任务（事务外；随机分布在0~8小时内，8点后人工可检查状态）
        $delay = random_int(0, 28800);
        $actionService->createTask($targetOrderId, 'commit', $delay);

        $scheduledAt = now()->addSeconds($delay)->format('m-d H:i');
        $this->info("订单 #{$targetOrderId} 已支付，计划于 $scheduledAt 提交");
    }

    /**
     * 发送失败通知
     *
     * 节点 gate：仅当订单当前证书 expires_at 落在到期通知节点窗口（14/7/3/1，与 ExpireCommand 同源）才发，
     * 避免到期前每天重复发送失败邮件。不在节点窗口直接跳过。
     */
    private function sendFailureNotification(Order $order, string $action, string $reason): void
    {
        $user = $order->user;

        if (! $user->email) {
            return;
        }

        // 仅在到期通知节点发送（与 ExpireCommand 节点窗口一致，防每日重复）
        // $order 来自 getRenewOrders/getReissueOrders，已 whereHas('latestCert')，关系非空
        if (! $this->isExpireNotifyNode($order->latestCert->expires_at)) {
            return;
        }

        $this->dispatchAutoRenewFailed($order, $action, $reason);
    }

    /**
     * 余额不足失败通知（独立去重，脱离节点 gate）。
     *
     * 余额不足是账户级持续态、用户可行动，改「per-user 每 N 天一封 + 到期前 ≤1 天窗口豁免必发」：
     * - 常规节奏：Cache::add 原子占位（反模式 20），N 天内同用户至多一封，防多单风暴；
     * - final-window（到期≤1 天）：即便去重键存续也必发（Cache::put 同时刷键抑制同轮其他单叠发），
     *   保住「到期前 1 天必达」下界，堵 ExpireCommand 排除自动续签订单后的静默过期洞。
     */
    private function sendBalanceFailureNotification(Order $order, string $action): void
    {
        $user = $order->user;

        if (! $user->email) {
            return;
        }

        $key = "auto_renew_balance_notified:{$user->id}";
        $ttl = now()->addDays(self::BALANCE_NOTIFY_INTERVAL_DAYS);
        $reason = '账户余额不足，请充值后手动续期';

        // 到期前最后窗口豁免去重必发（node-1 语义 [now, now+1]），刷键防同轮其他单叠发
        if ($this->isFinalExpireNotifyNode($order->latestCert->expires_at)) {
            Cache::put($key, true, $ttl);
            $this->dispatchAutoRenewFailed($order, $action, $reason);

            return;
        }

        // 常规节奏：per-user 每 N 天一封（Cache::add 原子占位，抢不到即近期已发过）
        if (! Cache::add($key, true, $ttl)) {
            return;
        }

        $this->dispatchAutoRenewFailed($order, $action, $reason);
    }

    /**
     * 派发 auto_renew_failed 通知（节点 gate 路径与余额独立去重路径共用同一 dispatch，仅闸门不同）。
     *
     * 调用方须已确认 $user->email 非空。
     */
    private function dispatchAutoRenewFailed(Order $order, string $action, string $reason): void
    {
        $user = $order->user;

        try {
            $notificationCenter = app(NotificationCenter::class);
            $notificationCenter->dispatch(new NotificationIntent(
                'auto_renew_failed',
                'user',
                $user->id,
                [
                    // 用证书 common_name 标识（用户认得域名），订单 id 仅留 cron 日志给运维
                    'common_name' => $order->latestCert->common_name,
                    'action' => $action,
                    'reason' => $reason,
                    // site_url 由 AutoRenewFailedNotificationBuilder 从系统设置注入，此处不传
                    'email' => $user->email,
                ]
            ));
        } catch (Throwable $e) {
            $this->error("发送通知失败: {$e->getMessage()}");
        }
    }

    /**
     * 检查所有域名是否都有有效委托记录（即时验证）
     */
    private function checkDelegationValidity(
        int $userId,
        string $domains,
        string $ca,
        array $sourceValidation,
    ): bool {
        return app(AutoRenewService::class)->checkDelegationValidity(
            $userId,
            $domains,
            $ca,
            $sourceValidation,
        );
    }
}

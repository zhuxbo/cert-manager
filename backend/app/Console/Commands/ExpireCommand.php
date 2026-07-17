<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ExpireNotifyWindow;
use App\Models\Acme;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\TemplateSelector;
use App\Services\Order\AutoRenewService;
use App\Services\Order\StalledRenewalQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireCommand extends Command
{
    use ExpireNotifyWindow;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark as expired and send an expiration notification';

    /**
     * Execute the console command.
     *
     * 通知时间点：第 14/7/3/1 天当天
     *
     * 客户端部署说明：
     * - 主动发起：应在证书到期前 15 天以上发起重签或续费
     * - 被动拉取：可在到期前 14 天之后拉取新证书
     */
    public function handle(): void
    {
        // 更改所有到期证书的状态（证书到期）
        Cert::where('status', 'active')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        // 订单到期时，标记 processing/approving/active 的证书为到期（证书有到期时间时也需同时到期）
        Cert::whereIn('status', ['processing', 'approving', 'active'])
            ->whereHas('order', fn ($q) => $q->where('period_till', '<', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<', now()))
            ->update(['status' => 'expired']);

        // ACME 订阅到期：period_till 已过的 active 订阅置 expired（纯本地簿记）。
        // 安全性：不 revoke、不调上游、不动 eab_kid/eab_hmac（certbot 直连 CA directory，不经本系统），
        // 与状态图 active──[到期]──→expired 一致；本地 expired 后 sync 终态守卫只挡 status、
        // period_till 仍被上游覆盖（Acme\Action 既有性质，见 skills/backend/acme-module.md）。
        Acme::where('status', Acme::STATUS_ACTIVE)
            ->where('period_till', '<', now())
            ->update(['status' => Acme::STATUS_EXPIRED]);

        // 到期通知时间窗口查询条件（第 14/7/3/1 天当天，节点来自 ExpireNotifyWindow 单一源）
        $windows = $this->expireNotifyWindows();
        $expireWindowQuery = function ($query) use ($windows) {
            $query->where('status', 'active')
                ->where(function ($query) use ($windows) {
                    foreach ($windows as $i => [$start, $end]) {
                        $i === 0
                            ? $query->whereBetween('expires_at', [$start, $end])
                            : $query->orWhereBetween('expires_at', [$start, $end]);
                    }
                })
                ->orderBy('expires_at');
        };

        // 取出窗口内订单（预加载防 N+1），PHP filter 排除"会被自动续签/重签妥善处理"的订单 —
        // 这些订单交由 AutoRenewCommand 提醒（失败时发 auto_renew_failed），避免用户收到两封冗余邮件。
        // 排除条件须与 AutoRenewCommand 实际处理范围精确对齐（铁律：少排除安全、多排除漏发）：
        //   - API channel 订单：AutoRenewCommand 不处理（下游控制），故 ExpireCommand 不排除（照常发 cert_expire）
        //   - 其余 willAutoRenewExecute||willAutoReissueExecute 为真的订单：AutoRenewCommand 会处理并在失败时发通知，排除
        // whereHas('user'|'product') 与 AutoRenewCommand::getRenewOrders/getReissueOrders 的过滤范围对齐，
        // 同时保证 willBeHandledByAutoRenew 内三关系非空（user 缺失本就发不出邮件、product 缺失会被汇总邮件 builder 的 whereHas('product') 二次过滤）。
        $autoRenewService = app(AutoRenewService::class);
        // auto_renew_failed 模板是否启用（循环外算一次布尔，避免逐单查询）：停用时 AutoRenewCommand
        // 发不出失败通知，若 ExpireCommand 仍排除自动订单则两头空 → 静默过期。故排除以模板启用为前置。
        $autoRenewFailedEnabled = app(TemplateSelector::class)->select('auto_renew_failed') !== null;
        $orders = Order::with(['latestCert', 'user', 'product'])
            ->whereHas('latestCert', $expireWindowQuery)
            ->whereHas('user')
            ->whereHas('product')
            ->get();

        $user_ids = $orders
            ->reject(fn (Order $order) => $autoRenewService->willBeHandledByAutoRenew($order, $order->user, $autoRenewFailedEnabled))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        $this->info(get_system_setting('site', 'name', 'SSL证书管理系统'));
        $notificationCenter = app(NotificationCenter::class);

        $this->dispatchExpiryNotifications(
            $notificationCenter,
            $user_ids,
            'cert_expire',
            'certificate expiration notification task created'
        );

        // 续期停滞孤儿提醒（cert_renew_stalled）：续费/重签把前驱证书终态化（renewed/reissued）后，接替
        // 证书长期卡在非 active 停滞态（unpaid/pending/processing/approving/failed），前驱即将到期。此类前驱
        // 不在上面 active 到期查询内（cert_expire 对 renewed/reissued 抑制），且 AutoRenewCommand 因 active
        // 前置不再处理该单 → X 是唯一止血。检测经 StalledRenewalQuery 单一形态（前驱轴 + EXISTS 接替 5 态 +
        // 48h 在途门槛 + 节点窗口），markRenewed 手工标记单无接替、结构性免疫。收件人经前驱 order->user 解析，
        // Builder 侧重查共用同一形态（forUser 超集窗口，防异步延迟跨窗漏发）。additive 分支，既有 active 到期
        // 查询一字不改（零回归）。
        $stalledUserIds = app(StalledRenewalQuery::class)->forDispatch()
            ->with('order:id,user_id')
            ->get()
            ->pluck('order.user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->dispatchExpiryNotifications(
            $notificationCenter,
            $stalledUserIds,
            'cert_renew_stalled',
            'certificate renewal stalled notification task created'
        );

        // ACME 订阅到期通知（节点 14/7/3/1，与 cert_expire 派发口径对齐）：查窗口内 active 订阅，
        // 按 user 去重逐 user 派发 acme_expire。无 willAuto* 去重（ACME 不由 AutoRenewCommand 处理，
        // 无双发风险）；Builder 侧用连续 14 天超集窗口（防异步延迟跨窗漏发，见 AcmeExpireNotificationBuilder）。
        $acmeUserIds = Acme::where('status', Acme::STATUS_ACTIVE)
            ->where(function ($query) use ($windows) {
                foreach ($windows as $i => [$start, $end]) {
                    $i === 0
                        ? $query->whereBetween('period_till', [$start, $end])
                        : $query->orWhereBetween('period_till', [$start, $end]);
                }
            })
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        $this->dispatchExpiryNotifications(
            $notificationCenter,
            $acmeUserIds,
            'acme_expire',
            'ACME subscription expiration notification task created'
        );

        // 清理终态证书的敏感材料：已到期/吊销/取消/被续期重签/失败的 CSR、私钥、证书串
        // 业务已无保留价值，提前清理可缩小备份脱敏成本与泄露面
        $this->purgeTerminalCertMaterial();
    }

    /**
     * 批量派发到期类通知：一次 whereIn 加载用户消 N+1，收件人闸门（email 判空）单点。
     *
     * cert_expire / cert_renew_stalled / acme_expire 三类共用；通知 code 与日志描述由参数吸收。
     * $userIds 已在各查询侧去重（unique）；缺行用户（已删）不在结果内 → 跳过，等价原 User::find 返 null。
     * 派发顺序无关（各 dispatch 独立），故用 DB 返回顺序不改变行为。
     */
    private function dispatchExpiryNotifications(
        NotificationCenter $notificationCenter,
        array $userIds,
        string $code,
        string $logDescription
    ): void {
        if (empty($userIds)) {
            return;
        }

        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (! $user->email) {
                continue;
            }

            $notificationCenter->dispatch(new NotificationIntent(
                $code,
                'user',
                $user->id,
                [
                    'email' => $user->email,
                ]
            ));
            $this->info("User $user->id email $user->email $logDescription");
        }
    }

    /**
     * 对终态证书清空 csr/private_key/cert 三列；已清过的行由第二个条件过滤掉，重跑零开销。
     */
    private function purgeTerminalCertMaterial(): void
    {
        $terminalStatuses = ['expired', 'cancelled', 'revoked', 'renewed', 'reissued', 'failed'];
        $totalCleared = 0;

        do {
            $affected = DB::table('certs')
                ->whereIn('status', $terminalStatuses)
                ->where(function ($q) {
                    $q->whereNotNull('csr')
                        ->orWhereNotNull('private_key')
                        ->orWhereNotNull('cert');
                })
                ->limit(1000)
                ->update([
                    'csr' => null,
                    'private_key' => null,
                    'cert' => null,
                ]);

            $totalCleared += $affected;
        } while ($affected > 0);

        if ($totalCleared > 0) {
            $this->info("Cleared csr/private_key/cert on $totalCleared terminal certs");
        }
    }
}

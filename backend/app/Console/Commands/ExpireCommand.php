<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ExpireNotifyWindow;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\AutoRenewService;
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
        $autoRenewService = app(AutoRenewService::class);
        $orders = Order::with(['latestCert', 'user', 'product'])
            ->whereHas('latestCert', $expireWindowQuery)
            ->get();

        $user_ids = $orders
            ->reject(fn (Order $order) => $this->willBeHandledByAutoRenew($order, $autoRenewService))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        $this->info(get_system_setting('site', 'name', 'SSL证书管理系统'));
        $notificationCenter = app(NotificationCenter::class);

        foreach ($user_ids as $user_id) {
            $user = User::find($user_id);
            if ($user && $user->email) {
                $notificationCenter->dispatch(new NotificationIntent(
                    'cert_expire',
                    'user',
                    $user->id,
                    [
                        'email' => $user->email,
                    ]
                ));
                $this->info("User $user->id email $user->email certificate expiration notification task created");
            }
        }

        // 清理终态证书的敏感材料：已到期/吊销/取消/被续期重签/失败的 CSR、私钥、证书串
        // 业务已无保留价值，提前清理可缩小备份脱敏成本与泄露面
        $this->purgeTerminalCertMaterial();
    }

    /**
     * 判断订单是否会被 AutoRenewCommand 妥善处理（成功续签/重签 或 失败时发 auto_renew_failed）。
     *
     * 为真则 ExpireCommand 不发 cert_expire（交给 AutoRenewCommand 提醒，去重）。
     * 与 AutoRenewCommand::getRenewOrders/getReissueOrders 处理范围对齐：
     *   - 关系缺失（无 cert/user/product）：返回 false（不排除，安全兜底）
     *   - API channel：AutoRenewCommand 跳过，返回 false（不排除，照常发 cert_expire，避免两头空）
     *   - 其余 willAutoRenewExecute||willAutoReissueExecute：返回其结果
     */
    private function willBeHandledByAutoRenew(Order $order, AutoRenewService $autoRenewService): bool
    {
        $cert = $order->latestCert;
        $user = $order->user;

        if ($cert === null || $user === null || $order->product === null) {
            return false;
        }

        // API channel 订单由下游系统自行续费/重签，AutoRenewCommand 不处理（getRenewOrders/getReissueOrders 已 channel != api 过滤）
        if ($cert->channel === 'api') {
            return false;
        }

        return $autoRenewService->willAutoRenewExecute($order, $user)
            || $autoRenewService->willAutoReissueExecute($order, $user);
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

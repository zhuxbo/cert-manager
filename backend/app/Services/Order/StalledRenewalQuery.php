<?php

namespace App\Services\Order;

use App\Console\Commands\Concerns\ExpireNotifyWindow;
use App\Models\Cert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 续期停滞孤儿检测查询（单一形态源）。
 *
 * 「续费/重签把前驱证书终态化（renewed/reissued）后，接替证书长期卡在非 active 停滞态」的孤儿
 * 形态条件——前驱状态集 + EXISTS 停滞接替（5 态）+ 48h 在途年龄门槛——集中在此单一私有形态，
 * 供派发侧（ExpireCommand）与重查侧（CertRenewStalledNotificationBuilder）共用，杜绝两处手写
 * 第二份查询漂移（漂移即重蹈「派发了 user、Builder 重查为空 → 静默漏发」事故）。
 *
 * 窗口策略按既有通知架构分两侧（与 CertExpire / AcmeExpire 一致）：
 *   - forDispatch：前驱 expires_at 施加离散节点窗口（14/7/3/1，防每日重复）；
 *   - forUser：前驱 expires_at 施加连续 14 天超集窗口（防 NotificationJob 异步延迟跨窗漏发）。
 * 超集 ⊇ 节点窗口 → 凡派发过的 user，Builder 侧结构性可重查到。
 *
 * 免疫：markRenewed 手工标记单无接替（EXISTS 恒 falsy）、已完成续签接替=active（不在 5 态集）→
 * 均结构性排除，绝不对存量已续签客户群发。
 *
 * 收件人经前驱 C 的 order->user 解析（certs 表无 user_id 列）：续费前驱在旧订单、重签前驱在同订单，
 * 二者 order->user 均正确指向本人。
 */
class StalledRenewalQuery
{
    use ExpireNotifyWindow;

    /**
     * 前驱证书状态轴（封闭）：续费置 renewed、重签置 reissued，全仓无第三写入源。
     *
     * @var string[]
     */
    private const PREDECESSOR_STATUSES = ['renewed', 'reissued'];

    /**
     * 接替证书「停滞」状态集（charter「非 active」的显式工程化，而非字面 != 'active'）：
     *   - unpaid：pay 前失败/中断（未扣费）
     *   - pending：commit 失败/卡单（已扣费）
     *   - processing/approving：DCV/审核长期不过（已扣费——审计 critical 路径 3）
     *   - failed：CA 拒签终态（需重开）
     * 排除 cancelled/revoked（已终止非停滞、误报不可静音）、renewed/reissued（链延长、接替曾签发成功）、
     * expired（接替曾 active 走完生命周期）、cancelling（取消过渡态）——详见计划 X1。
     *
     * @var string[]
     */
    private const SUCCESSOR_STALLED_STATUSES = ['unpaid', 'pending', 'processing', 'approving', 'failed'];

    /**
     * 在途年龄门槛（小时）：接替 created_at 早于此才算停滞。
     *
     * 挡两类误报：①健康在途（续费单当天创建当天 processing 是正常态、手工单跨日完成 DCV）；
     * ②auto_renew_failed 当日重叠。取 48h：盖住手工单 24~48h 内跨日完成 DCV 的健康态，
     * 对主人群（到期前 14 天建单的自动续费孤儿）首封恒落 node-7（age≫48h），零节点代价。详见计划 X1。
     */
    private const IN_FLIGHT_AGE_HOURS = 48;

    /**
     * 单一私有形态：前驱状态集 + EXISTS 停滞接替（5 态 + 48h 门槛）。窗口策略由 forDispatch/forUser 施加。
     *
     * @return Builder<Cert>
     */
    private function baseShape(): Builder
    {
        return Cert::query()
            ->whereIn('status', self::PREDECESSOR_STATUSES)
            ->whereHas('nextCert', function (Builder $query): void {
                $query->whereIn('status', self::SUCCESSOR_STALLED_STATUSES)
                    ->where('created_at', '<', now()->subHours(self::IN_FLIGHT_AGE_HOURS));
            });
    }

    /**
     * 派发侧：前驱 expires_at 施加离散节点窗口 + 收件人可解析（order.user 非空）。
     * 供 ExpireCommand 加载 order 后取 distinct user_id 逐 user 派发 cert_renew_stalled。
     *
     * @return Builder<Cert>
     */
    public function forDispatch(): Builder
    {
        $windows = $this->expireNotifyWindows();

        return $this->baseShape()
            ->where(function (Builder $query) use ($windows): void {
                foreach ($windows as $i => [$start, $end]) {
                    $i === 0
                        ? $query->whereBetween('expires_at', [$start, $end])
                        : $query->orWhereBetween('expires_at', [$start, $end]);
                }
            })
            ->whereHas('order.user')
            ->orderBy('expires_at');
    }

    /**
     * 重查侧：前驱 expires_at 施加连续 14 天超集窗口（逐字镜像 CertExpireNotificationBuilder）+ 按 user 过滤。
     * 供 CertRenewStalledNotificationBuilder 重查该用户的停滞前驱。
     *
     * @return Builder<Cert>
     */
    public function forUser(User $user): Builder
    {
        return $this->baseShape()
            ->whereBetween('expires_at', [now(), now()->addDays(max(self::EXPIRE_NOTIFY_NODES))])
            ->whereHas('order', function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->orderBy('expires_at');
    }
}

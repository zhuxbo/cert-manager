<?php

namespace App\Console\Commands\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * 到期通知时间节点（单一源）
 *
 * AutoRenewCommand（失败通知 gate）与 ExpireCommand（到期通知窗口）共用同一组节点，
 * 避免两处天数漂移导致去重错位。
 *
 * 节点 n 对应窗口 [now()+(n-1)天, now()+n 天]，与 ExpireCommand 历史 whereBetween 语义完全一致：
 * 节点 14 → [now+13, now+14]、7 → [now+6, now+7]、3 → [now+2, now+3]、1 → [now, now+1]。
 */
trait ExpireNotifyWindow
{
    /**
     * 到期通知触发节点（天）：第 14/7/3/1 天。
     *
     * @var int[]
     */
    public const EXPIRE_NOTIFY_NODES = [14, 7, 3, 1];

    /**
     * 判断某到期时间是否落在任一通知节点窗口内。
     *
     * 用于 AutoRenewCommand 失败通知 gate：仅当订单当前证书 expires_at 落在节点窗口才发，
     * 与 ExpireCommand 到期通知节点对齐（避免每天重复发失败通知）。
     */
    protected function isExpireNotifyNode(?CarbonInterface $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        foreach ($this->expireNotifyWindows() as [$start, $end]) {
            if ($expiresAt->betweenIncluded($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断某到期时间是否落在「最后一个」节点窗口内（到期前 ≤1 天）。
     *
     * 窗口由 min(EXPIRE_NOTIFY_NODES) 派生（当前 = 节点 1 → [now, now+1]），与节点单一源绑定、
     * 不引第二份天数常量。A2 余额不足去重用它做「final-window 豁免必发」：即便去重键存续，
     * 到期前最后窗口也必发一封，保住「到期前 1 天必达」下界（skills/backend/auto-renew.md 兜底必发红线）。
     */
    protected function isFinalExpireNotifyNode(?CarbonInterface $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        $finalNode = min(self::EXPIRE_NOTIFY_NODES);

        return $expiresAt->betweenIncluded(now(), now()->addDays($finalNode));
    }

    /**
     * 生成所有节点窗口 [start, end] 列表（每次调用基于当前 now()，与 betweenIncluded / whereBetween 语义一致）。
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected function expireNotifyWindows(): array
    {
        return array_map(
            static fn (int $node): array => [now()->addDays($node - 1), now()->addDays($node)],
            self::EXPIRE_NOTIFY_NODES
        );
    }
}

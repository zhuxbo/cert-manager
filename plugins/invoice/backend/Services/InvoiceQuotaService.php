<?php

namespace Plugins\Invoice\Services;

use Illuminate\Support\Facades\DB;

class InvoiceQuotaService
{
    /**
     * 获取用户可开票额度
     *
     * 可开票额度 = 当年非赠送充值 - 当年已开票（严格自然年）
     */
    public static function getQuota(int $userId): array
    {
        $year = date('Y');
        $yearStart = "$year-01-01 00:00:00";
        $yearEnd = "$year-12-31 23:59:59";

        // 当年非赠送充值（type=addfunds, status=1, pay_method!=gift）
        $recharge = bcadd((string) DB::table('funds')
            ->where('user_id', $userId)
            ->where('type', 'addfunds')
            ->where('status', 1)
            ->where('pay_method', '!=', 'gift')
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->sum('amount'), '0', 2);

        // 当年已开票（status=0 处理中 + status=1 已开票）
        $invoiced = bcadd((string) DB::table('invoices')
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1])
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->sum('amount'), '0', 2);

        // 可开票额度 = 充值 - 已开票，最低为 0
        $quota = bcsub($recharge, $invoiced, 2);
        if (bccomp($quota, '0.00', 2) < 0) {
            $quota = '0.00';
        }

        return [
            'recharge' => $recharge,
            'invoiced' => $invoiced,
            'quota' => $quota,
        ];
    }
}

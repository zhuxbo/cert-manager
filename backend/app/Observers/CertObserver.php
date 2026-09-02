<?php

namespace App\Observers;

use App\Models\Cert;
use App\Models\Order;
use App\Services\UserDashboardCache;
use Illuminate\Support\Facades\DB;

class CertObserver
{
    public function created(Cert $cert): void
    {
        $this->forgetUserDashboardCache($cert);
    }

    public function updated(Cert $cert): void
    {
        if ($cert->wasChanged('amount')) {
            $this->recalculateOrderAmount($cert);
        }

        if ($cert->wasChanged(['status', 'expires_at', 'amount'])) {
            $this->forgetUserDashboardCache($cert);
        }
    }

    public function deleted(Cert $cert): void
    {
        $this->recalculateOrderAmount($cert);
        $this->forgetUserDashboardCache($cert);
    }

    private function forgetUserDashboardCache(Cert $cert): void
    {
        if (! $cert->order_id) {
            return;
        }

        /** @var Order|null $relatedOrder */
        $relatedOrder = $cert->relationLoaded('order') ? $cert->getRelation('order') : null;
        $userId = $relatedOrder ? $relatedOrder->user_id : null;
        $userId ??= Order::whereKey($cert->order_id)->value('user_id');
        if (! $userId) {
            return;
        }

        $forget = static function () use ($userId): void {
            UserDashboardCache::forgetForCertificateChange($userId);
        };

        // 先清理当前缓存；事务提交后再清一次，避免提交窗口内旧数据重新写入缓存。
        $forget();
        DB::afterCommit($forget);
    }

    private function recalculateOrderAmount(Cert $cert): void
    {
        if (! $cert->order_id) {
            return;
        }

        $order = Order::find($cert->order_id);
        if (! $order) {
            return;
        }

        $order->amount = Cert::where('order_id', $cert->order_id)->sum('amount');
        $order->save();
    }
}

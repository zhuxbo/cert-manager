<?php

namespace Plugins\CloudDeploy\Support;

use App\Models\Order;
use Plugins\CloudDeploy\Models\CloudDeployAccess;

class TenantConsistency
{
    /**
     * 校验 access（及可选 order）均属于 $userId。
     * 用 withoutGlobalScopes 直查 user_id 列，不依赖请求层 UserScope，便于 Job 复用。
     */
    public static function check(int $userId, int $accessId, ?int $orderId): bool
    {
        $accessUserId = CloudDeployAccess::withoutGlobalScopes()
            ->whereKey($accessId)
            ->value('user_id');

        if ($accessUserId === null || (int) $accessUserId !== $userId) {
            return false;
        }

        if ($orderId !== null) {
            $orderUserId = Order::withoutGlobalScopes()
                ->whereKey($orderId)
                ->value('user_id');

            if ($orderUserId === null || (int) $orderUserId !== $userId) {
                return false;
            }
        }

        return true;
    }
}

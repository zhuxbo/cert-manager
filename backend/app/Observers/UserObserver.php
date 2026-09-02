<?php

namespace App\Observers;

use App\Models\User;
use App\Services\UserDashboardCache;
use Illuminate\Support\Facades\DB;

class UserObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged('balance')) {
            return;
        }

        $userId = $user->id;
        $forget = static function () use ($userId): void {
            UserDashboardCache::forgetForBalanceChange($userId);
        };

        // 先清理当前缓存；事务提交后再清一次，避免提交窗口内旧数据重新写入缓存。
        $forget();
        DB::afterCommit($forget);
    }
}

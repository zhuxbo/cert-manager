<?php

namespace Plugins\CloudDeploy\Controllers\User;

use App\Http\Controllers\User\BaseController;
use App\Models\Scopes\UserScope;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Plugins\CloudDeploy\Requests\LogIndexRequest;

class LogController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        if ($this->guard->id()) {
            CloudDeployLog::addGlobalScope(new UserScope($this->guard->id()));
        }
    }

    public function index(LogIndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = CloudDeployLog::query();

        // 等值/布尔筛选（is_final 用 array_key_exists 容 false 值；其余非空判定）
        foreach (['order_id', 'target_id', 'status', 'provider', 'product', 'trigger'] as $f) {
            if (isset($validated[$f]) && $validated[$f] !== '') {
                $query->where($f, $validated[$f]);
            }
        }
        if (array_key_exists('is_final', $validated)) {
            $query->where('is_final', (bool) $validated['is_final']);
        }
        if (! empty($validated['keyword'])) {
            $query->where('resource_summary', 'like', "%{$validated['keyword']}%");
        }
        if (! empty($validated['created_at_start'])) {
            $query->where('created_at', '>=', $validated['created_at_start']);
        }
        if (! empty($validated['created_at_end'])) {
            $query->where('created_at', '<=', $validated['created_at_end']);
        }
        if (! empty($validated['quickSearch'])) {
            $kw = $validated['quickSearch'];
            // 订单号(order_id 精确等值——与 admin quickSearch 同口径，order_id 整数列命中索引、非数字关键词隐式转 0 不命中由 orWhere 兜底) /
            // 域名(resource_summary 快照) / 凭证名(access_name 快照)。
            // 用户名维度由 UserScope 自限本人、无需 join users（user 侧）。orWhere 分组包裹保 AND。
            $query->where(function ($w) use ($kw) {
                $w->where('order_id', $kw)
                    ->orWhere('resource_summary', 'like', "%{$kw}%")
                    ->orWhere('access_name', 'like', "%{$kw}%");
            });
        }

        $total = $query->count();
        $items = $query->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)->limit($pageSize)->get();

        $this->success([
            'items' => $items, 'total' => $total,
            'pageSize' => $pageSize, 'currentPage' => $currentPage,
        ]);
    }
}

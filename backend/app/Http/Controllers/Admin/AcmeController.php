<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Acme\GetIdsRequest;
use App\Models\Acme;
use App\Models\Product;
use App\Models\User;
use App\Services\Acme\Action;
use Illuminate\Http\Request;

class AcmeController extends BaseController
{
    protected Action $action;

    public function __construct()
    {
        parent::__construct();
        $this->action = app(Action::class);
    }

    /**
     * ACME 订单列表
     */
    public function index(Request $request): void
    {
        $currentPage = (int) ($request->input('currentPage', 1));
        $pageSize = (int) ($request->input('pageSize', 10));

        $query = Acme::query();

        $this->applyFilters($query, $request, true);

        $total = $query->count();
        $items = $query->with(['user', 'product'])
            ->orderByDesc('id')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 订单详情（含 EAB，makeVisible eab_hmac）
     */
    public function show(int $id): void
    {
        $order = Acme::with(['user', 'product'])->find($id);

        if (! $order) {
            $this->error('订单不存在');
        }

        $data = $order->makeVisible('eab_hmac')->toArray();
        $data['directory_url'] = $this->action->syncDirectoryUrl($order);

        $this->success($data);
    }

    /**
     * 创建 ACME 订单
     */
    public function new(Request $request): void
    {
        $request->validate([
            'user_id' => 'required|integer',
            'product_id' => 'required|integer|exists:products,id',
            'period' => 'required|integer',
            'plus' => 'nullable|integer|in:0,1',
            'contact_email' => 'required|email|max:254',
        ]);

        $this->action->new($request->only(['user_id', 'product_id', 'period', 'plus', 'contact_email', 'remark']) + ['channel' => 'admin']);
    }

    /**
     * 支付订单
     */
    public function pay(int $id): void
    {
        $this->action->pay($id);
    }

    /**
     * 提交订单到上游系统
     */
    public function commit(int $id): void
    {
        $this->action->commit($id);
    }

    /**
     * 同步订单状态
     */
    public function sync(int $id): void
    {
        $this->action->sync($id);
    }

    /**
     * 取消订单
     */
    public function commitCancel(int $id): void
    {
        $this->action->commitCancel($id);
    }

    /**
     * 撤回取消
     */
    public function revokeCancel(int $id): void
    {
        $this->action->revokeCancel($id);
    }

    /**
     * 备注
     */
    public function remark(int $id, Request $request): void
    {
        $this->action->remark($id, $request->string('remark')->trim()->limit(255), 'admin_remark');
    }

    /**
     * 批量支付
     */
    public function batchPay(GetIdsRequest $request): void
    {
        $this->action->batchPay($request->validated('ids'));
    }

    /**
     * 批量提交
     */
    public function batchCommit(GetIdsRequest $request): void
    {
        $this->action->batchCommit($request->validated('ids'));
    }

    /**
     * 批量同步
     */
    public function batchSync(GetIdsRequest $request): void
    {
        $this->action->batchSync($request->validated('ids'));
    }

    /**
     * 批量取消
     */
    public function batchCommitCancel(GetIdsRequest $request): void
    {
        $this->action->batchCommitCancel($request->validated('ids'));
    }

    /**
     * 批量撤回取消
     */
    public function batchRevokeCancel(GetIdsRequest $request): void
    {
        $this->action->batchRevokeCancel($request->validated('ids'));
    }

    /**
     * 批量复制 EAB（Admin 端限制：所选订单必须同属一个用户）
     */
    public function batchCopyEab(GetIdsRequest $request): void
    {
        $acmes = Acme::whereIn('id', $request->validated('ids'))
            ->whereNotNull('eab_kid')
            ->with('product:id,ca')
            ->get();

        if ($acmes->isEmpty()) {
            $this->error('没有可复制的 EAB 信息');
        }

        if ($acmes->pluck('user_id')->unique()->count() > 1) {
            $this->error('仅能复制同一用户的 EAB');
        }

        $dirUrls = [];
        $text = $acmes->map(function ($acme) use (&$dirUrls) {
            $ca = (string) ($acme->product->ca ?? '');
            if (! isset($dirUrls[$ca])) {
                $dirUrls[$ca] = $this->action->syncDirectoryUrl($acme) ?? '';
            }
            $kid = $acme->makeVisible('eab_hmac')->eab_kid;
            $hmac = $acme->makeVisible('eab_hmac')->eab_hmac;

            return "directory_url={$dirUrls[$ca]}\neab_kid=$kid\neab_hmac=$hmac";
        })->implode("\n\n");

        $this->success(['text' => $text, 'count' => $acmes->count()]);
    }

    /**
     * 条件筛选
     *
     * quickSearch: id / eab_kid 左匹配（走索引）/ 用户名 / 产品名 / remark / admin_remark
     * eab_kid: 独立字段，左匹配
     */
    private function applyFilters($query, Request $request, bool $admin): void
    {
        if ($request->filled('quickSearch')) {
            $keyword = (string) $request->input('quickSearch');
            $query->where(function ($q) use ($keyword, $admin) {
                $q->where('id', 'like', "%$keyword%")
                    ->orWhere('eab_kid', 'like', "$keyword%") // 左匹配以使用索引
                    ->orWhere('remark', 'like', "%$keyword%")
                    ->orWhereIn('product_id', Product::where('name', 'like', "%$keyword%")->select('id'));
                if ($admin) {
                    $q->orWhere('admin_remark', 'like', "%$keyword%")
                        ->orWhereIn('user_id', User::where('username', 'like', "%$keyword%")->select('id'));
                }
            });
        }

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }
        $statusSet = $request->input('statusSet', 'activating');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } elseif ($statusSet === 'activating') {
            $query->whereIn('status', ['unpaid', 'pending', 'active', 'cancelling']);
        } elseif ($statusSet === 'archived') {
            $query->whereIn('status', ['cancelled', 'revoked', 'expired']);
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }
        if ($request->filled('period')) {
            $query->where('period', (int) $request->input('period'));
        }
        if ($request->filled('eab_kid')) {
            // 左匹配走 eab_kid 索引
            $query->where('eab_kid', 'like', $request->input('eab_kid').'%');
        }

        $amount = $request->input('amount');
        if (is_array($amount)) {
            if (isset($amount[0]) && isset($amount[1])) {
                $query->whereBetween('amount', [$amount[0], $amount[1]]);
            } elseif (isset($amount[0])) {
                $query->where('amount', '>=', $amount[0]);
            } elseif (isset($amount[1])) {
                $query->where('amount', '<=', $amount[1]);
            }
        }

        if ($request->filled('created_at')) {
            $range = (array) $request->input('created_at');
            if (count($range) === 2) {
                $query->whereBetween('created_at', $range);
            }
        }

        if ($request->filled('period_till')) {
            $range = (array) $request->input('period_till');
            if (count($range) === 2) {
                $query->whereBetween('period_till', $range);
            }
        }

        if ($admin) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }
            if ($request->filled('username')) {
                $matchedUserId = User::where('username', $request->input('username'))->value('id');
                $query->where('user_id', $matchedUserId ?? 0);
            }
        }

        if ($request->filled('product_name')) {
            $query->whereIn(
                'product_id',
                Product::where('name', 'like', '%'.$request->input('product_name').'%')->select('id')
            );
        }
    }
}

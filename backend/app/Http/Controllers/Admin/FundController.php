<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Fund\GetIdsRequest;
use App\Http\Requests\Fund\IndexRequest;
use App\Http\Requests\Fund\StoreRequest;
use App\Http\Requests\Fund\UpdateRequest;
use App\Http\Traits\PaymentConfigTrait;
use App\Models\Fund;
use Illuminate\Support\Facades\DB;
use Throwable;
use Yansongda\Pay\Pay;

/**
 * 资金管理
 */
class FundController extends BaseController
{
    use PaymentConfigTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取资金列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Fund::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('id', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('remark', 'like', "%{$validated['quickSearch']}%")
                    ->orWhereHas('user', function ($userQuery) use ($validated) {
                        $userQuery->where('username', 'like', "%{$validated['quickSearch']}%");
                    });
            });
        }
        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        }
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
        }
        if (! empty($validated['amount'])) {
            if (isset($validated['amount'][0]) && isset($validated['amount'][1])) {
                $query->whereBetween('amount', $validated['amount']);
            } elseif (isset($validated['amount'][0])) {
                $query->where('amount', '>=', $validated['amount'][0]);
            } elseif (isset($validated['amount'][1])) {
                $query->where('amount', '<=', $validated['amount'][1]);
            }
        }
        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (! empty($validated['pay_method'])) {
            $query->where('pay_method', $validated['pay_method']);
        }
        if (! empty($validated['pay_sn'])) {
            $query->where('pay_sn', $validated['pay_sn']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $total = $query->count();
        $items = $query->with([
            'user' => function ($query) {
                $query->select(['id', 'username']);
            },
        ])
            ->select([
                'id', 'user_id', 'amount', 'type', 'pay_method', 'pay_sn', 'status', 'remark', 'created_at',
            ])
            ->orderBy('id', 'desc')
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
     * 添加资金记录
     *
     * 并发安全：事务包裹 Fund::create —— status=1 会触发 createRecord 创建 Transaction，
     * 若后续 funds INSERT 失败，外层事务回滚保证 balance/transaction/fund 三者原子。
     */
    public function store(StoreRequest $request): void
    {
        $fund = DB::transaction(fn () => Fund::create($request->validated()));

        if (! $fund->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取资金记录
     */
    public function show(int $id): void
    {
        $fund = Fund::find($id);
        if (! $fund) {
            $this->error('资金记录不存在');
        }

        $this->success($fund->toArray());
    }

    /**
     * 批量获取资金记录
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $funds = Fund::whereIn('id', $ids)->get();
        if ($funds->isEmpty()) {
            $this->error('资金记录不存在');
        }

        $this->success($funds->toArray());
    }

    /**
     * 更新资金记录
     *
     * 并发安全：事务 + fund 行锁 —— 若 status 变更会触发 createRecord 创建 Transaction，
     * 外层事务保证 balance/transaction/fund 三者原子，防止后续 UPDATE funds 失败导致的脏余额。
     */
    public function update(UpdateRequest $request, int $id): void
    {
        DB::transaction(function () use ($request, $id) {
            $fund = Fund::where('id', $id)->lockForUpdate()->first();
            if (! $fund) {
                $this->error('资金记录不存在');
            }

            $fund->fill($request->validated());
            $fund->save();
        });

        $this->success();
    }

    /**
     * 删除资金记录
     */
    public function destroy(int $id): void
    {
        $fund = Fund::find($id);
        if (! $fund) {
            $this->error('资金记录不存在');
        }

        $fund->delete();
        $this->success();
    }

    /**
     * 批量删除资金记录
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $funds = Fund::whereIn('id', $ids)->get();
        if ($funds->isEmpty()) {
            $this->error('资金记录不存在');
        }

        Fund::destroy($ids);
        $this->success();
    }

    /**
     * 退款
     *
     * 并发安全：事务内持 fund 行级锁 + 锁内 status 二次校验，防止并发双退。
     * Fund::updating 钩子基于 getOriginal() 检查 status 转移，无法察觉并发已提交的 status 变更，
     * 必须由此处的悲观锁保证串行化。
     *
     * @throws Throwable
     */
    public function refunds(int $id): void
    {
        DB::transaction(function () use ($id) {
            $fund = Fund::where(['id' => $id, 'type' => 'addfunds'])->lockForUpdate()->first();
            $fund || $this->error('充值记录不存在');

            if ($fund->status === 0) {
                $this->error('资金处理中');
            }

            if ($fund->status === 2) {
                $this->error('资金已退');
            }

            $fund->type = 'refunds';
            $fund->status = 2;
            $fund->save();
        });

        $this->success();
    }

    /**
     * 退回
     *
     * 并发安全：同 refunds，fund 行级锁 + 锁内 status 校验。
     *
     * @throws Throwable
     */
    public function reverse(int $id): void
    {
        DB::transaction(function () use ($id) {
            $fund = Fund::where(['id' => $id, 'type' => 'deduct'])->lockForUpdate()->first();
            $fund || $this->error('扣款记录不存在');

            if ($fund->status === 0) {
                $this->error('扣款处理中');
            }

            if ($fund->status === 2) {
                $this->error('扣款已退');
            }

            $fund->type = 'reverse';
            $fund->status = 2;
            $fund->save();
        });

        $this->success();
    }

    /**
     * 检查充值状态
     *
     * @throws Throwable
     */
    public function check(int $id): void
    {
        $fund = Fund::where([
            'id' => $id,
            'type' => 'addfunds',
            'status' => 0, // processing
        ])->first();

        if (! $fund) {
            $this->error('invalid fund id');
        }

        if ($fund->pay_method === 'alipay') {
            $this->getPayConfig('alipay');
            $order = Pay::alipay()->query(['out_trade_no' => $fund->id]);
            if ($order['trade_status'] === 'TRADE_SUCCESS' || $order['trade_status'] === 'TRADE_FINISHED') {
                $pay_sn = $order['trade_no'];
            }
        }

        if ($fund->pay_method === 'wechat') {
            $this->getPayConfig('wechat');
            $order = Pay::wechat()->query(['out_trade_no' => $fund->id]);
            if ($order['trade_state'] === 'SUCCESS') {
                $pay_sn = $order['transaction_id'];
            }
        }

        // 如果支付序列号存在则充值成功
        if (isset($pay_sn)) {
            $this->addfundsSuccessful((string) $id, $pay_sn);
            $this->success();
        }

        $this->error('未查询到支付信息');
    }

    /**
     * 充值成功 使用事务 防止重复
     *
     * @throws Throwable
     */
    protected function addfundsSuccessful(string $id, int|string $pay_sn): void
    {
        DB::beginTransaction();
        try {
            $fund = Fund::where([
                'id' => $id,
                'type' => 'addfunds',
                'status' => 0, // processing
            ])->lockForUpdate()->first();

            if ($fund) {
                $fund->status = 1; // successful
                $fund->pay_sn = $pay_sn;
                $fund->save();
                DB::commit();
            }
        } catch (Throwable) {
            DB::rollback();
        }
    }
}
